<?php

namespace App\Support\Intelligence;

final readonly class PredictionResult
{
    /**
     * @param  list<array<string, string>>  $factors
     */
    public function __construct(
        public string $schemaVersion,
        public string $externalId,
        public string $entityType,
        public string $entityKey,
        public string $source,
        public string $modelName,
        public string $modelVersion,
        public string $score,
        public string $label,
        public array $factors,
        public string $recommendation,
        public string $predictedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'external_id' => $this->externalId,
            'entity_type' => $this->entityType,
            'entity_key' => $this->entityKey,
            'source' => $this->source,
            'model_name' => $this->modelName,
            'model_version' => $this->modelVersion,
            'score' => $this->score,
            'label' => $this->label,
            'factors' => $this->factors,
            'recommendation' => $this->recommendation,
            'predicted_at' => $this->predictedAt,
        ];
    }
}
