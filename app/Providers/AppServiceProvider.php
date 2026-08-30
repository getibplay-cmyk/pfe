<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\DamageReport;
use App\Models\Driver;
use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use App\Models\Invoice;
use App\Models\MaintenanceOrder;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Models\VehicleInspection;
use App\Support\Intelligence\PredictionScoringService;
use App\Support\Intelligence\RuleBasedScoringService;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\TestDatabaseGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->bind(PredictionScoringService::class, RuleBasedScoringService::class);

        $connections = config('database.connections');
        unset($connections['sqlite']);
        config(['database.connections' => $connections]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers());

        RateLimiter::for('reservation-demand-forecast', function (Request $request): array {
            $user = $request->user();
            $requestedAgency = $user?->agency_id ?? $request->integer('agency_id');
            $scope = implode('|', [
                'tenant:'.($user?->tenant_id ?? 'guest'),
                'agency:'.($requestedAgency > 0 ? $requestedAgency : 'none'),
            ]);
            $actor = $user?->getAuthIdentifier() ?? $request->ip();

            return [
                Limit::perMinute(max(
                    1,
                    (int) config(
                        'intelligence.demand_forecasting.rate_limits.user_per_minute',
                    ),
                ))->by('reservation-demand-forecast:user:'.$scope.'|actor:'.$actor),
                Limit::perHour(max(
                    1,
                    (int) config(
                        'intelligence.demand_forecasting.rate_limits.scope_per_hour',
                    ),
                ))->by('reservation-demand-forecast:scope:'.$scope),
            ];
        });

        RateLimiter::for('fleet-reallocation-planning', function (Request $request): array {
            $user = $request->user();
            $scope = 'tenant:'.($user?->tenant_id ?? 'guest');
            $actor = $user?->getAuthIdentifier() ?? $request->ip();

            return [
                Limit::perMinute(3)->by('fleet-reallocation-planning:user:'.$scope.'|actor:'.$actor),
                Limit::perHour(20)->by('fleet-reallocation-planning:scope:'.$scope),
            ];
        });

        RateLimiter::for('vehicle-color-v8', function (Request $request): array {
            $user = $request->user();
            $scope = implode('|', [
                'tenant:'.($user?->tenant_id ?? 'guest'),
                'agency:'.($user?->agency_id ?? 'all'),
            ]);
            $actor = $user?->getAuthIdentifier() ?? $request->ip();

            return [
                Limit::perMinute(max(
                    1,
                    (int) config('intelligence.vehicle_color_v8.rate_limits.user_per_minute'),
                ))->by('vehicle-color-v8:user:'.$scope.'|actor:'.$actor),
                Limit::perHour(max(
                    1,
                    (int) config('intelligence.vehicle_color_v8.rate_limits.scope_per_hour'),
                ))->by('vehicle-color-v8:scope:'.$scope),
            ];
        });

        RateLimiter::for('vehicle-damage-v1', function (Request $request): array {
            $user = $request->user();
            $scope = implode('|', [
                'tenant:'.($user?->tenant_id ?? 'guest'),
                'agency:'.($user?->agency_id ?? 'all'),
            ]);
            $actor = $user?->getAuthIdentifier() ?? $request->ip();

            return [
                Limit::perMinute(max(
                    1,
                    (int) config('intelligence.vehicle_damage_v1.rate_limits.user_per_minute'),
                ))->by('vehicle-damage-v1:user:'.$scope.'|actor:'.$actor),
                Limit::perHour(max(
                    1,
                    (int) config('intelligence.vehicle_damage_v1.rate_limits.scope_per_hour'),
                ))->by('vehicle-damage-v1:scope:'.$scope),
            ];
        });

        RateLimiter::for('vehicle-plate-hybrid', function (Request $request): array {
            $user = $request->user();
            $scope = implode('|', [
                'tenant:'.($user?->tenant_id ?? 'guest'),
                'agency:'.($user?->agency_id ?? 'all'),
            ]);
            $actor = $user?->getAuthIdentifier() ?? $request->ip();

            return [
                Limit::perMinute(max(
                    1,
                    (int) config(
                        'intelligence.vehicle_plate_hybrid_review.rate_limits.user_per_minute',
                    ),
                ))->by('vehicle-plate-hybrid:user:'.$scope.'|actor:'.$actor),
                Limit::perHour(max(
                    1,
                    (int) config(
                        'intelligence.vehicle_plate_hybrid_review.rate_limits.scope_per_hour',
                    ),
                ))->by('vehicle-plate-hybrid:scope:'.$scope),
            ];
        });

        RateLimiter::for('rental-usage-anomaly-v1', function (Request $request): array {
            $user = $request->user();
            $scope = implode('|', [
                'tenant:'.($user?->tenant_id ?? 'guest'),
                'agency:'.($user?->agency_id ?? 'all'),
            ]);
            $actor = $user?->getAuthIdentifier() ?? $request->ip();

            return [
                Limit::perMinute(max(
                    1,
                    (int) config('intelligence.rental_usage_anomaly.rate_limits.user_per_minute'),
                ))->by('rental-usage-anomaly-v1:user:'.$scope.'|actor:'.$actor),
                Limit::perHour(max(
                    1,
                    (int) config('intelligence.rental_usage_anomaly.rate_limits.scope_per_hour'),
                ))->by('rental-usage-anomaly-v1:scope:'.$scope),
            ];
        });

        RateLimiter::for('rental-usage-anomaly-review', function (Request $request): array {
            $user = $request->user();
            $resultRoute = $request->route('anomalyResult');
            $contractRoute = $request->route('contract');
            $resource = $resultRoute ?? $contractRoute;
            $resourceType = $resultRoute !== null ? 'result' : 'contract';
            $resourceKey = $resource instanceof Model
                ? (string) $resource->getKey()
                : (is_scalar($resource) && ctype_digit((string) $resource)
                    ? (string) $resource
                    : 'invalid');
            $scope = 'tenant:'.($user?->tenant_id ?? 'guest');
            $actor = $user?->getAuthIdentifier() ?? $request->ip();

            return [
                Limit::perMinute(10)->by(
                    'rental-usage-anomaly-review:'.$scope.'|actor:'.$actor
                    .'|'.$resourceType.':'.$resourceKey,
                ),
                Limit::perMinute(30)->by(
                    'rental-usage-anomaly-review:'.$scope.'|actor:'.$actor,
                ),
            ];
        });

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (TestDatabaseGuard::protects($event->command)) {
                TestDatabaseGuard::assertSafe(app());
            }
        });

        Relation::enforceMorphMap([
            'customer' => Customer::class,
            'driver' => Driver::class,
            'vehicle' => Vehicle::class,
            'rental_contract' => RentalContract::class,
            'reservation' => Reservation::class,
            'invoice' => Invoice::class,
            'vehicle_inspection' => VehicleInspection::class,
            'damage_report' => DamageReport::class,
            'maintenance_order' => MaintenanceOrder::class,
            'insurance_policy' => InsurancePolicy::class,
            'insurance_claim' => InsuranceClaim::class,
        ]);
    }
}
