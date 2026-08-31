<?php

namespace Tests\Unit;

use App\Support\Reporting\BelkhirSpaceReportPresenter;
use LogicException;
use PHPUnit\Framework\TestCase;

class BelkhirSpaceReportPresenterTest extends TestCase
{
    public function test_it_builds_integer_chart_series_and_real_availability_without_mutating_the_report(): void
    {
        $report = [
            'meta' => ['date_from' => '2026-08-01', 'date_to' => '2026-08-31'],
            'operational' => [
                'reservations' => ['created' => 9, 'confirmed' => 7, 'cancelled' => 1, 'expired' => 1],
                'contracts' => ['active' => 5, 'expected_returns' => 4, 'overdue_returns' => 1, 'closed' => 3],
                'fleet' => ['total' => 14, 'available' => 6, 'rented' => 4, 'blocked' => 1, 'maintenance' => 1, 'snapshot_at' => '2026-08-31T12:00:00+01:00'],
            ],
        ];
        $before = $report;

        $presented = (new BelkhirSpaceReportPresenter)->present($report);

        $this->assertSame($before, $report);
        $this->assertSame(['from' => '2026-08-01', 'to' => '2026-08-31'], $presented['period']);
        $this->assertSame([9, 7, 1, 1], $presented['charts']['reservations']['values']);
        $this->assertSame([5, 4, 1, 3], $presented['charts']['contracts']['values']);
        $this->assertSame([6, 4, 1, 1, 2], $presented['charts']['fleet']['values']);
        $this->assertSame(['available' => 6, 'total' => 14], $presented['availability']);
    }

    public function test_it_rejects_unvalidated_or_negative_counts_instead_of_casting_them(): void
    {
        $report = [
            'meta' => ['date_from' => '2026-08-01', 'date_to' => '2026-08-31'],
            'operational' => [
                'reservations' => ['created' => '9', 'confirmed' => 7, 'cancelled' => 1, 'expired' => 1],
                'contracts' => ['active' => 5, 'expected_returns' => 4, 'overdue_returns' => 1, 'closed' => 3],
                'fleet' => ['total' => 14, 'available' => 6, 'rented' => 4, 'blocked' => 1, 'maintenance' => 1],
            ],
        ];

        $this->expectException(LogicException::class);
        (new BelkhirSpaceReportPresenter)->present($report);
    }
}
