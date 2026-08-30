<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Ui\UiLabel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RolesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $groups = ['tenant', 'agency', 'user', 'vehicle', 'customer', 'reservation', 'contract', 'maintenance', 'payment', 'document', 'prediction', 'report', 'audit'];

        foreach ($groups as $group) {
            Permission::firstOrCreate(
                ['slug' => $group.'.view'],
                ['name' => 'Voir '.Str::headline($group), 'group' => $group],
            );
        }

        foreach (['tenant', 'agency', 'user', 'audit'] as $group) {
            Permission::firstOrCreate(
                ['slug' => $group.'.manage'],
                ['name' => 'Gérer '.Str::headline($group), 'group' => $group],
            );
        }

        $lotTwoPermissions = [
            'vehicle.create' => 'Créer un véhicule', 'vehicle.update' => 'Modifier un véhicule', 'vehicle.archive' => 'Archiver un véhicule',
            'customer.create' => 'Créer un client', 'customer.update' => 'Modifier un client', 'customer.identity.view' => 'Voir une identité complète',
            'document.upload' => 'Téléverser un document', 'document.download' => 'Télécharger un document', 'document.delete' => 'Archiver un document',
        ];
        foreach ($lotTwoPermissions as $slug => $name) {
            Permission::firstOrCreate(['slug' => $slug], ['name' => $name, 'group' => Str::before($slug, '.')]);
        }

        $lotThreePermissions = [
            'pricing.view' => 'Voir les tarifs', 'pricing.manage' => 'Gérer les tarifs',
            'reservation.view' => 'Voir les réservations', 'reservation.create' => 'Créer une réservation', 'reservation.update' => 'Modifier une réservation',
            'reservation.confirm' => 'Confirmer une réservation', 'reservation.cancel' => 'Annuler une réservation', 'reservation.export' => 'Exporter les réservations',
            'vehicle_block.manage' => 'Gérer les blocs véhicule',
        ];
        foreach ($lotThreePermissions as $slug => $name) {
            Permission::firstOrCreate(['slug' => $slug], ['name' => $name, 'group' => Str::before($slug, '.')]);
        }

        $lotFourPermissions = [
            'contract.create' => 'Créer un contrat', 'contract.version' => 'Versionner un contrat', 'contract.accept' => 'Accepter un contrat',
            'contract.activate' => 'Activer un contrat', 'contract.return' => 'Traiter le retour', 'contract.cancel' => 'Annuler un contrat brouillon',
            'inspection.manage' => 'Gérer les inspections', 'damage.view' => 'Voir les dommages', 'damage.report' => 'Signaler un dommage',
            'damage.review' => 'Décider la responsabilité', 'charge.review' => 'Revoir les frais',
        ];
        foreach ($lotFourPermissions as $slug => $name) {
            Permission::firstOrCreate(['slug' => $slug], ['name' => $name, 'group' => Str::before($slug, '.')]);
        }

        $lotFivePermissions = [
            'invoice.view' => 'Voir les factures', 'invoice.create' => 'Créer les factures', 'invoice.issue' => 'Émettre les factures', 'invoice.void' => 'Annuler une facture',
            'payment.view' => 'Voir les paiements', 'payment.create' => 'Saisir les paiements', 'payment.post' => 'Comptabiliser les paiements', 'payment.allocate' => 'Allouer les paiements', 'payment.reverse' => 'Contrepasser les paiements',
            'deposit.view' => 'Voir les cautions', 'deposit.create' => 'Saisir les mouvements de caution', 'deposit.reverse' => 'Contrepasser une caution',
            'expense.view' => 'Voir les dépenses', 'expense.create' => 'Saisir les dépenses', 'expense.approve' => 'Approuver les dépenses', 'expense.reject' => 'Rejeter les dépenses',
            'contract.close' => 'Clôturer financièrement un contrat',
            'maintenance.create' => 'Créer une maintenance', 'maintenance.update' => 'Modifier ou replanifier une maintenance', 'maintenance.approve' => 'Approuver une maintenance', 'maintenance.start' => 'Démarrer une maintenance', 'maintenance.complete' => 'Terminer une maintenance', 'maintenance.cancel' => 'Annuler une maintenance',
            'insurance.view' => 'Voir les assurances', 'insurance.manage' => 'Gérer les assurances',
            'claim.view' => 'Voir les sinistres assurance', 'claim.manage' => 'Gérer les sinistres assurance',
        ];
        foreach ($lotFivePermissions as $slug => $name) {
            Permission::firstOrCreate(['slug' => $slug], ['name' => $name, 'group' => Str::before($slug, '.')]);
        }

        Permission::firstOrCreate(
            ['slug' => 'prediction.export'],
            ['name' => 'Exporter le dataset Intelligence anonymisé', 'group' => 'prediction'],
        );
        Permission::firstOrCreate(
            ['slug' => 'prediction.demo.review'],
            ['name' => 'Revoir les contrats Intelligence synthétiques de démonstration', 'group' => 'prediction'],
        );
        Permission::firstOrCreate(
            ['slug' => 'prediction.forecast.import'],
            ['name' => 'Importer les prévisions de demande consultatives', 'group' => 'prediction'],
        );
        Permission::firstOrCreate(
            ['slug' => 'prediction.color.review'],
            ['name' => 'Analyser et revoir la couleur d’un véhicule', 'group' => 'prediction'],
        );
        Permission::firstOrCreate(
            ['slug' => 'prediction.damage.review'],
            ['name' => 'Analyser et revoir les zones de dommage d’un retour', 'group' => 'prediction'],
        );
        Permission::firstOrCreate(
            ['slug' => 'prediction.plate.review'],
            ['name' => 'Analyser et corriger une plaque de véhicule', 'group' => 'prediction'],
        );
        Permission::firstOrCreate(
            ['slug' => 'prediction.anomaly.review'],
            ['name' => 'Analyser et revoir les usages de location atypiques', 'group' => 'prediction'],
        );
        Permission::firstOrCreate(
            ['slug' => 'fleet.distance.view'],
            ['name' => 'Consulter les distances inter-agences', 'group' => 'fleet'],
        );
        Permission::firstOrCreate(
            ['slug' => 'fleet.distance.manage'],
            ['name' => 'Gérer les distances inter-agences vérifiées', 'group' => 'fleet'],
        );

        Permission::query()->get()->each(function (Permission $permission): void {
            $permission->forceFill(['name' => UiLabel::permission($permission->slug)])->save();
        });

        $roles = [
            'tenant-owner' => ['Administrateur de l’entreprise', Permission::pluck('slug')->all()],
            'agency-manager' => ['Responsable d’agence', ['agency.view', 'agency.manage', 'user.view', 'user.manage', 'audit.view', 'vehicle.view', 'vehicle.create', 'vehicle.update', 'vehicle.archive', 'customer.view', 'customer.create', 'customer.update', 'customer.identity.view', 'document.view', 'document.upload', 'document.download', 'document.delete', 'pricing.view', 'pricing.manage', 'reservation.view', 'reservation.create', 'reservation.update', 'reservation.confirm', 'reservation.cancel', 'reservation.export', 'vehicle_block.manage', 'contract.view', 'contract.create', 'contract.version', 'contract.accept', 'contract.activate', 'contract.return', 'contract.cancel', 'inspection.manage', 'damage.view', 'damage.report', 'damage.review', 'charge.review', 'prediction.view', 'prediction.export', 'prediction.color.review', 'prediction.damage.review', 'prediction.plate.review', 'prediction.anomaly.review', 'report.view']],
            'rental-agent' => ['Agent de location', ['agency.view', 'user.view', 'customer.view', 'customer.create', 'customer.update', 'customer.identity.view', 'document.view', 'document.upload', 'document.download', 'pricing.view', 'reservation.view', 'reservation.create', 'reservation.update', 'reservation.confirm', 'reservation.cancel', 'contract.view', 'contract.create', 'contract.version', 'contract.accept', 'contract.activate', 'contract.return', 'contract.cancel', 'inspection.manage', 'damage.view', 'damage.report']],
            'fleet-manager' => ['Responsable de flotte', ['agency.view', 'vehicle.view', 'vehicle.create', 'vehicle.update', 'vehicle.archive', 'maintenance.view', 'document.view', 'document.upload', 'document.download', 'reservation.view', 'vehicle_block.manage', 'contract.view', 'inspection.manage', 'damage.view', 'damage.report', 'damage.review', 'prediction.view', 'prediction.demo.review', 'prediction.forecast.import', 'prediction.color.review', 'prediction.damage.review', 'prediction.plate.review', 'prediction.anomaly.review', 'fleet.distance.view']],
            'accountant' => ['Comptable', ['agency.view', 'payment.view', 'report.view', 'pricing.view', 'reservation.view', 'contract.view', 'damage.view', 'charge.review']],
            'viewer-auditor' => ['Lecteur / auditeur', ['agency.view', 'user.view', 'vehicle.view', 'customer.view', 'document.view', 'pricing.view', 'reservation.view', 'contract.view', 'damage.view', 'prediction.view', 'report.view', 'audit.view']],
        ];

        foreach ($roles as $slug => [$name, $permissions]) {
            $role = Role::firstOrCreate(
                ['tenant_id' => null, 'slug' => $slug],
                ['name' => $name, 'is_system' => true],
            );
            $role->permissions()->sync(Permission::whereIn('slug', $permissions)->pluck('id'));
        }

        $grants = [
            'agency-manager' => ['invoice.view', 'payment.view', 'deposit.view', 'expense.view', 'maintenance.view', 'maintenance.create', 'maintenance.update', 'maintenance.approve', 'maintenance.start', 'maintenance.complete', 'maintenance.cancel', 'insurance.view', 'insurance.manage', 'claim.view', 'claim.manage'],
            'rental-agent' => ['invoice.view', 'payment.view', 'deposit.view', 'maintenance.view', 'insurance.view', 'claim.view'],
            'fleet-manager' => ['maintenance.view', 'maintenance.create', 'maintenance.update', 'maintenance.approve', 'maintenance.start', 'maintenance.complete', 'maintenance.cancel', 'expense.view', 'insurance.view', 'insurance.manage', 'claim.view', 'claim.manage'],
            'accountant' => ['invoice.view', 'invoice.create', 'invoice.issue', 'invoice.void', 'payment.view', 'payment.create', 'payment.post', 'payment.allocate', 'payment.reverse', 'deposit.view', 'deposit.create', 'deposit.reverse', 'expense.view', 'expense.create', 'expense.approve', 'expense.reject', 'contract.close'],
            'viewer-auditor' => ['invoice.view', 'payment.view', 'deposit.view', 'expense.view', 'maintenance.view', 'insurance.view', 'claim.view'],
        ];
        foreach ($grants as $roleSlug => $permissions) {
            Role::where('slug', $roleSlug)->firstOrFail()->permissions()->syncWithoutDetaching(Permission::whereIn('slug', $permissions)->pluck('id'));
        }
    }
}
