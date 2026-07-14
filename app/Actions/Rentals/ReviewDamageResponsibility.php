<?php

namespace App\Actions\Rentals;

use App\Enums\ContractChargeStatus;
use App\Enums\ContractChargeType;
use App\Enums\DamageResponsibility;
use App\Enums\DamageStatus;
use App\Models\ContractCharge;
use App\Models\DamageReport;
use App\Models\DamageStatusHistory;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewDamageResponsibility
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(DamageReport $damage, array $data, int $actorId): DamageReport
    {
        return DB::transaction(function () use ($damage, $data, $actorId) {
            $locked = DamageReport::whereKey($damage)->lockForUpdate()->firstOrFail();
            $responsibility = DamageResponsibility::from($data['responsibility']);
            $status = DamageStatus::from($data['status']);
            if ($status === DamageStatus::UnderReview && $responsibility === DamageResponsibility::Pending) {
                $from = $locked->status;
                $locked->forceFill(['status' => DamageStatus::UnderReview, 'reviewed_by' => $actorId])->save();
                DamageStatusHistory::create(['damage_report_id' => $locked->id, 'from_status' => $from, 'to_status' => DamageStatus::UnderReview, 'responsibility' => DamageResponsibility::Pending, 'reason' => $data['reason'] ?? null, 'changed_by' => $actorId]);
                $this->audit->record('damage.review.started', $locked, ['status' => $from->value], ['status' => 'under_review', 'responsibility' => 'pending']);

                return $locked->refresh();
            }
            if ($responsibility === DamageResponsibility::Pending || ! in_array($status, [DamageStatus::Resolved, DamageStatus::Dismissed], true)) {
                throw ValidationException::withMessages(['responsibility' => 'La revue humaine doit produire une responsabilité explicite et une décision finale.']);
            }
            if ($responsibility === DamageResponsibility::Customer && empty($data['approved_cost'])) {
                throw ValidationException::withMessages(['approved_cost' => 'Un coût approuvé est requis pour une responsabilité client.']);
            }
            $from = $locked->status;
            $locked->forceFill(['status' => $status, 'responsibility' => $responsibility, 'approved_cost' => $status === DamageStatus::Resolved ? ($data['approved_cost'] ?? '0.00') : null, 'reviewed_by' => $actorId, 'reviewed_at' => now()])->save();
            $locked->charges()->where('status', 'proposed')->update(['status' => ContractChargeStatus::Rejected->value, 'approved_by' => $actorId, 'approved_at' => now()]);
            if ($status === DamageStatus::Resolved && $responsibility === DamageResponsibility::Customer && $locked->approved_cost !== '0.00') {
                ContractCharge::create(['rental_contract_id' => $locked->rental_contract_id, 'damage_report_id' => $locked->id, 'charge_type' => ContractChargeType::Damage, 'description' => 'Dommage '.$locked->damage_number.' après revue humaine', 'quantity' => '1.00', 'unit_amount' => $locked->approved_cost, 'total_amount' => $locked->approved_cost, 'status' => ContractChargeStatus::Proposed, 'calculation_details' => ['human_reviewed' => true, 'responsibility' => 'customer']]);
            }
            DamageStatusHistory::create(['damage_report_id' => $locked->id, 'from_status' => $from, 'to_status' => $status, 'responsibility' => $responsibility, 'reason' => $data['reason'] ?? null, 'changed_by' => $actorId]);
            $this->audit->record('damage.reviewed', $locked, ['status' => $from->value, 'responsibility' => 'pending'], ['status' => $status->value, 'responsibility' => $responsibility->value, 'approved_cost' => $locked->approved_cost]);

            return $locked->refresh();
        });
    }
}
