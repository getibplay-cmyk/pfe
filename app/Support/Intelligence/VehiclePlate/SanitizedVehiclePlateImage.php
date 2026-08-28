<?php

namespace App\Support\Intelligence\VehiclePlate;

final readonly class SanitizedVehiclePlateImage
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
