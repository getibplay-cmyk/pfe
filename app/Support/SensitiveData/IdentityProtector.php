<?php

namespace App\Support\SensitiveData;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class IdentityProtector
{
    public function protect(string $value): array
    {
        $normalized = $this->normalize($value);

        return [
            'encrypted' => Crypt::encryptString($normalized),
            'hash' => hash_hmac('sha256', app(TenantContext::class)->tenantId().'|'.$normalized, (string) config('app.key')),
        ];
    }

    public function reveal(string $encrypted): string
    {
        return Crypt::decryptString($encrypted);
    }

    public function maskEncrypted(?string $encrypted): ?string
    {
        if (! $encrypted) {
            return null;
        }
        $value = $this->reveal($encrypted);

        return str_repeat('•', max(4, mb_strlen($value) - 4)).mb_substr($value, -4);
    }

    private function normalize(string $value): string
    {
        return Str::upper(preg_replace('/[^\pL\pN]/u', '', Str::ascii(trim($value))) ?? '');
    }
}
