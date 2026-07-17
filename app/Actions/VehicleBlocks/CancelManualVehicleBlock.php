<?php

namespace App\Actions\VehicleBlocks;

use App\Enums\VehicleBlockStatus;
use App\Enums\VehicleBlockType;
use App\Models\VehicleBlock;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelManualVehicleBlock
{
    public function __construct(
        private AgencyAccess $agencies,
        private AuditRecorder $audit,
    ) {}

    public function handle(VehicleBlock $block): VehicleBlock
    {
        $this->agencies->required($block->agency_id);

        return DB::transaction(function () use ($block) {
            $locked = VehicleBlock::query()->whereKey($block)->lockForUpdate()->firstOrFail();

            if ($locked->block_type !== VehicleBlockType::Manual || $locked->status !== VehicleBlockStatus::Active) {
                throw ValidationException::withMessages(['vehicle_block' => 'Seul un bloc manuel actif peut être annulé.']);
            }

            if (! $locked->starts_at->isFuture()) {
                throw ValidationException::withMessages(['vehicle_block' => 'Un bloc déjà commencé doit être libéré et non annulé.']);
            }

            $locked->forceFill([
                'status' => VehicleBlockStatus::Cancelled,
                'released_at' => now(),
            ])->save();

            $this->audit->record('vehicle_block.manual.cancelled', $locked, ['status' => 'active'], ['status' => 'cancelled']);

            return $locked->refresh();
        });
    }
}
