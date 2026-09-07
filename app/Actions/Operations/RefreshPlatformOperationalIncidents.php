<?php

namespace App\Actions\Operations;

use App\Models\PlatformOperationalIncident;
use App\Models\PlatformOperationalIncidentEvent;
use App\Support\Operations\CollectPlatformOperationalSignals;
use Illuminate\Support\Facades\DB;

final class RefreshPlatformOperationalIncidents
{
    public function __construct(private readonly CollectPlatformOperationalSignals $signals) {}

    /** @return array{checked: int, open: int, opened: int, reopened: int, resolved: int} */
    public function handle(): array
    {
        return DB::transaction(function (): array {
            DB::selectOne("SELECT pg_advisory_xact_lock(hashtextextended('platform-operational-monitor', 0))");

            $summary = ['checked' => 0, 'open' => 0, 'opened' => 0, 'reopened' => 0, 'resolved' => 0];
            $observedAt = now();

            foreach ($this->signals->handle() as $signal) {
                $summary['checked']++;
                $incident = PlatformOperationalIncident::query()
                    ->where('code', $signal['code'])
                    ->lockForUpdate()
                    ->first();

                if ($signal['active']) {
                    $summary['open']++;
                    $eventType = null;
                    if ($incident === null) {
                        $incident = new PlatformOperationalIncident;
                        $incident->forceFill([
                            'code' => $signal['code'],
                            'severity' => $signal['severity'],
                            'status' => 'open',
                            'title' => $signal['title'],
                            'summary' => $signal['summary'],
                            'occurrence_count' => 1,
                            'first_detected_at' => $observedAt,
                            'last_detected_at' => $observedAt,
                            'resolved_at' => null,
                        ])->save();
                        $eventType = 'opened';
                        $summary['opened']++;
                    } else {
                        $eventType = $incident->status === 'resolved' ? 'reopened' : null;
                        $incident->forceFill([
                            'severity' => $signal['severity'],
                            'status' => 'open',
                            'title' => $signal['title'],
                            'summary' => $signal['summary'],
                            'occurrence_count' => $incident->occurrence_count + 1,
                            'last_detected_at' => $observedAt,
                            'resolved_at' => null,
                        ])->save();
                        if ($eventType !== null) {
                            $summary['reopened']++;
                        }
                    }

                    if ($eventType !== null) {
                        $this->recordEvent($incident, $eventType, $signal['severity'], $signal['summary'], $observedAt);
                    }

                    continue;
                }

                if ($incident !== null && $incident->status === 'open') {
                    $incident->forceFill([
                        'status' => 'resolved',
                        'resolved_at' => $observedAt,
                        'updated_at' => $observedAt,
                    ])->save();
                    $this->recordEvent($incident, 'resolved', $incident->severity, 'Le contrôle est revenu à un état normal.', $observedAt);
                    $summary['resolved']++;
                }
            }

            DB::table('operational_heartbeats')->upsert([
                [
                    'component' => (string) config('operations.monitoring.heartbeat_component'),
                    'last_succeeded_at' => $observedAt,
                ],
            ], ['component'], ['last_succeeded_at']);

            return $summary;
        });
    }

    private function recordEvent(
        PlatformOperationalIncident $incident,
        string $eventType,
        string $severity,
        string $summary,
        mixed $observedAt,
    ): void {
        $event = new PlatformOperationalIncidentEvent;
        $event->forceFill([
            'platform_operational_incident_id' => $incident->getKey(),
            'event_type' => $eventType,
            'severity' => $severity,
            'summary' => $summary,
            'observed_at' => $observedAt,
        ])->save();
    }
}
