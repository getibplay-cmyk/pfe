<?php

namespace App\Support\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CollectPlatformOperationalSignals
{
    /**
     * @return list<array{code: string, severity: string, title: string, summary: string, active: bool}>
     */
    public function handle(): array
    {
        return [
            $this->schedulerSignal(),
            $this->queueBacklogSignal(),
            $this->staleQueueSignal(),
            $this->failedJobsSignal(),
            $this->cmiCallbackSignal(),
            $this->cmiReconciliationSignal(),
            $this->diskSignal(),
            ...(config('platform_billing.renewals_enabled') ? [$this->billingSignal()] : []),
        ];
    }

    private function billingSignal(): array
    {
        $at = DB::table('operational_heartbeats')->where('component', 'saas-billing')->value('last_succeeded_at');
        $stale = $at === null || CarbonImmutable::parse($at)->lessThan(now()->subHours(2));

        return $this->signal('billing.stale', 'critical', 'Facturation sans exécution récente',
            'Vérifiez le traitement horaire saas:process-billing et son dernier échec éventuel.', $stale);
    }

    /** @return array{code: string, severity: string, title: string, summary: string, active: bool} */
    private function schedulerSignal(): array
    {
        $maxAge = (int) config('operations.scheduler.heartbeat_max_age_minutes');
        $recordedAt = DB::table('operational_heartbeats')
            ->where('component', config('operations.scheduler.heartbeat_component'))
            ->value('last_succeeded_at');
        $age = $recordedAt === null
            ? null
            : max(0, (int) floor(CarbonImmutable::parse((string) $recordedAt)->diffInMinutes(now(), false)));
        $active = $age === null || $age > $maxAge;

        return $this->signal(
            'scheduler.stale',
            'critical',
            'Scheduler sans heartbeat récent',
            $age === null
                ? "Aucun heartbeat du scheduler n’est enregistré (seuil : {$maxAge} min)."
                : "Le dernier heartbeat du scheduler date de {$age} min (seuil : {$maxAge} min).",
            $active,
        );
    }

    /** @return array{code: string, severity: string, title: string, summary: string, active: bool} */
    private function queueBacklogSignal(): array
    {
        $count = DB::table('jobs')->count();
        $maximum = (int) config('operations.monitoring.queue_backlog_max');

        return $this->signal(
            'queue.backlog',
            'warning',
            'File de traitements chargée',
            "{$count} traitement(s) attendent dans la file (seuil : {$maximum}).",
            $count > $maximum,
        );
    }

    /** @return array{code: string, severity: string, title: string, summary: string, active: bool} */
    private function staleQueueSignal(): array
    {
        $oldestAvailableAt = DB::table('jobs')->min('available_at');
        $age = $oldestAvailableAt === null
            ? 0
            : max(0, (int) floor(CarbonImmutable::createFromTimestampUTC((int) $oldestAvailableAt)->diffInMinutes(now(), false)));
        $maximum = (int) config('operations.monitoring.queue_stale_minutes');

        return $this->signal(
            'queue.stale',
            'critical',
            'Traitement en attente depuis trop longtemps',
            "Le plus ancien traitement disponible attend depuis {$age} min (seuil : {$maximum} min).",
            $oldestAvailableAt !== null && $age > $maximum,
        );
    }

    /** @return array{code: string, severity: string, title: string, summary: string, active: bool} */
    private function failedJobsSignal(): array
    {
        $count = DB::table('failed_jobs')->count();
        $maximum = (int) config('operations.monitoring.failed_jobs_max');

        return $this->signal(
            'queue.failed',
            'critical',
            'Traitements en échec',
            "{$count} traitement(s) en échec sont enregistrés (seuil : {$maximum}).",
            $count > $maximum,
        );
    }

    /** @return array{code: string, severity: string, title: string, summary: string, active: bool} */
    private function cmiCallbackSignal(): array
    {
        $lookback = (int) config('operations.monitoring.cmi_failure_lookback_minutes');
        $count = DB::table('saas_payment_gateway_events')
            ->whereIn('processing_result', ['declined', 'rejected'])
            ->where('received_at', '>=', now()->subMinutes($lookback))
            ->count();
        $maximum = (int) config('operations.monitoring.cmi_failures_max');

        return $this->signal(
            'cmi.callback_failures',
            'warning',
            'Callbacks CMI refusés ou rejetés',
            "{$count} callback(s) CMI ont échoué sur {$lookback} min (seuil : {$maximum}).",
            $count > $maximum,
        );
    }

    /** @return array{code: string, severity: string, title: string, summary: string, active: bool} */
    private function cmiReconciliationSignal(): array
    {
        $count = DB::table('saas_payment_attempts as attempts')
            ->where('attempts.status', 'paid')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('saas_payments as payments')
                    ->whereColumn('payments.tenant_id', 'attempts.tenant_id')
                    ->whereColumn('payments.saas_subscription_id', 'attempts.saas_subscription_id')
                    ->where('payments.entry_type', 'payment')
                    ->where('payments.payment_method', 'cmi')
                    ->whereRaw("payments.idempotency_key = 'cmi:' || attempts.id::text");
            })
            ->count();

        return $this->signal(
            'cmi.unreconciled',
            'critical',
            'Paiements CMI non rapprochés',
            "{$count} tentative(s) CMI payée(s) ne possèdent pas d’écriture financière correspondante.",
            $count > 0,
        );
    }

    /** @return array{code: string, severity: string, title: string, summary: string, active: bool} */
    private function diskSignal(): array
    {
        $minimum = (int) config('operations.monitoring.disk_free_min_percent');

        try {
            $path = (string) config('operations.monitoring.disk_path');
            $total = disk_total_space($path);
            $free = disk_free_space($path);
            if ($total === false || $free === false || $total <= 0) {
                throw new \RuntimeException('Disk metrics unavailable.');
            }
            $percent = (int) floor(($free / $total) * 100);

            return $this->signal(
                'storage.low_space',
                'critical',
                'Espace de stockage faible',
                "{$percent} % d’espace reste disponible (minimum : {$minimum} %).",
                $percent < $minimum,
            );
        } catch (Throwable) {
            return $this->signal(
                'storage.low_space',
                'warning',
                'Espace de stockage non vérifiable',
                'La mesure de l’espace de stockage est indisponible.',
                true,
            );
        }
    }

    /** @return array{code: string, severity: string, title: string, summary: string, active: bool} */
    private function signal(
        string $code,
        string $severity,
        string $title,
        string $summary,
        bool $active,
    ): array {
        return compact('code', 'severity', 'title', 'summary', 'active');
    }
}
