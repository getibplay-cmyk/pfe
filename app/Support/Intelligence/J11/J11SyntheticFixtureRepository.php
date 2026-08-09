<?php

namespace App\Support\Intelligence\J11;

use App\Enums\J11AdvisoryModule;
use JsonException;
use UnexpectedValueException;

final class J11SyntheticFixtureRepository
{
    public function __construct(
        private readonly J11SyntheticFixtureValidator $validator,
        private readonly J11CanonicalPayload $canonicalPayload,
    ) {}

    /** @throws JsonException */
    public function get(J11AdvisoryModule $module): J11ValidatedFixture
    {
        $fixtureBytes = $this->verifiedBytes(
            resource_path('intelligence/j11/fixtures/'.$module->fixtureFile()),
            $module->fixtureSha256(),
        );
        $this->verifiedBytes(
            resource_path('intelligence/j11/schemas/'.$module->schemaFile()),
            $module->schemaSha256(),
        );

        $record = json_decode($fixtureBytes, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($record) || array_is_list($record)) {
            throw new UnexpectedValueException('La fixture J11 doit être un objet JSON.');
        }

        $validation = $this->validator->validate($module, $record);
        if (! $validation->passed()) {
            throw new UnexpectedValueException('Fixture J11 invalide : '.implode(', ', $validation->failedChecks()));
        }

        $idempotency = $record['idempotency'];

        return new J11ValidatedFixture(
            module: $module,
            recordId: (string) $record['record_id'],
            idempotencyKey: (string) $idempotency['key'],
            fingerprint: $this->canonicalPayload->digest($record),
            payload: $record,
        );
    }

    private function verifiedBytes(string $path, string $expectedSha256): string
    {
        $bytes = @file_get_contents($path);
        if (! is_string($bytes) || hash('sha256', $bytes) !== $expectedSha256) {
            throw new UnexpectedValueException('L’artefact J11 local est absent ou son empreinte est invalide.');
        }

        return $bytes;
    }
}
