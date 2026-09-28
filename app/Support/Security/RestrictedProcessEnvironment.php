<?php

namespace App\Support\Security;

use InvalidArgumentException;

final class RestrictedProcessEnvironment
{
    private const SYSTEM_NAMES = ['PATH', 'SystemRoot', 'SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP', 'TMPDIR', 'LANG', 'LC_ALL', 'LC_CTYPE', 'CUDA_VISIBLE_DEVICES', 'NVIDIA_VISIBLE_DEVICES'];

    private const RUNTIME_NAMES = ['PYTHONDONTWRITEBYTECODE', 'PYTHONHASHSEED', 'PYTHONNOUSERSITE', 'OMP_NUM_THREADS', 'OPENBLAS_NUM_THREADS', 'MKL_NUM_THREADS', 'NUMEXPR_NUM_THREADS', 'HF_HUB_OFFLINE', 'TRANSFORMERS_OFFLINE', 'WANDB_DISABLED', 'ORT_DISABLE_TELEMETRY_EVENTS'];

    /** @return array<string, string|false> */
    public static function make(array $overrides = []): array
    {
        // Symfony inherits environment variables unless explicitly set to false.
        // A denylist of known secrets misses newly added payment/cloud credentials.
        $inherited = array_merge((array) getenv(), $_ENV, $_SERVER);
        $environment = array_fill_keys(array_filter(array_keys($inherited), 'is_string'), false);
        foreach (self::SYSTEM_NAMES as $name) {
            $value = getenv($name);
            if (is_string($value)) {
                $environment[$name] = $value;
            }
        }
        foreach ($overrides as $name => $value) {
            if ($value !== false && ! in_array($name, self::RUNTIME_NAMES, true)) {
                throw new InvalidArgumentException('Unapproved child process environment variable.');
            }
            $environment[$name] = $value;
        }

        return array_replace($environment, [
            'PYTHONDONTWRITEBYTECODE' => '1', 'PYTHONNOUSERSITE' => '1',
            'HF_HUB_OFFLINE' => '1', 'TRANSFORMERS_OFFLINE' => '1', 'WANDB_DISABLED' => 'true',
            'OMP_NUM_THREADS' => '1', 'OPENBLAS_NUM_THREADS' => '1', 'MKL_NUM_THREADS' => '1',
        ]);
    }
}
