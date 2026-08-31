<?php

namespace Tests\Unit;

use App\Exceptions\VehiclePlateHybridExecutionException;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridContract;
use App\Support\Intelligence\VehiclePlate\VehiclePlateHybridResultValidator;
use JsonException;
use PHPUnit\Framework\TestCase;

class VehiclePlateHybridContractTest extends TestCase
{
    /** @throws JsonException */
    public function test_validates_one_consultative_suggestion_without_exposing_raw_observations(): void
    {
        $result = (new VehiclePlateHybridResultValidator)->validate(
            json_encode($this->validPayload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'crop-1',
        );

        $this->assertSame('complete_primary_suggestion', $result->status);
        $this->assertSame('12345|أ|7', $result->canonical);
        $this->assertSame('12345 | أ | 7', $result->displayText);
        $this->assertSame(0.96, $result->confidence);
        $this->assertFalse($result->fallbackExecuted);
        $this->assertObjectNotHasProperty('observations', $result);
    }

    /** @throws JsonException */
    public function test_refuses_any_automatic_vehicle_update(): void
    {
        $payload = $this->validPayload();
        $payload['safeguards']['automatic_vehicle_update_allowed'] = true;

        try {
            (new VehiclePlateHybridResultValidator)->validate(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'crop-1',
            );
            $this->fail('The automatic-action payload should have been rejected.');
        } catch (VehiclePlateHybridExecutionException $exception) {
            $this->assertSame('PLATE_OUTPUT_CONTRACT_INVALID', $exception->failureCode());
        }
    }

    /** @throws JsonException */
    public function test_refuses_components_that_do_not_reconstruct_the_canonical_plate(): void
    {
        $payload = $this->validPayload();
        $payload['results'][0]['suggestion']['components'][2]['value'] = '8';

        try {
            (new VehiclePlateHybridResultValidator)->validate(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'crop-1',
            );
            $this->fail('The inconsistent component payload should have been rejected.');
        } catch (VehiclePlateHybridExecutionException $exception) {
            $this->assertSame('PLATE_OUTPUT_COMPONENTS_INVALID', $exception->failureCode());
        }
    }

    /** @throws JsonException */
    public function test_accepts_empty_full_crop_result_when_tiny_crop_cannot_be_segmented(): void
    {
        $payload = $this->validPayload();
        $payload['results'][0]['fallback_executed'] = false;
        $payload['results'][0]['suggestion'] = [
            'schema_version' => VehiclePlateHybridContract::SUGGESTION_SCHEMA_VERSION,
            'status' => 'empty_suggestion',
            'canonical' => null,
            'display_text' => '? | ? | ?',
            'confidence' => 0.0,
            'confidence_semantics' => VehiclePlateHybridContract::CONFIDENCE_SEMANTICS,
            'source' => 'segmented_ppocrv5_fusion',
            'model_name' => VehiclePlateHybridContract::MODEL_NAME,
            'components' => [],
            'reasons' => ['no_readable_plate_component'],
            'human_review_required' => true,
            'operational_effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
        ];
        $payload['status_counts'] = ['empty_suggestion' => 1];

        $result = (new VehiclePlateHybridResultValidator)->validate(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'crop-1',
        );

        $this->assertSame('empty_suggestion', $result->status);
        $this->assertNull($result->canonical);
        $this->assertFalse($result->fallbackExecuted);
    }

    public function test_canonical_helper_accepts_only_closed_moroccan_grammar(): void
    {
        $this->assertTrue(VehiclePlateHybridContract::isCanonical('12345|أ|7'));
        $this->assertFalse(VehiclePlateHybridContract::isCanonical('0123|أ|7'));
        $this->assertFalse(VehiclePlateHybridContract::isCanonical('12345|ج|7'));
        $this->assertFalse(VehiclePlateHybridContract::isCanonical('12345 | أ | 7'));
    }

    /** @throws JsonException */
    public function test_refuses_non_finite_or_out_of_range_suggestion_confidence(): void
    {
        foreach ([-0.01, 1.01] as $confidence) {
            $payload = $this->validPayload();
            $payload['results'][0]['suggestion']['confidence'] = $confidence;
            $this->assertInvalidOutput(
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'PLATE_OUTPUT_SUGGESTION_INVALID',
            );
        }

        $json = json_encode(
            $this->validPayload(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
        foreach (['NaN', '1e999'] as $nonFinite) {
            $invalidJson = str_replace(
                '"confidence":0.96,"confidence_semantics"',
                '"confidence":'.$nonFinite.',"confidence_semantics"',
                $json,
            );
            $this->assertInvalidOutput($invalidJson, [
                'PLATE_OUTPUT_JSON_INVALID',
                'PLATE_OUTPUT_SUGGESTION_INVALID',
            ]);
        }
    }

    /** @throws JsonException */
    public function test_refuses_a_complete_suggestion_outside_the_existing_canonical_grammar(): void
    {
        $payload = $this->validPayload();
        $payload['results'][0]['suggestion']['canonical'] = '0123|ج|99';

        $this->assertInvalidOutput(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'PLATE_OUTPUT_POLICY_MISMATCH',
        );
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        $component = static fn (string $role, string $value): array => [
            'role' => $role,
            'value' => $value,
            'confidence' => 0.96,
            'support' => 1,
            'evidence' => ['full:original'],
            'inferred_from_latin' => false,
        ];

        return [
            'schema_version' => VehiclePlateHybridContract::RESULT_SCHEMA_VERSION,
            'fallback_version' => VehiclePlateHybridContract::FALLBACK_VERSION,
            'model_name' => VehiclePlateHybridContract::MODEL_NAME,
            'count' => 1,
            'results' => [[
                'crop_id' => 'crop-1',
                'fallback_executed' => false,
                'suggestion' => [
                    'schema_version' => VehiclePlateHybridContract::SUGGESTION_SCHEMA_VERSION,
                    'status' => 'complete_primary_suggestion',
                    'canonical' => '12345|أ|7',
                    'display_text' => '12345 | أ | 7',
                    'confidence' => 0.96,
                    'confidence_semantics' => VehiclePlateHybridContract::CONFIDENCE_SEMANTICS,
                    'source' => 'full_crop_ppocrv5',
                    'model_name' => VehiclePlateHybridContract::MODEL_NAME,
                    'components' => [
                        $component('serial', '12345'),
                        $component('series', 'أ'),
                        $component('region', '7'),
                    ],
                    'reasons' => ['primary_reading_passed_moroccan_grammar'],
                    'human_review_required' => true,
                    'operational_effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
                ],
                'observations' => [
                    [
                        'layout_id' => 'full',
                        'role' => 'full',
                        'variant_id' => 'original',
                        'raw_text' => '7أ12345',
                        'score' => 0.96,
                    ],
                    [
                        'layout_id' => 'full',
                        'role' => 'full',
                        'variant_id' => 'clahe',
                        'raw_text' => '7أ12345',
                        'score' => 0.95,
                    ],
                ],
            ]],
            'status_counts' => ['complete_primary_suggestion' => 1],
            'timings_seconds' => [
                'ocr_load' => 0.5,
                'ocr_inference_total' => 0.1,
            ],
            'environment' => [
                'python' => '3.12.13',
                'paddle' => '3.3.0',
                'paddleocr' => '3.7.0',
                'paddle_cuda_compiled' => false,
                'paddle_gpu_count' => 0,
                'device' => 'cpu',
                'isolated_process' => true,
            ],
            'safeguards' => [
                'human_review_required' => true,
                'automatic_vehicle_update_allowed' => false,
                'operational_effect' => VehiclePlateHybridContract::OPERATIONAL_EFFECT,
                'second_ocr_model_used' => false,
            ],
        ];
    }

    /** @param string|list<string> $failureCode */
    private function assertInvalidOutput(string $json, string|array $failureCode): void
    {
        try {
            (new VehiclePlateHybridResultValidator)->validate($json, 'crop-1');
            $this->fail('The invalid vehicle plate output should have been rejected.');
        } catch (VehiclePlateHybridExecutionException $exception) {
            $this->assertContains($exception->failureCode(), (array) $failureCode);
        }
    }
}
