<?php

namespace App\Policies;

use App\Models\MaintenanceOrder;
use App\Models\User;

class MaintenanceOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('maintenance.view');
    }

    public function view(User $user, MaintenanceOrder $order): bool
    {
        return $this->sameScope($user, $order) && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('maintenance.create');
    }

    public function update(User $user, MaintenanceOrder $order): bool
    {
        return $this->sameScope($user, $order) && $order->status === 'planned' && $user->hasPermission('maintenance.update');
    }

    public function reschedule(User $user, MaintenanceOrder $order): bool
    {
        return $this->sameScope($user, $order) && in_array($order->status, ['planned', 'approved'], true) && $user->hasPermission('maintenance.update');
    }

    public function approve(User $user, MaintenanceOrder $order): bool
    {
        return $this->transition($user, $order, 'maintenance.approve', ['planned']);
    }

    public function start(User $user, MaintenanceOrder $order): bool
    {
        return $this->transition($user, $order, 'maintenance.start', ['approved']);
    }

    public function complete(User $user, MaintenanceOrder $order): bool
    {
        return $this->transition($user, $order, 'maintenance.complete', ['in_progress']);
    }

    public function cancel(User $user, MaintenanceOrder $order): bool
    {
        return $this->transition($user, $order, 'maintenance.cancel', ['planned', 'approved']);
    }

    public function uploadDocument(User $user, MaintenanceOrder $order): bool
    {
        return $this->sameScope($user, $order)
            && $user->hasPermission('maintenance.update')
            && $user->hasPermission('document.upload');
    }

    private function transition(User $user, MaintenanceOrder $order, string $permission, array $statuses): bool
    {
        return $this->sameScope($user, $order)
            && in_array($order->status, $statuses, true)
            && $user->hasPermission($permission);
    }

    private function sameScope(User $user, MaintenanceOrder $order): bool
    {
        return $user->tenant_id === $order->tenant_id
            && ($user->agency_id === null || $user->agency_id === $order->agency_id);
    }
}
