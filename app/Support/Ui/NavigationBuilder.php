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
                        $this->item('platform-dashboard', 'Tableau de bord', 'platform.dashboard', 'platform.dashboard'),
                        $this->item('platform-statistics', 'Statistiques', 'platform.statistics.index', 'platform.statistics.*'),
                        $this->item('platform-audit', 'Journal global', 'platform.audit-logs.index', 'platform.audit-logs.*'),
                    ],
                ],
                [
                    'label' => 'Entreprises et facturation',
                    'items' => [
                        $this->item('platform-tenants', 'Entreprises clientes', 'platform.tenants.index', 'platform.tenants.*'),
                        $this->item('platform-plans', 'Offres '.config('brand.name'), 'platform.plans.index', 'platform.plans.*'),
                        $this->item('platform-subscriptions', 'Abonnements', 'platform.subscriptions.index', 'platform.subscriptions.*'),
                        $this->item('platform-saas-payments', 'Paiements', 'platform.saas-payments.index', 'platform.saas-payments.*'),
                    ],
                ],
                [
                    'label' => 'Fonctionnalités intelligentes',
                    'items' => [
                        $this->item('platform-intelligence', 'Fonctionnalités et accès', 'platform.intelligence.index', 'platform.intelligence.*'),
                    ],
                ],
            ];
        }

        return array_values(array_filter([
            $this->section('Vue d’ensemble', [
                $this->item('dashboard', 'Tableau de bord', 'dashboard', 'dashboard'),
                $this->item('notifications', 'Notifications', 'notifications.index', 'notifications.*'),
            ]),
            $this->section('Activité locative', [
                $this->when($user, 'reservation.view', $this->item('availability', 'Disponibilité', 'availability.index', 'availability.*')),
                $this->when($user, 'reservation.view', $this->item('reservations', 'Réservations', 'reservations.index', 'reservations.*')),
                $this->when($user, 'contract.view', $this->item('contracts', 'Contrats', 'contracts.index', 'contracts.*')),
                $this->when($user, 'customer.view', $this->item('customers', 'Clients et conducteurs', 'customers.index', ['customers.*', 'drivers.*'])),
                $this->when($user, 'pricing.view', $this->item('pricing', 'Tarification', 'pricing-rules.index', 'pricing-rules.*')),
            ]),
            $this->section('Parc automobile', [
                $this->when($user, 'vehicle.view', $this->item('vehicles', 'Véhicules', 'vehicles.index', 'vehicles.*')),
                $this->when($user, 'vehicle.view', $this->item('vehicle-categories', 'Catégories', 'vehicle-categories.index', 'vehicle-categories.*')),
                $this->when($user, 'vehicle_block.manage', $this->item('vehicle-blocks', 'Blocs véhicules', 'vehicle-blocks.index', 'vehicle-blocks.*')),
                $this->when($user, 'maintenance.view', $this->item('maintenance', 'Maintenance', 'maintenance.index', 'maintenance.*')),
                $this->when($user, 'insurance.view', $this->item('insurance', 'Assurance', 'insurance.index', 'insurance.*')),
            ]),
            $this->section('Finance', [
                $this->whenAny($user, ['invoice.view', 'payment.view', 'deposit.view', 'expense.view'], $this->item('finance', 'Finance', 'finance.index', 'finance.*')),
            ]),
            $this->section('Aide à la décision', [
                $this->when($user, 'fleet.distance.view', $this->item('agency-distances', 'Distances inter-agences', 'agency-distances.index', 'agency-distances.*')),
                $this->whenOperationalPlanner($user, $this->item('fleet-reallocation-planning', 'Planification des réallocations', 'fleet.reallocation-planning.index', 'fleet.reallocation-planning.*')),
                $this->when($user, 'prediction.view', $this->item('intelligence', 'Analyses et prévisions', 'intelligence.index', 'intelligence.*')),
                $this->when($user, 'report.view', $this->item('reports', 'Rapports', 'reports.index', 'reports.*')),
            ]),
            $this->section('Administration', [
                $this->when($user, 'tenant.manage', $this->item('tenant', 'Entreprise', 'tenant.show', 'tenant.*')),
                $this->whenTenantOwner($user, $this->item('tenant-saas-account', 'Abonnement '.config('brand.name'), 'tenant-saas-account.show', 'tenant-saas-account.*')),
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

    private function item(string $key, string $label, string $route, string|array $pattern): array
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

    private function whenOperationalPlanner(User $user, array $item): ?array
    {
        return in_array($user->role?->slug, ['tenant-owner', 'fleet-manager'], true)
            && $user->hasPermission('prediction.demo.review')
                ? $item
                : null;
    }

    private function whenTenantOwner(User $user, array $item): ?array
    {
        return $user->isTenantOwner() ? $item : null;
    }
}
