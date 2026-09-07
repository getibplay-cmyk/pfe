<?php

namespace App\Http\Controllers;

use App\Models\PlatformBilling\SaasSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class OnboardingController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isTenantOwner(), 403);
        $tenant = $user->tenant;
        $tenantId = (int) $tenant->getKey();
        $settings = $tenant->settings ?? [];

        $steps = collect([
            [
                'label' => 'Compléter l’entreprise',
                'description' => 'Ajoutez la raison sociale, le téléphone et l’adresse.',
                'complete' => filled($tenant->legal_name) && filled($tenant->phone) && filled($settings['address'] ?? null),
                'url' => route('tenant.show'),
                'action' => 'Compléter le profil',
            ],
            [
                'label' => 'Vérifier l’agence initiale',
                'description' => 'Contrôlez ses coordonnées et son adresse opérationnelle.',
                'complete' => DB::table('agencies')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('is_active', true)->exists(),
                'url' => route('agencies.index'),
                'action' => 'Voir les agences',
            ],
            [
                'label' => 'Créer une catégorie de véhicule',
                'description' => 'Définissez une catégorie active avant de constituer le parc.',
                'complete' => DB::table('vehicle_categories')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('is_active', true)->exists(),
                'url' => route('vehicle-categories.create'),
                'action' => 'Créer une catégorie',
            ],
            [
                'label' => 'Ajouter le premier véhicule',
                'description' => 'Créez le parc qui sera proposé à la réservation.',
                'complete' => DB::table('vehicles')->where('tenant_id', $tenantId)->whereNull('deleted_at')->exists(),
                'url' => route('vehicles.create'),
                'action' => 'Ajouter un véhicule',
            ],
            [
                'label' => 'Définir une règle tarifaire',
                'description' => 'Configurez un tarif actif avant de prendre une réservation.',
                'complete' => DB::table('pricing_rules')->where('tenant_id', $tenantId)->where('is_active', true)->exists(),
                'url' => route('pricing-rules.create'),
                'action' => 'Créer un tarif',
            ],
            [
                'label' => 'Créer le premier client',
                'description' => 'Enregistrez les coordonnées nécessaires à une location.',
                'complete' => DB::table('customers')->where('tenant_id', $tenantId)->whereNull('deleted_at')->exists(),
                'url' => route('customers.create'),
                'action' => 'Ajouter un client',
            ],
            [
                'label' => 'Créer la première réservation',
                'description' => 'Validez le parcours métier avec une réservation réelle ou de démonstration.',
                'complete' => DB::table('reservations')->where('tenant_id', $tenantId)->whereNull('deleted_at')->exists(),
                'url' => route('reservations.create'),
                'action' => 'Créer une réservation',
            ],
        ]);
        $completed = $steps->where('complete', true)->count();
        $subscription = SaasSubscription::query()
            ->with('plan')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['trialing', 'active', 'past_due', 'suspended'])
            ->latest('starts_at')
            ->first();

        return view('tenant.onboarding', [
            'steps' => $steps,
            'completed' => $completed,
            'progress' => (int) round(($completed / max(1, $steps->count())) * 100),
            'subscription' => $subscription,
        ]);
    }
}
