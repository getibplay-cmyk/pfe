<?php

namespace App\Support\Intelligence;

use RuntimeException;

final class IntelligencePseudonymizer
{
    public function configured(): bool
    {
        return strlen($this->key()) >= 32;
    }

    public function tenantKey(int $tenantId): string
    {
        return $this->digest('t_', "tenant|v1|{$tenantId}");
    }

    public function agencyKey(int $tenantId, int $agencyId): string
    {
        return $this->digest('a_', "agency|v1|{$tenantId}|{$agencyId}");
    }

    public function contractKey(int $tenantId, int $contractId): string
    {
        return $this->digest('c_', "contract|v1|{$tenantId}|{$contractId}");
    }

    public function rowId(int $tenantId, int $contractId, string $eventAtUtc): string
    {
        return $this->digest('r_', "row|v1|{$tenantId}|{$contractId}|{$eventAtUtc}");
    }

    private function digest(string $prefix, string $message): string
    {
        $key = $this->key();
        if (strlen($key) < 32) {
            throw new RuntimeException('La configuration Intelligence requise est indisponible.');
        }

        return $prefix.hash_hmac('sha256', $message, $key);
    }

    private function key(): string
    {
        return (string) config('intelligence.export_hmac_key', '');
    }
}
