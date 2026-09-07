<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $model->setAttribute('tenant_id', app(TenantContext::class)->tenantId());
        });

        // A previously loaded model must not outlive its authorized tenant context.
        $assertOwnership = function (Model $model): void {
            $tenantId = app(TenantContext::class)->tenantId();
            if ((int) $model->getRawOriginal('tenant_id') !== $tenantId
                || (int) $model->getAttribute('tenant_id') !== $tenantId) {
                throw new AuthorizationException('Cette ressource ne fait pas partie de votre entreprise.');
            }
        };
        static::updating($assertOwnership);
        static::deleting($assertOwnership);
    }
}
