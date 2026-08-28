<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class VehiclePlateRuntimeUnavailableException extends ServiceUnavailableHttpException
{
    public function __construct()
    {
        parent::__construct(
            null,
            'OCR de plaque indisponible : vérifiez le runtime PaddleOCR local avant activation.',
        );
    }
}
