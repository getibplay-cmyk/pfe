<?php

namespace App\Support\Intelligence\J11;

final class J11CanonicalPayload
{
    /** @param array<string, mixed> $record */
    public function digest(array $record): string
    {
        $payload = [];

        foreach ([
            'schema_version',
            'contract_id',
            'module_id',
            'record_id',
            'created_at',
            'research_status',
            'scope',
            'advisory',
            'human_decision',
        ] as $key) {
            $payload[$key] = $record[$key] ?? null;
        }

        return hash('sha256', $this->stringify($payload));
    }

    private function stringify(mixed $value): string
    {
        if (! is_array($value)) {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        }

        if (array_is_list($value)) {
            return '['.implode(',', array_map($this->stringify(...), $value)).']';
        }

        ksort($value, SORT_STRING);

        $items = [];
        foreach ($value as $key => $child) {
            $encodedKey = json_encode(
                (string) $key,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $items[] = $encodedKey.':'.$this->stringify($child);
        }

        return '{'.implode(',', $items).'}';
    }
}
