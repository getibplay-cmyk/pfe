<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
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

        $roles = [
            'tenant-owner' => ['Tenant Owner', Permission::pluck('slug')->all()],
            'agency-manager' => ['Agency Manager', ['agency.view', 'agency.manage', 'user.view', 'user.manage', 'audit.view', 'vehicle.view', 'vehicle.create', 'vehicle.update', 'vehicle.archive', 'customer.view', 'customer.create', 'customer.update', 'customer.identity.view', 'document.view', 'document.upload', 'document.download', 'document.delete']],
            'rental-agent' => ['Rental Agent', ['agency.view', 'user.view', 'customer.view', 'customer.create', 'customer.update', 'customer.identity.view', 'document.view', 'document.upload', 'document.download', 'reservation.view', 'contract.view']],
            'fleet-manager' => ['Fleet Manager', ['agency.view', 'vehicle.view', 'vehicle.create', 'vehicle.update', 'vehicle.archive', 'maintenance.view', 'document.view', 'document.upload', 'document.download']],
            'accountant' => ['Accountant', ['agency.view', 'payment.view', 'report.view']],
            'viewer-auditor' => ['Viewer/Auditor', ['agency.view', 'user.view', 'vehicle.view', 'customer.view', 'document.view', 'report.view', 'audit.view']],
        ];

        foreach ($roles as $slug => [$name, $permissions]) {
            $role = Role::firstOrCreate(
                ['tenant_id' => null, 'slug' => $slug],
                ['name' => $name, 'is_system' => true],
            );
            $role->permissions()->sync(Permission::whereIn('slug', $permissions)->pluck('id'));
        }
    }
}
