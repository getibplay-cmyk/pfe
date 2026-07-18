<?php

namespace App\Actions\Insurance;

use App\Models\InsuranceCompany;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateInsuranceCompany
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(InsuranceCompany $company, array $data): InsuranceCompany
    {
        return DB::transaction(function () use ($company, $data): InsuranceCompany {
            $locked = InsuranceCompany::whereKey($company)->lockForUpdate()->firstOrFail();
            if ($locked->is_active && InsuranceCompany::query()->whereKeyNot($locked->id)->where('is_active', true)->whereRaw('lower(name) = lower(?)', [trim($data['name'])])->exists()) {
                throw ValidationException::withMessages(['name' => 'Une compagnie active porte déjà ce nom.']);
            }
            $before = $this->values($locked);
            $locked->forceFill(['name' => trim($data['name']), 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null])->save();
            $this->audit->record('insurance.company.updated', $locked, $before, $this->values($locked));

            return $locked->refresh();
        });
    }

    private function values(InsuranceCompany $company): array
    {
        return ['name' => $company->name, 'email' => $company->email, 'phone' => $company->phone, 'is_active' => $company->is_active];
    }
}
