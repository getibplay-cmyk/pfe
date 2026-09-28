<?php

namespace App\Support\Security;

use Illuminate\Validation\ValidationException;
use Throwable;

final class UploadInspection
{
    public function __construct(private readonly MalwareScanner $scanner) {}

    public function inspect(string $path, string $field = 'file'): string
    {
        if (! is_file($path) || is_link($path) || filesize($path) < 1
            || filesize($path) > (int) config('security.uploads.max_bytes', 10_485_760)) {
            throw ValidationException::withMessages([$field => __('Le fichier est invalide ou dépasse la taille autorisée.')]);
        }
        $before = hash_file('sha256', $path);
        if (! app()->environment('production') && ! config('security.uploads.scan_required')) {
            return (string) $before; // Integrity only; never a clean antivirus verdict.
        }
        try {
            $this->scanner->assertClean($path);
            if (! is_string($before) || ! hash_equals($before, (string) hash_file('sha256', $path))) {
                throw new \RuntimeException('FILE_CHANGED_DURING_SCAN');
            }
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => __('Le fichier n’a pas pu être validé par le contrôle de sécurité. Réessayez ou contactez l’administration.')]);
        }

        return $before;
    }
}
