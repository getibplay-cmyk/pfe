<?php

namespace App\Support\Auth;

use InvalidArgumentException;

/** RFC 6238 / RFC 4226: SHA-1, 30-second steps, six digits. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function secret(): string
    {
        $bits = '';
        foreach (str_split(random_bytes(20)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn (string $chunk) => self::ALPHABET[bindec($chunk)], str_split($bits, 5)));
    }

    public function code(string $secret, int $counter, int $digits = 6): string
    {
        if (! preg_match('/^[A-Z2-7]{32}$/D', $secret) || $counter < 0 || ! in_array($digits, [6, 8], true)) {
            throw new InvalidArgumentException('Invalid TOTP parameters.');
        }
        $bits = '';
        foreach (str_split($secret) as $character) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $character)), 5, '0', STR_PAD_LEFT);
        }
        $key = implode('', array_map(fn (string $chunk) => chr(bindec($chunk)), str_split($bits, 8)));
        $hash = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $key, true);
        $offset = ord($hash[19]) & 15;
        $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($number % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public function match(string $secret, string $code, ?int $lastCounter = null): ?int
    {
        if (! preg_match('/^[0-9]{6}$/D', $code)) {
            return null;
        }
        $current = intdiv(now()->timestamp, 30);
        foreach ([$current, $current - 1, $current + 1] as $counter) {
            if ($counter > ($lastCounter ?? -1) && hash_equals($this->code($secret, $counter), $code)) {
                return $counter;
            }
        }

        return null;
    }
}
