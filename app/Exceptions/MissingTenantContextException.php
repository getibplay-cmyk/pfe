<?php

namespace App\Exceptions;

use RuntimeException;

class MissingTenantContextException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Aucun contexte tenant n’est disponible pour cette opération métier.');
    }
}
