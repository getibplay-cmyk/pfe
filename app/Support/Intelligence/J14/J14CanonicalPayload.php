<?php

namespace App\Support\Intelligence\J14;

use JsonException;

final class J14CanonicalPayload
{
    /** @param array<string, mixed> $payload */
    public function digest(array $payload): string
    {
        if (isset($payload['idempotency']) && is_array($payload['idempotency'])) {
            unset($payload['idempotency']['canonical_payload_sha256']);
        }

        return hash('sha256', $this->encode($payload, false));
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    public function encode(array $payload, bool $trailingNewline = true): string
    {
        $json = json_encode(
            $this->sort($payload),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
        );

        return $json.($trailingNewline ? "\n" : '');
    }

    private function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->sort(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->sort($item);
        }

        return $value;
    }
}
