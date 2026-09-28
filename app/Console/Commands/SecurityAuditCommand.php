<?php

namespace App\Console\Commands;

use App\Support\Security\SecurityReadiness;
use Illuminate\Console\Command;

final class SecurityAuditCommand extends Command
{
    protected $signature = 'rentfleet:security-audit {--production : Check production requirements} {--database : Include read-only PostgreSQL role inspection} {--json : Machine-readable output}';

    protected $description = 'Inspect effective security settings without changing configuration or exposing secrets';

    public function handle(SecurityReadiness $readiness): int
    {
        $report = $readiness->inspect((bool) $this->option('production'), (bool) $this->option('database'));
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Control', 'Result', 'Action'], array_map(fn ($row) => array_values($row), $report['checks']));
            $this->line('Configuration inspection only; infrastructure and operational evidence remain separate.');
        }

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
