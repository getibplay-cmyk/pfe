<?php

namespace App\Support\Notifications;

use App\Models\InsurancePolicy;
use App\Models\InternalNotification;
use App\Models\Invoice;
use App\Models\MaintenanceOrder;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class NotificationDestination
{
    private const DESTINATIONS = [
        'reservation' => [Reservation::class, 'reservations.show'],
        'rental_contract' => [RentalContract::class, 'contracts.show'],
        'insurance_policy' => [InsurancePolicy::class, 'insurance.policies.show'],
        'maintenance_order' => [MaintenanceOrder::class, 'maintenance.show'],
        'invoice' => [Invoice::class, 'finance.invoices.show'],
    ];

    public function resolve(InternalNotification $notification, User $user): string
    {
        abort_unless($user->hasPermission($notification->required_permission), 403);
        [$modelClass, $routeName] = self::DESTINATIONS[$notification->resource_type] ?? abort(404);

        /** @var Model $resource */
        $resource = $modelClass::query()->findOrFail($notification->resource_id);
        abort_unless((int) $resource->getAttribute('tenant_id') === (int) $user->tenant_id, 403);
        abort_if($user->agency_id !== null && (int) $resource->getAttribute('agency_id') !== (int) $user->agency_id, 403);

        if (! $resource instanceof Invoice) {
            Gate::forUser($user)->authorize('view', $resource);
        }

        return route($routeName, $resource);
    }
}
