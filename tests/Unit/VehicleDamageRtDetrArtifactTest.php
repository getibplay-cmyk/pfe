<?php

namespace Tests\Unit;

use App\Support\Intelligence\VehicleDamage\VehicleDamageContract;
use App\Support\Intelligence\VehicleDamage\VehicleDamageModelArtifact;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VehicleDamageRtDetrArtifactTest extends TestCase
{
    public function test_closed_rtdetr_pair_is_accepted_and_seal_tampering_is_rejected(): void
    {
        Storage::fake('local');
        $directory = Storage::disk('local')->path('private-model-fixture');
        mkdir($directory, 0700, true);
        $modelPath = $directory.'/model.onnx';
        $cardPath = $directory.'/model_card.json';
        file_put_contents($modelPath, str_repeat('x', 1_000_000));
        $modelSha256 = hash_file('sha256', $modelPath);
        $card = $this->card($modelSha256);
        file_put_contents($cardPath, json_encode($card, JSON_THROW_ON_ERROR));

        config([
            'intelligence.vehicle_damage_v1.backend' => VehicleDamageContract::BACKEND_RTDETRV2_S,
            'intelligence.vehicle_damage_v1.model_path' => $modelPath,
            'intelligence.vehicle_damage_v1.model_card_path' => $cardPath,
            'intelligence.vehicle_damage_v1.model_sha256' => $modelSha256,
            'intelligence.vehicle_damage_v1.model_card_sha256' => hash_file('sha256', $cardPath),
        ]);

        $artifact = app(VehicleDamageModelArtifact::class);
        $this->assertTrue($artifact->configuredIsValid());

        $card['safety']['test_used'] = true;
        file_put_contents($cardPath, json_encode($card, JSON_THROW_ON_ERROR));
        config([
            'intelligence.vehicle_damage_v1.model_card_sha256' => hash_file('sha256', $cardPath),
        ]);
        $this->assertFalse($artifact->configuredIsValid());
    }

    /** @return array<string, mixed> */
    private function card(string $modelSha256): array
    {
        return [
            'model_id' => VehicleDamageContract::MODEL_CARD_ID,
            'model_name' => VehicleDamageContract::MODEL_NAME,
            'model_version' => VehicleDamageContract::MODEL_VERSION,
            'task' => 'consultative_vehicle_damage_detection',
            'architecture' => 'rtdetrv2_r18vd',
            'classes' => ['0' => 'dommage_visible'],
            'onnx_sha256' => $modelSha256,
            'decision_threshold' => VehicleDamageContract::DECISION_THRESHOLD,
            'input' => [
                'images_name' => 'images',
                'orig_target_sizes_name' => 'orig_target_sizes',
                'color' => 'RGB',
                'resize' => 640,
                'normalization' => 'zero_one',
            ],
            'outputs' => ['labels', 'boxes', 'scores'],
            'postprocess' => [
                'type' => 'hard_nms',
                'class_agnostic' => true,
                'iou_threshold' => 0.72,
                'max_candidates' => 12,
            ],
            'source_checkpoint' => [
                'filename' => 'selected_checkpoint_soup_19_24_29_inference_only.pth',
                'sha256' => VehicleDamageContract::SOURCE_CHECKPOINT_SHA256,
                'epochs' => [19, 24, 29],
                'weights' => [0.25, 0.5, 0.25],
            ],
            'validation' => [
                'AP' => VehicleDamageContract::VALIDATION_AP,
                'AP50' => VehicleDamageContract::VALIDATION_AP50,
                'AP75' => VehicleDamageContract::VALIDATION_AP75,
                'operating_profile' => 'precision_90',
                'precision_iou50' => VehicleDamageContract::VALIDATION_PRECISION_IOU50,
                'recall_iou50' => VehicleDamageContract::VALIDATION_RECALL_IOU50,
                'tuned_on_validation' => true,
            ],
            'scientific_gate' => ['AP' => 0.40, 'AP50' => 0.65, 'passed' => false],
            'safety' => [
                'human_review_required' => true,
                'automatic_business_action_allowed' => false,
                'final_test_sealed' => true,
                'calibration_used' => false,
                'test_used' => false,
                'local_pilot_required' => true,
            ],
        ];
    }
}
