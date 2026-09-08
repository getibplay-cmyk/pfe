<?php

namespace App\Support\Tenancy;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Vehicles\CreateVehicle;
use App\Models\Agency;
use App\Models\Customer;
use App\Models\OnboardingImport;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Support\Audit\AuditRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OnboardingCsvImport
{
    public const HEADERS = [
        'vehicles' => ['immatriculation', 'marque', 'modele', 'categorie', 'carburant', 'transmission', 'kilometrage'],
        'customers' => ['prenom', 'nom', 'email', 'telephone'],
    ];

    public function preview(UploadedFile $file, string $kind, int $agencyId, User $actor): OnboardingImport
    {
        $this->authorize($actor);
        $agencyId = app(AgencyAccess::class)->required($agencyId);
        Agency::where('is_active', true)->findOrFail($agencyId);
        $contents = $file->get();
        if (! isset(self::HEADERS[$kind]) || $file->getSize() > 1024 * 1024 || ! mb_check_encoding($contents, 'UTF-8') || str_contains($contents, "\0")) {
            throw ValidationException::withMessages(['file' => 'Utilisez un fichier CSV UTF-8 de moins de 1 Mo.']);
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $lines = preg_split('/\r\n|\n|\r/', $contents);
        $headerIndex = null;
        $delimiter = ';';
        $headers = [];
        foreach (array_slice($lines, 0, 20) as $index => $line) {
            foreach ([';', ',', "\t"] as $candidate) {
                $columns = array_map(fn ($value) => Str::lower(Str::ascii(trim($value ?? ''))), str_getcsv($line, $candidate, '"', ''));
                if (count($columns) === count(self::HEADERS[$kind]) && count(array_unique($columns)) === count($columns)
                    && array_diff(self::HEADERS[$kind], $columns) === []) {
                    $headerIndex = $index;
                    $delimiter = $candidate;
                    $headers = $columns;
                    break 2;
                }
            }
        }
        if ($headerIndex === null) {
            throw ValidationException::withMessages(['file' => 'En-têtes introuvables dans les 20 premières lignes. Utilisez le modèle fourni sans ajouter de colonnes.']);
        }
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, implode("\n", array_slice($lines, $headerIndex + 1)));
        rewind($stream);
        $rows = [];
        while (($columns = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            if (count(array_filter($columns, fn ($value) => filled($value))) === 0) {
                continue;
            }
            if (count($columns) !== count($headers) || count($rows) >= 200) {
                fclose($stream);
                throw ValidationException::withMessages(['file' => 'Limite de 200 lignes ou nombre de colonnes incorrect. Corrigez le CSV.']);
            }
            $rows[] = array_combine($headers, array_map(fn ($value) => trim($value ?? ''), $columns));
        }
        fclose($stream);
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'Le fichier ne contient aucune donnée.']);
        }

        return OnboardingImport::create(['agency_id' => $agencyId, 'created_by' => $actor->id, 'kind' => $kind,
            'payload' => $rows, 'row_count' => count($rows), 'expires_at' => now()->addHour()]);
    }

    public function inspect(OnboardingImport $import): array
    {
        $seen = [];

        return collect($import->payload ?? [])->map(function (array $row, int $index) use ($import, &$seen) {
            $data = $import->kind === 'vehicles' ? [
                'agency_id' => $import->agency_id,
                'registration_number' => mb_strtoupper($row['immatriculation']), 'brand' => $row['marque'], 'model' => $row['modele'],
                'vehicle_category_id' => VehicleCategory::where('is_active', true)->whereRaw('LOWER(code) = ?', [mb_strtolower($row['categorie'])])->value('id'),
                'fuel_type' => match (Str::lower(Str::ascii($row['carburant']))) {
                    'essence', 'petrol' => 'petrol', 'diesel' => 'diesel', 'hybride', 'hybrid' => 'hybrid', 'electrique', 'electric' => 'electric', 'autre', 'other' => 'other', default => $row['carburant']
                },
                'transmission' => match (Str::lower(Str::ascii($row['transmission']))) {
                    'manuelle', 'manual' => 'manual', 'automatique', 'automatic' => 'automatic', default => $row['transmission']
                },
                'current_mileage' => $row['kilometrage'],
            ] : ['agency_id' => $import->agency_id, 'customer_type' => 'individual', 'first_name' => $row['prenom'], 'last_name' => $row['nom'], 'email' => $row['email'] ?: null, 'phone' => $row['telephone'] ?: null];
            $rules = $import->kind === 'vehicles' ? [
                'registration_number' => ['required', 'string', 'max:50'], 'brand' => ['required', 'string', 'max:100'], 'model' => ['required', 'string', 'max:100'],
                'vehicle_category_id' => ['required', 'integer'], 'fuel_type' => ['required', Rule::in(['petrol', 'diesel', 'hybrid', 'electric', 'other'])],
                'transmission' => ['required', Rule::in(['manual', 'automatic'])], 'current_mileage' => ['required', 'integer', 'between:0,2147483647'],
            ] : ['first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50']];
            $errors = Validator::make($data, $rules)->errors()->all();
            $fingerprint = $import->kind === 'vehicles' ? $data['registration_number'] : mb_strtolower($data['email'] ?: $data['first_name'].'|'.$data['last_name'].'|'.$data['phone']);
            $exists = $import->kind === 'vehicles'
                ? Vehicle::withTrashed()->whereRaw('UPPER(registration_number) = ?', [$data['registration_number']])->exists()
                : Customer::withTrashed()->where(function ($query) use ($data) {
                    if ($data['email']) {
                        $query->whereRaw('LOWER(email) = ?', [mb_strtolower($data['email'])]);
                    } else {
                        $query->whereRaw('LOWER(first_name) = ?', [mb_strtolower($data['first_name'])])->whereRaw('LOWER(last_name) = ?', [mb_strtolower($data['last_name'])])->where('phone', $data['phone']);
                    }
                })->exists();
            if (isset($seen[$fingerprint]) || $exists) {
                $errors[] = 'Doublon détecté dans le fichier ou dans votre entreprise. Retirez cette ligne avant de réimporter.';
            }
            $seen[$fingerprint] = true;

            return ['line' => $index + 1, 'source' => $row, 'data' => $data, 'errors' => $errors];
        })->all();
    }

    public function commit(OnboardingImport $import, User $actor): int
    {
        $this->authorize($actor);
        abort_unless($import->created_by === $actor->id, 403);

        return DB::transaction(function () use ($import, $actor) {
            // The tenant lock serializes import/quotas; the import lock makes confirmation idempotent.
            Tenant::whereKey(app(TenantContext::class)->tenantId())->lockForUpdate()->firstOrFail();
            $import = OnboardingImport::whereKey($import)->lockForUpdate()->firstOrFail();
            if ($import->completed_at) {
                return $import->row_count;
            }
            abort_unless($import->expires_at->gt(now()) && $import->payload !== null, 410, 'Cet aperçu a expiré. Importez à nouveau le fichier.');
            app(AgencyAccess::class)->required($import->agency_id);
            Agency::where('is_active', true)->findOrFail($import->agency_id);
            $rows = $this->inspect($import);
            if (collect($rows)->contains(fn ($row) => $row['errors'] !== [])) {
                throw ValidationException::withMessages(['file' => 'Le fichier contient des erreurs ou des doublons. Aucun enregistrement n’a été créé.']);
            }
            foreach ($rows as $row) {
                if ($import->kind === 'vehicles') {
                    app(CreateVehicle::class)->handle($row['data'], $actor->id);
                } else {
                    app(CreateCustomer::class)->handle($row['data']);
                }
            }
            $import->forceFill(['completed_at' => now(), 'payload' => null])->save();
            app(AuditRecorder::class)->record('tenant.onboarding_import_completed', $actor->tenant, [], ['kind' => $import->kind, 'row_count' => $import->row_count]);

            return $import->row_count;
        });
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->isTenantOwner() && $actor->hasPermission('vehicle.create') && $actor->hasPermission('customer.create')
            && (int) $actor->tenant_id === app(TenantContext::class)->tenantId(), 403);
    }
}
