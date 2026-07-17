<?php

namespace App\Actions\Customers;

use App\Models\Driver;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArchiveDriver
{
    public function __construct(private readonly AgencyAccess $agencyAccess, private readonly AuditRecorder $audit) {}

    public function handle(Driver $driver): void
    {
        DB::transaction(function () use ($driver): void {
            $locked = Driver::with('customer')->whereKey($driver)->lockForUpdate()->firstOrFail();
            $this->agencyAccess->required($locked->customer->agency_id);

            if ($locked->reservations()->whereIn('status', ['draft', 'pending', 'confirmed'])->exists()) {
                $this->blocked('Le conducteur est utilisé par une réservation active.');
            }

            $activeContract = DB::table('contract_drivers as cd')
                ->join('rental_contracts as rc', function ($join): void {
                    $join->on('rc.tenant_id', '=', 'cd.tenant_id')->on('rc.id', '=', 'cd.rental_contract_id');
                })
                ->where('cd.tenant_id', $locked->tenant_id)
                ->where('cd.driver_id', $locked->id)
                ->whereNotIn('rc.status', ['closed', 'cancelled'])
                ->exists();
            if ($activeContract) {
                $this->blocked('Le conducteur est utilisé par un contrat non terminal.');
            }

            $ongoingInspection = DB::table('contract_drivers as cd')
                ->join('vehicle_inspections as vi', function ($join): void {
                    $join->on('vi.tenant_id', '=', 'cd.tenant_id')->on('vi.rental_contract_id', '=', 'cd.rental_contract_id');
                })
                ->where('cd.tenant_id', $locked->tenant_id)
                ->where('cd.driver_id', $locked->id)
                ->where('vi.status', 'draft')
                ->exists();
            if ($ongoingInspection) {
                $this->blocked('Une inspection opérationnelle est encore en cours pour ce conducteur.');
            }

            $wasPrimary = $locked->is_primary;
            $locked->forceFill(['is_primary' => false])->save();
            $locked->delete();
            $this->audit->record('driver.archived', $locked, ['archived' => false, 'is_primary' => $wasPrimary], ['archived' => true, 'is_primary' => false]);
        });
    }

    private function blocked(string $message): never
    {
        throw ValidationException::withMessages(['driver' => $message]);
    }
}
