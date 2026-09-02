<?php

namespace App\Support\PlatformBilling\Cmi;

class CmiSignature
{
    /** @param array<string, scalar|null> $parameters */
    public function sign(array $parameters, string $storeKey): string
    {
        $keys = array_keys($parameters);
        natcasesort($keys);

        $plain = '';
        foreach ($keys as $key) {
            if (in_array(strtolower($key), ['hash', 'encoding'], true)) {
                continue;
            }

            $plain .= $this->escape((string) ($parameters[$key] ?? '')).'|';
        }
        $plain .= $this->escape($storeKey);

        return base64_encode(hash('sha512', $plain, true));
    }

    /** @param array<string, scalar|null> $parameters */
    public function verify(array $parameters, string $storeKey): bool
    {
        $provided = $this->value($parameters, 'HASH');
        if ($provided === null || $provided === '') {
            return false;
        }

        return hash_equals($this->sign($parameters, $storeKey), $provided);
    }

    /** @param array<string, scalar|null> $parameters */
    private function value(array $parameters, string $wanted): ?string
    {
        foreach ($parameters as $key => $value) {
            if (strcasecmp($key, $wanted) === 0) {
                return (string) ($value ?? '');
            }
        }

        return null;
    }

    private function escape(string $value): string
    {
        return str_replace('|', '\\|', str_replace('\\', '\\\\', $value));
    }
}
