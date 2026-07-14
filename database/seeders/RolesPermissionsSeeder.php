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

        $roles = [
            'tenant-owner' => ['Tenant Owner', Permission::pluck('slug')->all()],
            'agency-manager' => ['Agency Manager', ['agency.view', 'agency.manage', 'user.view', 'user.manage', 'audit.view']],
            'rental-agent' => ['Rental Agent', ['agency.view', 'user.view', 'customer.view', 'reservation.view', 'contract.view']],
            'fleet-manager' => ['Fleet Manager', ['agency.view', 'vehicle.view', 'maintenance.view', 'document.view']],
            'accountant' => ['Accountant', ['agency.view', 'payment.view', 'report.view']],
            'viewer-auditor' => ['Viewer/Auditor', ['agency.view', 'user.view', 'report.view', 'audit.view']],
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
