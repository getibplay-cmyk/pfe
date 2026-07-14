<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\Driver;
use App\Models\Vehicle;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'customer' => Customer::class,
            'driver' => Driver::class,
            'vehicle' => Vehicle::class,
        ]);
    }
}
