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
use Illuminate\Support\Facades\DB;

class GenerateOperationalNotifications
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array{tenants: int, created: int} */
    public function handle(): array
    {
        $tenants = Tenant::query()->where('status', TenantStatus::Active->value)->whereNull('deleted_at')->orderBy('id')->get();
        $created = 0;

        foreach ($tenants as $tenant) {
            $created += $this->context->run($tenant, fn (): int => $this->forCurrentTenant());
        }

        return ['tenants' => $tenants->count(), 'created' => $created];
    }

    private function forCurrentTenant(): int
    {
        $created = 0;
        $now = now();
        $soon = $now->copy()->addDays(30);

        Reservation::query()->where('status', 'pending')->orderBy('id')->each(function (Reservation $reservation) use (&$created, $now): void {
            $expiring = $reservation->expires_at?->lte($now->copy()->addDay()) ?? false;
            $created += $this->createFor(
                $reservation,
                'reservation',
                $expiring ? 'urgent' : 'information',
                $expiring ? 'Réservation bientôt expirée' : 'Réservation à confirmer',
                $expiring ? 'Une réservation attend une confirmation avant son échéance proche.' : 'Une réservation attend une confirmation par une personne autorisée.',
                'reservation.confirm',
                'reservation:'.$reservation->id.':pending',
                $reservation->expires_at ?? $reservation->updated_at,
            );
        });

        Reservation::query()->whereIn('status', ['cancelled', 'expired'])->where('updated_at', '>=', $now->copy()->subDays(7))->orderBy('id')->each(function (Reservation $reservation) use (&$created): void {
            $status = $reservation->status->value;
            $created += $this->createFor(
                $reservation,
                'reservation',
                'information',
                $status === 'expired' ? 'Réservation expirée' : 'Réservation annulée',
                'Le statut d’une réservation a changé et son historique peut être consulté.',
                'reservation.view',
                'reservation:'.$reservation->id.':'.$status,
                $reservation->updated_at,
            );
        });

        RentalContract::query()->whereIn('status', ['ready', 'accepted', 'return_pending'])->orderBy('id')->each(function (RentalContract $contract) use (&$created): void {
            [$permission, $title, $summary] = match ($contract->status->value) {
                'ready' => ['contract.accept', 'Contrat prêt à accepter', 'Un contrat attend une acceptation tracée.'],
                'accepted' => ['contract.activate', 'Départ à préparer', 'Un contrat accepté attend ses prérequis de départ.'],
                default => ['contract.return', 'Retour à finaliser', 'Un contrat attend la finalisation humaine du retour.'],
            };
            $created += $this->createFor($contract, 'contract', 'warning', $title, $summary, $permission, 'contract:'.$contract->id.':'.$contract->status->value, $contract->updated_at);
        });

        RentalContract::query()->whereIn('status', ['active', 'return_pending'])->where('expected_return_at', '<', $now)->orderBy('id')->each(function (RentalContract $contract) use (&$created): void {
            $created += $this->createFor($contract, 'contract', 'urgent', 'Retour de véhicule en retard', 'La date de retour attendue est dépassée et nécessite une revue.', 'contract.return', 'contract:'.$contract->id.':overdue-return', $contract->expected_return_at);
        });

        InsurancePolicy::query()->whereIn('status', ['active', 'expired'])->whereDate('ends_at', '<=', $soon)->orderBy('id')->each(function (InsurancePolicy $policy) use (&$created, $now): void {
            $expired = $policy->status->value === 'expired' || $policy->ends_at->lt($now);
            $created += $this->createFor($policy, 'insurance', $expired ? 'urgent' : 'warning', $expired ? 'Police d’assurance expirée' : 'Police d’assurance bientôt échue', 'Une échéance d’assurance nécessite une vérification administrative.', 'insurance.view', 'insurance-policy:'.$policy->id.':'.$policy->ends_at->toDateString(), $policy->ends_at);
        });

        MaintenanceOrder::query()->whereNotIn('status', ['completed', 'cancelled'])->where(function ($query) use ($soon): void {
            $query->where('scheduled_start_at', '<=', $soon)->orWhereDate('next_due_date', '<=', $soon);
        })->orderBy('id')->each(function (MaintenanceOrder $maintenance) use (&$created, $now): void {
            $dueAt = $maintenance->scheduled_start_at ?? $maintenance->next_due_date;
            $overdue = $dueAt?->lt($now) ?? false;
            $created += $this->createFor($maintenance, 'maintenance', $overdue ? 'urgent' : 'warning', $overdue ? 'Maintenance en retard' : 'Maintenance planifiée', 'Une intervention de maintenance nécessite un suivi opérationnel.', 'maintenance.view', 'maintenance:'.$maintenance->id.':'.($dueAt?->format('YmdHi') ?? 'pending'), $dueAt ?? $maintenance->updated_at);
        });

        Invoice::query()->whereIn('status', ['issued', 'partially_paid'])->where('balance_due', '>', 0)->orderBy('id')->each(function (Invoice $invoice) use (&$created, $now): void {
            $overdue = $invoice->due_at?->lt($now) ?? false;
            $created += $this->createFor($invoice, 'finance', $overdue ? 'urgent' : 'warning', $overdue ? 'Facture échue' : 'Facture impayée', 'Une facture présente encore un solde à traiter dans sa devise.', 'invoice.view', 'invoice:'.$invoice->id.':outstanding', $invoice->due_at ?? $invoice->issued_at ?? $invoice->updated_at);
        });

        RentalContract::query()->whereIn('status', ['accepted', 'returned'])->orderBy('id')->each(function (RentalContract $contract) use (&$created): void {
            $required = DecimalMoney::toMinorUnits($contract->deposit_required);
            $received = DecimalMoney::toMinorUnits($contract->deposit_received);
            $settled = DecimalMoney::toMinorUnits($contract->deposit_retained) + DecimalMoney::toMinorUnits($contract->deposit_refunded);

            if ($contract->status->value === 'accepted' && $required > $received) {
                $created += $this->createFor($contract, 'finance', 'warning', 'Caution à encaisser', 'Une caution contractuelle reste à encaisser avant le départ.', 'deposit.create', 'contract:'.$contract->id.':deposit-receive', $contract->accepted_at ?? $contract->updated_at);
            }
            if ($contract->status->value === 'returned' && $received > $settled) {
                $created += $this->createFor($contract, 'finance', 'urgent', 'Caution à régulariser', 'Une caution doit être restituée ou retenue par une décision explicite.', 'deposit.create', 'contract:'.$contract->id.':deposit-settle', $contract->returned_at ?? $contract->updated_at);
            }
        });

        return $created;
    }

    private function createFor(Model $resource, string $category, string $priority, string $title, string $summary, string $permission, string $deduplicationKey, CarbonInterface $occurredAt): int
    {
        $agencyId = (int) $resource->getAttribute('agency_id');
        $recipients = User::query()
            ->where('tenant_id', $this->context->tenantId())
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('agency_id')->orWhere('agency_id', $agencyId))
            ->whereHas('role', fn ($role) => $role->where('is_active', true)->whereHas('permissions', fn ($permissions) => $permissions->where('slug', $permission)))
            ->pluck('id');

        if ($recipients->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($resource, $category, $priority, $title, $summary, $permission, $deduplicationKey, $occurredAt, $agencyId, $recipients): int {
            $notification = InternalNotification::query()->firstOrCreate(
                ['deduplication_key' => $deduplicationKey],
                [
                    'agency_id' => $agencyId,
                    'category' => $category,
                    'priority' => $priority,
                    'title' => $title,
                    'summary' => $summary,
                    'resource_type' => $resource->getMorphClass(),
                    'resource_id' => $resource->getKey(),
                    'required_permission' => $permission,
                    'occurred_at' => $occurredAt,
                ],
            );

            foreach ($recipients as $userId) {
                DB::table('internal_notification_recipients')->insertOrIgnore([
                    'tenant_id' => $this->context->tenantId(),
                    'internal_notification_id' => $notification->id,
                    'user_id' => $userId,
                    'created_at' => now(),
                ]);
            }

            if ($notification->wasRecentlyCreated) {
                $this->audit->record('notification.generated', $notification, [], [
                    'category' => $category,
                    'priority' => $priority,
                    'recipient_count' => $recipients->count(),
                ]);

                return 1;
            }

            return 0;
        });
    }
}
