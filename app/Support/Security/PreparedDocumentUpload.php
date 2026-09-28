<?php

namespace App\Support\Security;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class PreparedDocumentUpload
{
    private ?string $temporary = null;

    public readonly UploadedFile $file;

    public function __construct(UploadedFile $original)
    {
        $mime = $original->getMimeType();
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $this->file = $original;

            return;
        }
        $dimensions = @getimagesize($original->getRealPath());
        if (! $dimensions || $dimensions[0] < 1 || $dimensions[1] < 1
            || (int) config('security.uploads.max_pixels', 16_000_000) < $dimensions[0] * $dimensions[1]
            || ! function_exists('imagecreatefromstring')) {
            $this->invalid();
        }
        $image = @imagecreatefromstring($original->getContent());
        if ($image === false) {
            $this->invalid();
        }
        try {
            if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
                $exif = @exif_read_data($original->getRealPath(), 'IFD0', true, false);
                $orientation = (int) ($exif['IFD0']['Orientation'] ?? 1);
                if (in_array($orientation, [2, 4, 5, 7], true)) {
                    imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
                }
                $rotation = match ($orientation) {
                    3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0
                };
                if ($rotation !== 0) {
                    $rotated = imagerotate($image, $rotation, 0);
                    if ($rotated === false) {
                        $this->invalid();
                    }
                    $image = $rotated;
                }
            }
            $temporary = tempnam(sys_get_temp_dir(), 'rf-document-');
            if (! is_string($temporary) || ! chmod($temporary, 0600)) {
                if (is_string($temporary)) {
                    @unlink($temporary);
                }
                $this->invalid();
            }
            $this->temporary = $temporary;
            imagesavealpha($image, true);
            $written = match ($mime) {
                'image/jpeg' => imagejpeg($image, $temporary, 92),
                'image/png' => imagepng($image, $temporary, 6),
                'image/webp' => imagewebp($image, $temporary, 92),
            };
            if (! $written || filesize($temporary) < 1 || filesize($temporary) > (int) config('security.uploads.max_bytes', 10_485_760)) {
                $this->invalid();
            }
            $this->file = new UploadedFile($temporary, $original->getClientOriginalName(), $mime, test: true);
        } finally {
            unset($image);
        }
    }

    public function __destruct()
    {
        if ($this->temporary !== null) {
            @unlink($this->temporary);
        }
    }

    private function invalid(): never
    {
        if ($this->temporary !== null) {
            @unlink($this->temporary);
        }
        throw ValidationException::withMessages(['file' => __('Cette image est invalide ou trop volumineuse pour être traitée.')]);
    }
}
