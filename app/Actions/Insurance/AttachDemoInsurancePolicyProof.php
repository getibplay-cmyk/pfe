<?php

namespace App\Actions\Insurance;

use App\Actions\Documents\RequireValidCurrentDocument;
use App\Actions\Documents\StorePrivateDocument;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\InsurancePolicy;
use App\Support\Audit\AuditRecorder;
use App\Support\Insurance\DemoInsurancePolicyProof;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AttachDemoInsurancePolicyProof
{
    public function __construct(
        private readonly StorePrivateDocument $documents,
        private readonly RequireValidCurrentDocument $validDocuments,
        private readonly DemoInsurancePolicyProof $proof,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(InsurancePolicy $policy, int $actorId, string $auditAction): Document
    {
        $existing = $policy->documents()
            ->where('document_type', DocumentType::InsurancePolicySigned->value)
            ->with('currentVersion')
            ->get()
            ->first(fn (Document $document): bool => $this->validDocuments->isValid($document));

        if ($existing) {
            if ($policy->document_id !== $existing->id) {
                $policy->forceFill(['document_id' => $existing->id])->save();
            }

            return $existing;
        }

        $file = $this->proof->make();
        $storedPath = null;

        try {
            return DB::transaction(function () use ($policy, $actorId, $auditAction, $file, &$storedPath): Document {
                $locked = InsurancePolicy::whereKey($policy)->lockForUpdate()->firstOrFail();
                $document = $this->documents->handle($locked, [
                    'document_type' => DocumentType::InsurancePolicySigned->value,
                    'title' => 'Attestation d’assurance — démonstration non contractuelle',
                    'is_sensitive' => true,
                ], $file, $actorId)->load('currentVersion');
                $storedPath = $document->currentVersion?->stored_path;
                $locked->forceFill(['document_id' => $document->id])->save();
                $this->audit->record($auditAction, $locked, ['document_id' => null], [
                    'document_id' => $document->id,
                    'reason' => 'Preuve fictive non contractuelle requise par le Lot 06F-C2',
                ]);

                return $document;
            });
        } catch (\Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk(config('documents.disk'))->delete($storedPath);
            }
            throw $exception;
        } finally {
            $this->proof->cleanup($file);
        }
    }
}
