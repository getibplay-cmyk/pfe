<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

final class TenantIntelligenceUnavailableException extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct('Cette fonctionnalité n’est pas disponible pour cette entreprise.');
    }
}
