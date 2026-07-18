<?php

namespace App\Actions\Insurance;

use App\Models\InsuranceCompany;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateInsuranceCompany
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(array $data): InsuranceCompany
    {
        return DB::transaction(function () use ($data): InsuranceCompany {
            $this->ensureActiveNameAvailable($data['name']);
            $company = InsuranceCompany::create([
                'name' => trim($data['name']),
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'is_active' => true,
            ]);
            $this->audit->record('insurance.company.created', $company, [], $this->values($company));

            return $company;
        });
    }

    private function ensureActiveNameAvailable(string $name): void
    {
        if (InsuranceCompany::query()->where('is_active', true)->whereRaw('lower(name) = lower(?)', [trim($name)])->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['name' => 'Une compagnie active porte déjà ce nom.']);
        }
    }

    private function values(InsuranceCompany $company): array
    {
        return ['name' => $company->name, 'email' => $company->email, 'phone' => $company->phone, 'is_active' => $company->is_active];
    }
}
