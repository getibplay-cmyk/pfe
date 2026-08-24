<?php

namespace App\Support\Intelligence\VehicleDamage;

final readonly class SanitizedVehicleDamageImage
{
    public function __construct(
        public string $contents,
        public string $mime,
        public string $extension,
        public int $bytes,
        public string $sha256,
        public int $width,
        public int $height,
    ) {}
}
