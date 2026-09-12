<?php

namespace App\Actions\Rentals;

use App\Enums\AcceptanceMethod;
use App\Models\ContractAcceptance;
use App\Models\ContractVersion;
use App\Support\Contracts\CanonicalJson;
use Illuminate\Support\Str;

/** Called under the contract/version transaction lock by the canonical acceptance actions. */
final class RecordContractAcceptance
{
    public function handle(ContractVersion $version, array $data, ?int $actorId): ContractAcceptance
    {
        abort_if($version->locked_at !== null, 409);
        $acceptedAt = now();
        $hash = app(CanonicalJson::class)->hash(['contract_version_hash' => $version->content_hash, 'accepted_by_name' => $data['accepted_by_name'], 'acceptance_method' => $data['acceptance_method'], 'consent_text_version' => config('rentals.consent_text_version'), 'accepted_at' => $acceptedAt->toIso8601String()]);
        $acceptance = ContractAcceptance::create(['rental_contract_id' => $version->rental_contract_id, 'contract_version_id' => $version->id, 'accepted_by_name' => $data['accepted_by_name'], 'acceptance_method' => AcceptanceMethod::from($data['acceptance_method']), 'consent_text_version' => config('rentals.consent_text_version'), 'accepted_at' => $acceptedAt, 'ip_address' => $data['ip_address'] ?? null, 'user_agent' => Str::limit((string) ($data['user_agent'] ?? ''), 1000, ''), 'signature_document_id' => $data['signature_document_id'] ?? null, 'content_hash' => $hash, 'created_by' => $actorId]);
        $version->forceFill(['locked_at' => $acceptedAt])->save();

        return $acceptance;
    }
}
