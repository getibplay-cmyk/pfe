<?php

namespace App\Support\Auth;

use Illuminate\Support\Facades\Hash;

class PasswordHashInspector
{
    public function isCompatible(?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            return false;
        }

        $algorithm = Hash::info($hash)['algoName'] ?? 'unknown';

        return match (Hash::getDefaultDriver()) {
            'bcrypt' => $algorithm === 'bcrypt',
            'argon' => $algorithm === 'argon2i',
            'argon2id' => $algorithm === 'argon2id',
            default => false,
        };
    }

    public function expectedDriver(): string
    {
        return Hash::getDefaultDriver();
    }
}
