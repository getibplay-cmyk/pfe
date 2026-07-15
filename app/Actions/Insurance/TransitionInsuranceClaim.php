<?php

namespace App\Actions\Insurance;

use App\Enums\InsuranceClaimStatus;
use App\Models\InsuranceClaim;
use App\Models\InsuranceClaimStatusHistory;
use App\Support\Audit\AuditRecorder;
use App\Support\Pricing\DecimalMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransitionInsuranceClaim
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(InsuranceClaim $claim, InsuranceClaimStatus $target, array $data, int $actorId): InsuranceClaim
    {
        return DB::transaction(function () use ($claim, $target, $data, $actorId) {
            $locked = InsuranceClaim::whereKey($claim)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $from->canTransitionTo($target)) {
                throw ValidationException::withMessages(['status' => 'Cette transition de sinistre n’est pas autorisée.']);
            }

            $updates = ['status' => $target];
            if ($target === InsuranceClaimStatus::Submitted) {
                $updates['submitted_at'] = now();
            }
            if ($target === InsuranceClaimStatus::UnderReview) {
                $updates['submitted_at'] = $locked->submitted_at ?? now();
            }
            if ($target === InsuranceClaimStatus::Approved) {
                $approved = $this->amount($data, 'approved_amount');
                if ($approved > DecimalMoney::toMinorUnits($locked->claimed_amount)) {
                    throw ValidationException::withMessages(['approved_amount' => 'Le montant approuvé ne peut pas dépasser le montant demandé.']);
                }
                $updates['approved_amount'] = DecimalMoney::fromMinorUnits($approved);
                $updates['reviewed_at'] = now();
            }
            if ($target === InsuranceClaimStatus::Rejected) {
                $updates['reviewed_at'] = now();
            }
            if ($target === InsuranceClaimStatus::Settled) {
                $settled = $this->amount($data, 'settled_amount');
                if ($settled > DecimalMoney::toMinorUnits($locked->approved_amount)) {
                    throw ValidationException::withMessages(['settled_amount' => 'Le montant réglé ne peut pas dépasser le montant approuvé.']);
                }
                $updates['settled_amount'] = DecimalMoney::fromMinorUnits($settled);
            }

            $locked->forceFill($updates)->save();
            InsuranceClaimStatusHistory::create([
                'agency_id' => $locked->agency_id,
                'insurance_claim_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $target,
                'actor_id' => $actorId,
                'note' => $data['note'] ?? null,
                'changed_at' => now(),
            ]);
            $this->audit->record('insurance_claim.status.changed', $locked, ['status' => $from->value], ['status' => $target->value]);

            return $locked->refresh();
        });
    }

    private function amount(array $data, string $field): int
    {
        if (! isset($data[$field]) || $data[$field] === '') {
            throw ValidationException::withMessages([$field => 'Ce montant est requis pour cette transition.']);
        }

        $amount = DecimalMoney::toMinorUnits($data[$field]);
        if ($amount <= 0) {
            throw ValidationException::withMessages([$field => 'Ce montant doit être strictement positif.']);
        }

        return $amount;
    }
}
