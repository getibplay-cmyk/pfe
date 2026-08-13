<?php

namespace App\Support\Intelligence\J15;

use JsonException;

final class J15CanonicalJson
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    public function encode(array $payload): string
    {
        return json_encode(
            $this->sort($payload),
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION,
        )."\n";
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
