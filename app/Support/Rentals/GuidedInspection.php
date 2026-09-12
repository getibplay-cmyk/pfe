<?php

namespace App\Support\Rentals;

use App\Actions\Documents\StorePrivateDocument;
use App\Actions\Rentals\CompleteDepartureInspection;
use App\Actions\Rentals\CompleteReturnInspection;
use App\Enums\RentalContractStatus;
use App\Models\InspectionDraft;
use App\Models\InspectionDraftPhoto;
use App\Models\RentalContract;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class GuidedInspection
{
    public const ANGLES = ['front' => 'Avant', 'back' => 'Arrière', 'left' => 'Côté gauche', 'right' => 'Côté droit', 'interior' => 'Habitacle', 'odometer' => 'Compteur kilométrique'];

    public const ITEMS = ['body' => 'Carrosserie', 'interior' => 'Habitacle', 'tyres' => 'Pneus', 'equipment' => 'Équipements'];

    public function authorize(User $user, RentalContract $contract, string $kind): void
    {
        abort_unless(in_array($kind, ['departure', 'return'], true), 404);
        Gate::forUser($user)->authorize('view', $contract);
        abort_unless($user->hasPermission('inspection.manage'), 403);
    }

    public function save(User $user, RentalContract $contract, string $kind, int $revision, array $data): InspectionDraft
    {
        $this->authorize($user, $contract, $kind);
        $clean = $this->data($data, false);

        return DB::transaction(function () use ($user, $contract, $kind, $revision, $clean) {
            $draft = $this->lock($user, $contract, $kind, $revision);
            $draft->forceFill(['data' => $clean, 'revision' => $revision + 1, 'saved_by' => $user->id])->save();

            return $draft;
        });
    }

    public function addPhoto(User $user, RentalContract $contract, string $kind, int $revision, string $angle, UploadedFile $file): InspectionDraft
    {
        $this->authorize($user, $contract, $kind);
        abort_unless($user->hasPermission('document.upload'), 403);
        Validator::make(['angle' => $angle, 'file' => $file], [
            'angle' => ['required', Rule::in(array_keys(self::ANGLES))],
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:8192', 'dimensions:min_width=64,min_height=64,max_width=6000,max_height=6000'],
        ])->validate();
        $dimensions = @getimagesize($file->getRealPath());
        if (! $dimensions || $dimensions[0] * $dimensions[1] > 20000000 || ! function_exists('imagecreatefromstring')) {
            throw ValidationException::withMessages(['file' => __('Cette photo ne peut pas être traitée. Utilisez une image plus petite ou contactez l’agence.')]);
        }
        $decoded = @imagecreatefromstring($file->getContent());
        if ($decoded === false) {
            throw ValidationException::withMessages(['file' => __('L’image est corrompue ou illisible.')]);
        }
        unset($decoded);
        $storedPath = null;
        try {
            return DB::transaction(function () use ($user, $contract, $kind, $revision, $angle, $file, &$storedPath) {
                $draft = $this->lock($user, $contract, $kind, $revision);
                if ($draft->photos()->count() >= 30) {
                    throw ValidationException::withMessages(['file' => __('La limite de 30 photos pour cet état des lieux est atteinte.')]);
                }
                $document = app(StorePrivateDocument::class)->handle($contract, [
                    'document_type' => 'inspection_photo', 'title' => 'Inspection '.$kind.' — '.self::ANGLES[$angle], 'is_sensitive' => true,
                ], $file, $user->id);
                $version = $document->currentVersion;
                $storedPath = $version->stored_path;
                InspectionDraftPhoto::create(['inspection_draft_id' => $draft->id, 'angle' => $angle, 'document_id' => $document->id, 'document_version_id' => $version->id, 'sha256' => $version->sha256, 'created_by' => $user->id]);
                $draft->forceFill(['revision' => $revision + 1, 'saved_by' => $user->id])->save();

                return $draft;
            });
        } catch (\Throwable $exception) {
            if ($storedPath) {
                Storage::disk(config('documents.disk'))->delete($storedPath);
            }
            throw $exception;
        }
    }

    public function complete(User $user, RentalContract $contract, string $kind, int $revision, array $data): InspectionDraft
    {
        $this->authorize($user, $contract, $kind);
        $clean = $this->data($data, true);

        return DB::transaction(function () use ($user, $contract, $kind, $revision, $clean) {
            $draft = $this->lock($user, $contract, $kind, $revision);
            $photos = $draft->photos()->latest('id')->get()->unique('angle');
            if ($photos->count() < count(self::ANGLES) && mb_strlen(trim($clean['photo_omission_reason'] ?? '')) < 10) {
                throw ValidationException::withMessages(['photo_omission_reason' => __('Ajoutez les six vues ou expliquez les photos manquantes en au moins 10 caractères.')]);
            }
            $inspectionData = ['mileage' => $clean['mileage'], 'fuel_level' => $clean['fuel_level'], 'notes' => $clean['notes'] ?? null, 'items' => []];
            foreach (self::ITEMS as $code => $label) {
                $inspectionData['items'][] = ['item_code' => $code, 'label' => $label, 'condition' => $clean['conditions'][$code]];
            }
            $action = app($kind === 'departure' ? CompleteDepartureInspection::class : CompleteReturnInspection::class);
            $inspection = $action->handle($contract, $inspectionData, $user->id);
            $draft->forceFill(['data' => $clean, 'revision' => $revision + 1, 'saved_by' => $user->id, 'vehicle_inspection_id' => $inspection->id, 'selected_photo_ids' => $photos->pluck('id')->values()->all(), 'completed_at' => now()])->save();
            app(AuditRecorder::class)->record('inspection.guided_completed', $draft, [], ['inspection_id' => $inspection->id, 'photo_count' => $photos->count(), 'missing_photos_explained' => $photos->count() < count(self::ANGLES)]);

            return $draft;
        });
    }

    private function data(array $data, bool $complete): array
    {
        $presence = $complete ? 'required' : 'nullable';

        return Validator::make($data, [
            'tenant_id' => ['prohibited'], 'agency_id' => ['prohibited'],
            'mileage' => [$presence, 'integer', 'between:0,10000000'], 'fuel_level' => [$presence, 'decimal:0,2', 'between:0,100'],
            'notes' => ['nullable', 'string', 'max:5000'], 'photo_omission_reason' => ['nullable', 'string', 'max:1000'],
            'conditions' => [$presence, 'array:body,interior,tyres,equipment'],
            ...collect(self::ITEMS)->mapWithKeys(fn ($label, $code) => ['conditions.'.$code => [$presence, Rule::in(['good', 'damaged', 'missing', 'not_checked'])]])->all(),
        ])->validate();
    }

    /** Parent lock serializes draft creation, photo capture and completion. */
    private function lock(User $user, RentalContract $contract, string $kind, int $revision): InspectionDraft
    {
        $locked = RentalContract::query()->whereKey($contract)->lockForUpdate()->firstOrFail();
        $this->authorize($user, $locked, $kind);
        $expected = $kind === 'departure' ? RentalContractStatus::Accepted : RentalContractStatus::Active;
        abort_unless($locked->status === $expected, 409, __('Le contrat a changé. Rechargez la page avant de continuer.'));
        abort_if($locked->inspections()->where('inspection_type', $kind)->where('status', 'completed')->exists(), 409, __('Cet état des lieux est déjà terminé.'));
        $draft = InspectionDraft::query()->where('rental_contract_id', $locked->id)->where('kind', $kind)->lockForUpdate()->first();
        if (! $draft) {
            abort_unless($revision === 0, 409);
            $draft = InspectionDraft::create(['agency_id' => $locked->agency_id, 'vehicle_id' => $locked->vehicle_id, 'rental_contract_id' => $locked->id, 'kind' => $kind, 'data' => [], 'revision' => 0, 'saved_by' => $user->id]);
        }
        abort_if($draft->completed_at || $draft->revision !== $revision, 409, __('Un autre enregistrement a modifié ce brouillon. Rechargez la page pour reprendre la dernière version.'));

        return $draft;
    }
}
