<?php

namespace App\Support\Contracts;

class CanonicalJson
{
    public function encode(array $value): string
    {
        return json_encode($this->normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public function hash(array $value): string
    {
        return hash('sha256', $this->encode($value));
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($item) => $this->normalize($item), $value);
    }
}
