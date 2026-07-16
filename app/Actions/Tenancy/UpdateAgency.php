<?php

namespace App\Actions\Tenancy;

use App\Models\Agency;
use App\Models\MaintenanceOrder;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VehicleBlock;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateAgency
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Agency $agency, array $data, User $actor): Agency
    {
        return DB::transaction(function () use ($agency, $data, $actor): Agency {
            $locked = Agency::query()->lockForUpdate()->findOrFail($agency->id);
            $deactivating = $locked->is_active && ! $data['is_active'];

            if ($actor->agency_id !== null && $locked->is_active !== $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => 'Seul le Tenant Owner peut changer l’état d’une agence.']);
            }
            if ($deactivating) {
                $this->ensureCanDeactivate($locked);
            }

            $old = $locked->only(['code', 'name', 'email', 'phone', 'address', 'is_active']);
            $locked->update($data);
            if ($deactivating) {
                $userIds = User::query()->where('tenant_id', $locked->tenant_id)->where('agency_id', $locked->id)->pluck('id');
                DB::table('sessions')->whereIn('user_id', $userIds)->delete();
            }
            $this->audit->record('agency.updated', $locked, $old, $locked->only(array_keys($old)));

            return $locked;
        });
    }

    private function ensureCanDeactivate(Agency $agency): void
    {
        $hasDependencies = Reservation::query()->where('agency_id', $agency->id)->whereIn('status', ['pending', 'confirmed'])->exists()
            || RentalContract::query()->where('agency_id', $agency->id)->whereIn('status', ['draft', 'ready', 'accepted', 'active', 'return_pending'])->exists()
            || MaintenanceOrder::query()->where('agency_id', $agency->id)->whereIn('status', ['planned', 'approved', 'in_progress'])->exists()
            || VehicleBlock::query()->where('agency_id', $agency->id)->where('status', 'active')->exists();

        if ($hasDependencies) {
            throw ValidationException::withMessages(['is_active' => 'Cette agence possède encore des réservations, contrats, maintenances ou blocs actifs.']);
        }
        if (Agency::query()->whereKeyNot($agency->id)->where('is_active', true)->doesntExist()) {
            throw ValidationException::withMessages(['is_active' => 'Le tenant doit conserver au moins une agence active.']);
        }
    }
}
