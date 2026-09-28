<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SafePassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) > 512) {
            $fail(__('Le mot de passe dépasse la longueur autorisée.'));

            return;
        }
        if (str_contains($value, "\0")) {
            $fail(__('Le mot de passe contient un caractère non autorisé.'));

            return;
        }
        if (mb_strlen($value) < 15) {
            return; // The enclosing Password rule rejects this without an external lookup.
        }
        // bcrypt only considers the first 72 bytes. Reject instead of truncating.
        if (Hash::getDefaultDriver() === 'bcrypt' && strlen($value) > 72) {
            $fail(__('Ce mot de passe dépasse 72 octets. Utilisez une phrase plus courte.'));

            return;
        }
        if (in_array(mb_strtolower($value), ['passwordpassword', 'password123456789', '123456789012345', '1234567890123456', 'azertyuiopqsdfgh', 'qwertyuiopasdfgh', 'motdepasse123456'], true)
            || preg_match('/^(.)\1+$/usD', $value) === 1) {
            $fail(__('Choisissez une phrase de passe moins courante.'));

            return;
        }
        if (! config('security.password_breach_check')) {
            return;
        }
        // Only the SHA-1 prefix is sent. Neither the password nor its full hash leaves the application.
        $digest = strtoupper(sha1($value));
        try {
            $response = Http::connectTimeout(2)->timeout(5)->withoutRedirecting()
                ->withOptions(['progress' => static function ($total, $downloaded): void {
                    if ($total > 2_000_000 || $downloaded > 2_000_000) {
                        throw new \RuntimeException('Password screening response too large.');
                    }
                }])
                ->withHeaders(['Add-Padding' => 'true', 'User-Agent' => 'RentFleet-Password-Screening'])
                ->get('https://api.pwnedpasswords.com/range/'.substr($digest, 0, 5));
            if (! $response->successful() || strlen($response->body()) > 2_000_000) {
                throw new \RuntimeException('Password screening unavailable.');
            }
            $validRows = 0;
            foreach (explode("\n", $response->body()) as $line) {
                if (preg_match('/^([A-F0-9]{35}):(\d+)$/D', trim($line), $matches) !== 1) {
                    continue;
                }
                $validRows++;
                if (hash_equals(substr($digest, 5), $matches[1]) && (int) $matches[2] > 0) {
                    $fail(__('Ce mot de passe apparaît dans une fuite connue. Choisissez-en un autre.'));

                    return;
                }
            }
            if ($validRows === 0) {
                throw new \RuntimeException('Invalid password screening response.');
            }
        } catch (Throwable) {
            $fail(__('La vérification du mot de passe est temporairement indisponible. Réessayez dans quelques instants.'));
        }
    }
}
