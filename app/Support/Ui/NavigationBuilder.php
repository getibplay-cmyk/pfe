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
                    'label' => __('Vue d’ensemble'),
                    'items' => [
                        $this->item('platform-dashboard', __('Tableau de bord'), 'platform.dashboard', 'platform.dashboard'),
                        $this->item('platform-statistics', 'Statistiques', 'platform.statistics.index', 'platform.statistics.*'),
                        $this->item('platform-operations', 'Supervision', 'platform.operations.index', 'platform.operations.*'),
                        $this->item('platform-audit', __('Journal global'), 'platform.audit-logs.index', 'platform.audit-logs.*'),
                    ],
                ],
                [
                    'label' => __('Entreprises et facturation'),
                    'items' => [
                        $this->item('platform-tenants', __('Entreprises clientes'), 'platform.tenants.index', 'platform.tenants.*'),
                        $this->item('platform-onboarding', __('Invitations d’accueil'), 'platform.onboarding-invitations.index', 'platform.onboarding-invitations.*'),
                        $this->item('platform-plans', __('Offres ').config('brand.name'), 'platform.plans.index', 'platform.plans.*'),
                        $this->item('platform-subscriptions', 'Abonnements', 'platform.subscriptions.index', 'platform.subscriptions.*'),
                        $this->item('platform-saas-payments', 'Paiements', 'platform.saas-payments.index', 'platform.saas-payments.*'),
                    ],
                ],
                [
                    'label' => __('Fonctionnalités intelligentes'),
                    'items' => [
                        $this->item('platform-intelligence', __('Fonctionnalités et accès'), 'platform.intelligence.index', 'platform.intelligence.*'),
                        $this->item('platform-training', __('Réentraînement des modèles'), 'platform.training.index', 'platform.training.*'),
                    ],
                ],
            ];
        }

        return array_values(array_filter([
            $this->section(__('Vue d’ensemble'), [
                $this->item('workspace', __('Recherche et favoris'), 'workspace.index', 'workspace.*'),
                $this->item('dashboard', __('Tableau de bord'), 'dashboard', 'dashboard'),
                $this->whenTenantOwner($user, $this->item('onboarding', __('Démarrage guidé'), 'onboarding.index', 'onboarding.*')),
                $this->item('notifications', 'Notifications', 'notifications.index', 'notifications.*'),
            ]),
            $this->section(__('Activité locative'), [
                $user->hasPermission('reservation.view') && $user->hasPermission('customer.view') ? $this->item('booking-requests', __('Demandes publiques'), 'booking-admin.index', ['booking-admin.index', 'booking-admin.show']) : null,
                $this->when($user, 'reservation.view', $this->item('availability', __('Disponibilité'), 'availability.index', 'availability.*')),
                $this->when($user, 'reservation.view', $this->item('reservations', __('Réservations'), 'reservations.index', 'reservations.*')),
                $this->when($user, 'contract.view', $this->item('contracts', __('Contrats'), 'contracts.index', 'contracts.*')),
                $this->when($user, 'contract.view', $this->item('contract-extensions', __('Prolongations locataires'), 'contract-extensions.index', 'contract-extensions.*')),
                $this->when($user, 'customer.view', $this->item('customers', __('Clients et conducteurs'), 'customers.index', ['customers.*', 'drivers.*'])),
                $this->when($user, 'pricing.view', $this->item('pricing', 'Tarification', 'pricing-rules.index', 'pricing-rules.*')),
            ]),
            $this->section(__('Parc automobile'), [
                $this->when($user, 'vehicle.view', $this->item('fleet-planning', __('Planning de flotte'), 'fleet.planning.index', 'fleet.planning.*')),
                $this->when($user, 'vehicle.view', $this->item('vehicles', __('Véhicules'), 'vehicles.index', 'vehicles.*')),
                $this->when($user, 'vehicle.view', $this->item('vehicle-categories', __('Catégories'), 'vehicle-categories.index', 'vehicle-categories.*')),
                $this->when($user, 'vehicle_block.manage', $this->item('vehicle-blocks', __('Blocs véhicules'), 'vehicle-blocks.index', 'vehicle-blocks.*')),
                $this->when($user, 'maintenance.view', $this->item('maintenance', 'Maintenance', 'maintenance.index', 'maintenance.*')),
                $this->when($user, 'insurance.view', $this->item('insurance', 'Assurance', 'insurance.index', 'insurance.*')),
            ]),
            $this->section('Finance', [
                $user->hasPermission('report.view') && $user->hasPermission('invoice.view') && $user->hasPermission('expense.view')
                    ? $this->item('vehicle-profitability', __('Rentabilité des véhicules'), 'vehicle-profitability.index', 'vehicle-profitability.*') : null,
                $this->whenAny($user, ['invoice.view', 'payment.view', 'deposit.view', 'expense.view'], $this->item('finance', 'Finance', 'finance.index', 'finance.*')),
            ]),
            $this->section(__('Aide à la décision'), [
                $this->when($user, 'prediction.view', $this->item('model-quality', __('Qualité des modèles'), 'model-quality.index', 'model-quality.*')),
                $this->when($user, 'prediction.view', $this->item('annotations', __('Annotations vérifiées'), 'annotations.index', 'annotations.*')),
                $this->when($user, 'fleet.distance.view', $this->item('agency-distances', __('Distances inter-agences'), 'agency-distances.index', 'agency-distances.*')),
                $this->whenOperationalPlanner($user, $this->item('fleet-reallocation-planning', __('Planification des réallocations'), 'fleet.reallocation-planning.index', 'fleet.reallocation-planning.*')),
                $this->when($user, 'prediction.view', $this->item('intelligence', __('Analyses et prévisions'), 'intelligence.index', 'intelligence.*')),
                $user->isTenantOwner() && $user->hasPermission('prediction.export') ? $this->item('model-training', __('Données d’apprentissage'), 'model-training.index', 'model-training.*') : null,
                $this->when($user, 'report.view', $this->item('reports', 'Rapports', 'reports.index', 'reports.*')),
            ]),
            $this->section('Administration', [
                $this->whenTenantOwner($user, $this->item('booking-catalog', __('Catalogue public'), 'booking-admin.settings', 'booking-admin.settings*')),
                $this->when($user, 'tenant.manage', $this->item('tenant', 'Entreprise', 'tenant.show', 'tenant.*')),
                $this->whenTenantOwner($user, $this->item('tenant-saas-account', __('Abonnement ').config('brand.name'), 'tenant-saas-account.show', 'tenant-saas-account.*')),
                $this->whenAny($user, ['agency.view', 'agency.manage'], $this->item('agencies', __('Agences'), 'agencies.index', 'agencies.*')),
                $this->whenAny($user, ['user.view', 'user.manage'], $this->item('users', 'Utilisateurs', 'users.index', 'users.*')),
                $this->when($user, 'role.view', $this->item('roles', __('Rôles et permissions'), 'roles.index', 'roles.*')),
                $this->when($user, 'audit.view', $this->item('audit', __('Journal d’audit'), 'audit-logs.index', 'audit-logs.*')),
            ]),
        ]));
    }

    private function section(string $label, array $items): ?array
    {
        $items = array_values(array_filter($items));

        $label = UiText::t($label);

        return $items === [] ? null : compact('label', 'items');
    }

    private function item(string $key, string $label, string $route, string|array $pattern): array
    {
        $label = UiText::t($label);

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
