<?php

namespace App\Support\Notifications;

use App\Enums\TenantStatus;
use App\Models\InsurancePolicy;
use App\Models\InternalNotification;
use App\Models\Invoice;
use App\Models\MaintenanceOrder;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Pricing\DecimalMoney;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GenerateOperationalNotifications
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array{tenants: int, created: int, updated: int, resolved: int, reactivated: int} */
    public function handle(): array
    {
        $tenants = Tenant::query()
            ->where('status', TenantStatus::Active->value)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();
        $totals = ['created' => 0, 'updated' => 0, 'resolved' => 0, 'reactivated' => 0];

        foreach ($tenants as $tenant) {
            $result = $this->context->run($tenant, fn (): array => $this->forCurrentTenant());

            foreach ($totals as $key => $value) {
                $totals[$key] += $result[$key];
            }
        }

        return ['tenants' => $tenants->count(), ...$totals];
    }

    /** @return array{created: int, updated: int, resolved: int, reactivated: int} */
    private function forCurrentTenant(): array
    {
        return DB::transaction(function (): array {
            DB::select('SELECT pg_advisory_xact_lock(20260730, ?)', [$this->context->tenantId()]);

            $incidents = $this->collectIncidents();
            $desired = $incidents
                ->filter(fn (array $incident): bool => $this->recipientIds($incident)->isNotEmpty())
                ->keyBy('incident_key');
            $existing = InternalNotification::query()
                ->lockForUpdate()
                ->get()
                ->keyBy('deduplication_key');
            $result = ['created' => 0, 'updated' => 0, 'resolved' => 0, 'reactivated' => 0];

            foreach ($desired as $incidentKey => $incident) {
                $notification = $existing->get($incidentKey);
                $recipients = $this->recipientIds($incident);
                $wasResolved = false;

                if (! $notification) {
                    $notification = InternalNotification::query()->create([
                        'agency_id' => $incident['agency_id'],
                        'category' => $incident['category'],
                        'priority' => $incident['priority'],
                        'title' => $incident['title'],
                        'summary' => $incident['summary'],
                        'resource_type' => $incident['resource_type'],
                        'resource_id' => $incident['resource_id'],
                        'required_permission' => $incident['required_permission'],
                        'deduplication_key' => $incidentKey,
                        'occurred_at' => $incident['occurred_at'],
                        'due_at' => $incident['due_at'],
                        'last_detected_at' => now(),
                        'occurrence_count' => 1,
                    ]);
                    $result['created']++;
                    $this->audit->record('notification.generated', $notification, [], $this->auditMetadata($notification, $recipients->count()));
                } else {
                    $wasResolved = $notification->resolved_at !== null;
                    $before = $notification->only([
                        'category', 'priority', 'title', 'summary', 'required_permission', 'due_at',
                    ]);
                    $notification->forceFill([
                        'agency_id' => $incident['agency_id'],
                        'category' => $incident['category'],
                        'priority' => $incident['priority'],
                        'title' => $incident['title'],
                        'summary' => $incident['summary'],
                        'resource_type' => $incident['resource_type'],
                        'resource_id' => $incident['resource_id'],
                        'required_permission' => $incident['required_permission'],
                        'due_at' => $incident['due_at'],
                        'last_detected_at' => now(),
                        'resolved_at' => null,
                        'resolution_reason' => null,
                        'occurrence_count' => $wasResolved
                            ? $notification->occurrence_count + 1
                            : $notification->occurrence_count,
                    ])->save();

                    if ($wasResolved) {
                        $result['reactivated']++;
                        $this->audit->record('notification.reactivated', $notification, [
                            'resolved' => true,
                        ], $this->auditMetadata($notification, $recipients->count()));
                    } elseif ($this->presentationChanged($before, $notification)) {
                        $result['updated']++;
                        $this->audit->record('notification.updated', $notification, $before, $this->auditMetadata($notification, $recipients->count()));
                    }
                }

                $this->syncRecipients($notification, $recipients, $wasResolved);
            }

            $existing
                ->filter(fn (InternalNotification $notification, string $key): bool => ! $desired->has($key) && $notification->resolved_at === null)
                ->each(function (InternalNotification $notification) use (&$result): void {
                    $notification->forceFill([
                        'resolved_at' => now(),
                        'resolution_reason' => 'cause_disparue',
                    ])->save();
                    $result['resolved']++;
                    $this->audit->record('notification.resolved', $notification, [
                        'resolved' => false,
                    ], [
                        'resolved' => true,
                        'reason' => 'cause_disparue',
                    ]);
                });

            return $result;
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function collectIncidents(): Collection
    {
        $incidents = collect();
        $now = now();
        $soon = $now->copy()->addDays(30);

        Reservation::query()->where('status', 'pending')->orderBy('id')->each(function (Reservation $reservation) use ($incidents, $now): void {
            $expiring = $reservation->expires_at?->lte($now->copy()->addDay()) ?? false;
            $incidents->push($this->incident(
                $reservation,
                'reservation',
                $expiring ? 'urgent' : 'information',
                $expiring ? 'Réservation bientôt expirée' : 'Réservation à confirmer',
                $expiring ? 'Une réservation attend une confirmation avant son échéance proche.' : 'Une réservation attend une confirmation par une personne autorisée.',
                'reservation.confirm',
                'reservation:'.$reservation->id.':pending',
                $reservation->updated_at,
                $reservation->expires_at,
            ));
        });

        Reservation::query()
            ->whereIn('status', ['cancelled', 'expired'])
            ->where('updated_at', '>=', $now->copy()->subDays(7))
            ->orderBy('id')
            ->each(function (Reservation $reservation) use ($incidents): void {
                $status = $reservation->status->value;
                $incidents->push($this->incident(
                    $reservation,
                    'reservation',
                    'information',
                    $status === 'expired' ? 'Réservation expirée' : 'Réservation annulée',
                    'Le statut d’une réservation a changé et son historique peut être consulté.',
                    'reservation.view',
                    'reservation:'.$reservation->id.':terminal',
                    $reservation->updated_at,
                ));
            });

        RentalContract::query()->whereIn('status', ['ready', 'accepted', 'return_pending'])->orderBy('id')->each(function (RentalContract $contract) use ($incidents): void {
            [$permission, $title, $summary] = match ($contract->status->value) {
                'ready' => ['contract.accept', 'Contrat prêt à accepter', 'Un contrat attend une acceptation tracée.'],
                'accepted' => ['contract.activate', 'Départ à préparer', 'Un contrat accepté attend ses prérequis de départ.'],
                default => ['contract.return', 'Retour à finaliser', 'Un contrat attend la finalisation humaine du retour.'],
            };
            $incidents->push($this->incident($contract, 'contract', 'warning', $title, $summary, $permission, 'contract:'.$contract->id.':next-action', $contract->updated_at));
        });

        RentalContract::query()
            ->whereIn('status', ['active', 'return_pending'])
            ->where('expected_return_at', '<', $now)
            ->orderBy('id')
            ->each(fn (RentalContract $contract) => $incidents->push($this->incident(
                $contract,
                'contract',
                'urgent',
                'Retour de véhicule en retard',
                'La date de retour attendue est dépassée et nécessite une revue.',
                'contract.return',
                'contract:'.$contract->id.':overdue-return',
                $contract->updated_at,
                $contract->expected_return_at,
            )));

        InsurancePolicy::query()
            ->whereIn('status', ['active', 'expired'])
            ->whereDate('ends_at', '<=', $soon)
            ->whereDoesntHave('renewals', fn ($query) => $query->where('status', 'active'))
            ->orderBy('id')
            ->each(function (InsurancePolicy $policy) use ($incidents, $now): void {
                $expired = $policy->status->value === 'expired' || $policy->ends_at->lt($now);
                $incidents->push($this->incident(
                    $policy,
                    'insurance',
                    $expired ? 'urgent' : 'warning',
                    $expired ? 'Police d’assurance expirée' : 'Police d’assurance bientôt échue',
                    'Une échéance d’assurance nécessite une vérification administrative.',
                    'insurance.view',
                    'insurance-policy:'.$policy->id.':expiry',
                    $policy->updated_at,
                    $policy->ends_at,
                ));
            });

        MaintenanceOrder::query()
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->where(function ($query) use ($soon): void {
                $query->where('scheduled_start_at', '<=', $soon)
                    ->orWhereDate('next_due_date', '<=', $soon);
            })
            ->orderBy('id')
            ->each(function (MaintenanceOrder $maintenance) use ($incidents, $now): void {
                $dueAt = $maintenance->scheduled_start_at ?? $maintenance->next_due_date;
                $overdue = $dueAt?->lt($now) ?? false;
                $incidents->push($this->incident(
                    $maintenance,
                    'maintenance',
                    $overdue ? 'urgent' : 'warning',
                    $overdue ? 'Maintenance en retard' : 'Maintenance planifiée',
                    'Une intervention de maintenance nécessite un suivi opérationnel.',
                    'maintenance.view',
                    'maintenance:'.$maintenance->id.':due',
                    $maintenance->updated_at,
                    $dueAt,
                ));
            });

        Invoice::query()
            ->whereIn('status', ['issued', 'partially_paid'])
            ->where('balance_due', '>', 0)
            ->orderBy('id')
            ->each(function (Invoice $invoice) use ($incidents, $now): void {
                $overdue = $invoice->due_at?->lt($now) ?? false;
                $incidents->push($this->incident(
                    $invoice,
                    'finance',
                    $overdue ? 'urgent' : 'warning',
                    $overdue ? 'Facture échue' : 'Facture impayée',
                    'Une facture présente encore un solde à traiter dans sa devise.',
                    'invoice.view',
                    'invoice:'.$invoice->id.':outstanding',
                    $invoice->updated_at,
                    $invoice->due_at,
                ));
            });

        RentalContract::query()->whereIn('status', ['accepted', 'returned'])->orderBy('id')->each(function (RentalContract $contract) use ($incidents): void {
            $required = DecimalMoney::toMinorUnits($contract->deposit_required);
            $received = DecimalMoney::toMinorUnits($contract->deposit_received);
            $settled = DecimalMoney::toMinorUnits($contract->deposit_retained) + DecimalMoney::toMinorUnits($contract->deposit_refunded);

            if ($contract->status->value === 'accepted' && $required > $received) {
                $incidents->push($this->incident(
                    $contract,
                    'finance',
                    'warning',
                    'Caution à encaisser',
                    'Une caution contractuelle reste à encaisser avant le départ.',
                    'deposit.create',
                    'contract:'.$contract->id.':deposit-receive',
                    $contract->updated_at,
                ));
            }

            if ($contract->status->value === 'returned' && $received > $settled) {
                $incidents->push($this->incident(
                    $contract,
                    'finance',
                    'urgent',
                    'Caution à régulariser',
                    'Une caution doit être restituée ou retenue par une décision explicite.',
                    'deposit.create',
                    'contract:'.$contract->id.':deposit-settle',
                    $contract->updated_at,
                ));
            }
        });

        return $incidents;
    }

    /** @return array<string, mixed> */
    private function incident(
        Model $resource,
        string $category,
        string $priority,
        string $title,
        string $summary,
        string $permission,
        string $incidentKey,
        CarbonInterface $occurredAt,
        ?CarbonInterface $dueAt = null,
    ): array {
        return [
            'agency_id' => (int) $resource->getAttribute('agency_id'),
            'category' => $category,
            'priority' => $priority,
            'title' => $title,
            'summary' => $summary,
            'resource_type' => $resource->getMorphClass(),
            'resource_id' => $resource->getKey(),
            'required_permission' => $permission,
            'incident_key' => $incidentKey,
            'occurred_at' => $occurredAt,
            'due_at' => $dueAt,
        ];
    }

    /** @param  array<string, mixed>  $incident */
    private function recipientIds(array $incident): Collection
    {
        return User::query()
            ->where('tenant_id', $this->context->tenantId())
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->whereNull('agency_id')
                ->orWhere('agency_id', $incident['agency_id']))
            ->whereHas('role', fn ($role) => $role
                ->where('is_active', true)
                ->whereHas('permissions', fn ($permissions) => $permissions->where('slug', $incident['required_permission'])))
            ->pluck('id');
    }

    private function syncRecipients(InternalNotification $notification, Collection $recipients, bool $reactivated): void
    {
        DB::table('internal_notification_recipients')
            ->where('tenant_id', $notification->tenant_id)
            ->where('internal_notification_id', $notification->id)
            ->whereNotIn('user_id', $recipients)
            ->delete();

        foreach ($recipients as $userId) {
            DB::table('internal_notification_recipients')->insertOrIgnore([
                'tenant_id' => $notification->tenant_id,
                'internal_notification_id' => $notification->id,
                'user_id' => $userId,
                'created_at' => now(),
            ]);
        }

        if ($reactivated) {
            DB::table('internal_notification_recipients')
                ->where('tenant_id', $notification->tenant_id)
                ->where('internal_notification_id', $notification->id)
                ->whereIn('user_id', $recipients)
                ->update(['read_at' => null]);
        }
    }

    /** @param  array<string, mixed>  $before */
    private function presentationChanged(array $before, InternalNotification $notification): bool
    {
        $after = $notification->only(array_keys($before));

        return collect($before)->contains(
            fn (mixed $value, string $key): bool => (string) $value !== (string) $after[$key]
        );
    }

    /** @return array<string, mixed> */
    private function auditMetadata(InternalNotification $notification, int $recipientCount): array
    {
        return [
            'category' => $notification->category,
            'priority' => $notification->priority,
            'recipient_count' => $recipientCount,
            'occurrence_count' => $notification->occurrence_count,
        ];
    }
}
