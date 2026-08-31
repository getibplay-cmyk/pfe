<?php

namespace App\Support\Reporting;

use LogicException;

final class BelkhirSpaceReportPresenter
{
    /**
     * Prépare uniquement des séries de présentation à partir du rapport canonique déjà tenant-scopé.
     *
     * @return array{
     *     period: array{from: string, to: string},
     *     charts: array<string, array{labels: list<string>, values: list<int>, total: int}>,
     *     availability: array{available: int, total: int}
     * }
     */
    public function present(array $report): array
    {
        $reservations = $report['operational']['reservations'];
        $contracts = $report['operational']['contracts'];
        $fleet = $report['operational']['fleet'];
        $fleetTotal = $this->validatedCount($fleet['total'], 'fleet.total');
        $fleetAvailable = $this->validatedCount($fleet['available'], 'fleet.available');
        $fleetRented = $this->validatedCount($fleet['rented'], 'fleet.rented');
        $fleetBlocked = $this->validatedCount($fleet['blocked'], 'fleet.blocked');
        $fleetMaintenance = $this->validatedCount($fleet['maintenance'], 'fleet.maintenance');
        $classifiedFleet = $fleetAvailable + $fleetRented + $fleetBlocked + $fleetMaintenance;
        $otherFleetStates = max(0, $fleetTotal - $classifiedFleet);

        $charts = [
            'reservations' => $this->series(
                ['Créées', 'Confirmées', 'Annulées', 'Expirées'],
                [$reservations['created'], $reservations['confirmed'], $reservations['cancelled'], $reservations['expired']],
            ),
            'contracts' => $this->series(
                ['Actifs', 'Retours attendus', 'Retours en retard', 'Clôturés'],
                [$contracts['active'], $contracts['expected_returns'], $contracts['overdue_returns'], $contracts['closed']],
            ),
            'fleet' => $this->series(
                ['Disponibles', 'Loués', 'Réservés ou bloqués', 'Maintenance', 'Autres états'],
                [$fleetAvailable, $fleetRented, $fleetBlocked, $fleetMaintenance, $otherFleetStates],
            ),
        ];

        return [
            'period' => [
                'from' => (string) $report['meta']['date_from'],
                'to' => (string) $report['meta']['date_to'],
            ],
            'charts' => $charts,
            'availability' => [
                'available' => $fleetAvailable,
                'total' => $fleetTotal,
            ],
        ];
    }

    /** @return array{labels: list<string>, values: list<int>, total: int} */
    private function series(array $labels, array $values): array
    {
        $integers = [];
        foreach ($values as $index => $value) {
            $integers[] = $this->validatedCount($value, 'series.'.(string) $index);
        }

        return [
            'labels' => array_values($labels),
            'values' => $integers,
            'total' => array_sum($integers),
        ];
    }

    private function validatedCount(mixed $value, string $field): int
    {
        if (! is_int($value) || $value < 0) {
            throw new LogicException("Invalid canonical report count: {$field}.");
        }

        return $value;
    }
}
