<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FRENCH_NAMES = [
        'tenant-owner@atlas-demo.test' => 'Administrateur de l’entreprise — démonstration',
        'agency-manager@atlas-demo.test' => 'Responsable d’agence — démonstration',
        'rental-agent@atlas-demo.test' => 'Agent de location — démonstration',
        'fleet-manager@atlas-demo.test' => 'Responsable de flotte — démonstration',
        'accountant@atlas-demo.test' => 'Comptable — démonstration',
        'viewer-auditor@atlas-demo.test' => 'Lecteur / auditeur — démonstration',
        'owner@rif-demo.test' => 'Administrateur de l’entreprise — démonstration Rif',
        'manager@rif-demo.test' => 'Responsable d’agence — démonstration Rif',
        'platform@rentfleet.test' => 'Administrateur de la plateforme — démonstration',
    ];

    private const LEGACY_NAMES = [
        'tenant-owner@atlas-demo.test' => 'Tenant Owner Démo',
        'agency-manager@atlas-demo.test' => 'Agency Manager Démo',
        'rental-agent@atlas-demo.test' => 'Rental Agent Démo',
        'fleet-manager@atlas-demo.test' => 'Fleet Manager Démo',
        'accountant@atlas-demo.test' => 'Accountant Démo',
        'viewer-auditor@atlas-demo.test' => 'Viewer/Auditor Démo',
        'owner@rif-demo.test' => 'Tenant Owner Rif Démo',
        'manager@rif-demo.test' => 'Agency Manager Rif Démo',
        'platform@rentfleet.test' => 'Platform Admin Démo',
    ];

    public function up(): void
    {
        $this->renameKnownDemoAccounts(self::FRENCH_NAMES);
    }

    public function down(): void
    {
        $this->renameKnownDemoAccounts(self::LEGACY_NAMES);
    }

    private function renameKnownDemoAccounts(array $names): void
    {
        foreach ($names as $email => $name) {
            DB::table('users')
                ->where('email', $email)
                ->where(function ($query) use ($email): void {
                    if ($email === 'platform@rentfleet.test') {
                        $query->where('is_platform_admin', true)->whereNull('tenant_id');

                        return;
                    }

                    $query->whereIn('tenant_id', DB::table('tenants')
                        ->whereIn('slug', ['atlas-location-demo', 'rif-mobilite-demo'])
                        ->select('id'));
                })
                ->update(['name' => $name, 'updated_at' => now()]);
        }
    }
};
