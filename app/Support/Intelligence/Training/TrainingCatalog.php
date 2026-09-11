<?php

namespace App\Support\Intelligence\Training;

use App\Support\Intelligence\DemandForecasting\DemandForecastContract;
use App\Support\Intelligence\VehicleColor\VehicleColorContract;
use App\Support\Intelligence\VehicleDamage\VehicleDamageContract;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridContract;

final class TrainingCatalog
{
    public static function all(): array
    {
        return [
            'demand' => ['label' => 'Prévision de demande', 'baseline' => DemandForecastContract::MODEL_VERSION, 'recipe' => 'HGB Poisson, horizons de 1 à 7 jours', 'metric' => 'Erreur absolue moyenne', 'direction' => 'lower'],
            'anomaly' => ['label' => 'Anomalies d’usage', 'baseline' => 'robust_mad_top2::calibration-train-v1', 'recipe' => 'MAD figé sur l’apprentissage et challenger Isolation Forest', 'metric' => 'F1 des anomalies confirmées', 'direction' => 'higher'],
            'color' => ['label' => 'Couleur du véhicule', 'baseline' => VehicleColorContract::MODEL_VERSION, 'recipe' => 'MobileNet V3, annotations de couleur vérifiées', 'metric' => 'Exactitude', 'direction' => 'higher'],
            'damage' => ['label' => 'Détection des dommages', 'baseline' => VehicleDamageContract::modelVersion(), 'recipe' => 'RT-DETRv2, annotations COCO vérifiées', 'metric' => 'F1 des boîtes à IoU 0,50', 'direction' => 'higher'],
            'plate' => ['label' => 'Lecture de plaque', 'baseline' => VehiclePlateHybridContract::MODEL_NAME.'::crop-reference-v1', 'recipe' => 'OCR sur recadrages vérifiés, avec transcription humaine', 'metric' => 'Recadrages entièrement transcrits', 'direction' => 'higher'],
        ];
    }

    public static function get(string $family): array
    {
        abort_unless(isset(self::all()[$family]), 422, 'Famille de modèle inconnue.');

        return self::all()[$family];
    }
}
