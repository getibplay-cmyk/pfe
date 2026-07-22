<?php

namespace App\Support\Ui;

use App\Models\User;

class NavigationBuilder
{
    public function for(User $user): array
    {
        if ($user->is_platform_admin) {
            return [
                [
                    'label' => 'Vue d’ensemble',
                    'items' => [
                        $this->item('platform-dashboard', 'Vue plateforme', 'platform.dashboard', 'platform.dashboard'),
                    ],
                ],
                [
                    'label' => 'Administration',
                    'items' => [
                        $this->item('platform-tenants', 'Tenants', 'platform.tenants.index', 'platform.tenants.*'),
                    ],
                ],
            ];
        }

        return array_values(array_filter([
            $this->section('Vue d’ensemble', [
                $this->item('dashboard', 'Tableau de bord', 'dashboard', 'dashboard'),
                $this->item('notifications', 'Notifications', 'notifications.index', 'notifications.*'),
            ]),
            $this->section('Exploitation', [
                $this->when($user, 'reservation.view', $this->item('availability', 'Disponibilité', 'availability.index', 'availability.*')),
                $this->when($user, 'customer.view', $this->item('customers', 'Clients et conducteurs', 'customers.index', 'customers.*')),
            ]),
            $this->section('Locations', [
                $this->when($user, 'reservation.view', $this->item('reservations', 'Réservations', 'reservations.index', 'reservations.*')),
                $this->when($user, 'contract.view', $this->item('contracts', 'Contrats', 'contracts.index', 'contracts.*')),
                $this->when($user, 'pricing.view', $this->item('pricing', 'Tarification', 'pricing-rules.index', 'pricing-rules.*')),
            ]),
            $this->section('Flotte', [
                $this->when($user, 'vehicle.view', $this->item('vehicles', 'Véhicules', 'vehicles.index', 'vehicles.*')),
                $this->when($user, 'vehicle.view', $this->item('vehicle-categories', 'Catégories', 'vehicle-categories.index', 'vehicle-categories.*')),
                $this->when($user, 'vehicle_block.manage', $this->item('vehicle-blocks', 'Blocs véhicules', 'vehicle-blocks.index', 'vehicle-blocks.*')),
                $this->when($user, 'maintenance.view', $this->item('maintenance', 'Maintenance', 'maintenance.index', 'maintenance.*')),
                $this->when($user, 'insurance.view', $this->item('insurance', 'Assurance', 'insurance.index', 'insurance.*')),
            ]),
            $this->section('Finance', [
                $this->whenAny($user, ['invoice.view', 'payment.view', 'deposit.view', 'expense.view'], $this->item('finance', 'Finance', 'finance.index', 'finance.*')),
            ]),
            $this->section('Pilotage', [
                $this->when($user, 'report.view', $this->item('reports', 'Rapports', 'reports.index', 'reports.*')),
            ]),
            $this->section('Administration', [
                $this->when($user, 'tenant.manage', $this->item('tenant', 'Entreprise', 'tenant.show', 'tenant.*')),
                $this->whenAny($user, ['agency.view', 'agency.manage'], $this->item('agencies', 'Agences', 'agencies.index', 'agencies.*')),
                $this->whenAny($user, ['user.view', 'user.manage'], $this->item('users', 'Utilisateurs', 'users.index', 'users.*')),
                $this->when($user, 'role.view', $this->item('roles', 'Rôles et permissions', 'roles.index', 'roles.*')),
                $this->when($user, 'audit.view', $this->item('audit', 'Journal d’audit', 'audit-logs.index', 'audit-logs.*')),
            ]),
        ]));
    }

    private function section(string $label, array $items): ?array
    {
        $items = array_values(array_filter($items));

        return $items === [] ? null : compact('label', 'items');
    }

    private function item(string $key, string $label, string $route, string $pattern): array
    {
        return compact('key', 'label', 'route', 'pattern');
    }

    private function when(User $user, string $permission, array $item): ?array
    {
        return $user->hasPermission($permission) ? $item : null;
    }

    private function whenAny(User $user, array $permissions, array $item): ?array
    {
        return collect($permissions)->contains(fn (string $permission) => $user->hasPermission($permission)) ? $item : null;
    }
}
