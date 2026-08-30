<?php

namespace App\Support\Intelligence\FleetReallocation;

use Illuminate\Support\Facades\Process;
use Throwable;

class FleetReallocationRuntimeReadiness
{
    public function ready(): bool
    {
        try {
            $python = (string) config('intelligence.fleet_reallocation.python_binary');
            $script = (string) config('intelligence.fleet_reallocation.runtime_script');
            if (! (bool) config('intelligence.fleet_reallocation.runtime_enabled')
                || $python === ''
                || $script === ''
                || ! is_file($python)
                || ! is_file($script)) {
                return false;
            }

            $result = Process::timeout(5)->run([
                $python,
                '-c',
                'import importlib.metadata,sys; print("READY" if sys.version_info[:2] == (3,12) and importlib.metadata.version("ortools") == "9.15.6755" else "UNAVAILABLE")',
            ]);

            return $result->successful() && trim($result->output()) === 'READY';
        } catch (Throwable) {
            return false;
        }
    }
}
