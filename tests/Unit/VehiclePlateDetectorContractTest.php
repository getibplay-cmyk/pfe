<?php

namespace Tests\Unit;

use App\Exceptions\VehiclePlateHybridExecutionException;
use App\Models\VehiclePlatePredictionRun;
use App\Support\Intelligence\VehiclePlate\VehiclePlateDetectorContract;
use App\Support\Intelligence\VehiclePlate\VehiclePlateDetectorResultValidator;
use JsonException;
use PHPUnit\Framework\TestCase;

class VehiclePlateDetectorContractTest extends TestCase
{
    private string $cropPath;

    protected function setUp(): void
    {
        parent::setUp();
        $path = tempnam(sys_get_temp_dir(), 'rentfleet-plate-detector-test-');
        if (! is_string($path)) {
            throw new \RuntimeException('Temporary crop path unavailable.');
        }
        $this->cropPath = $path;
    }

    protected function tearDown(): void
    {
        @unlink($this->cropPath);
        parent::tearDown();
    }

    /** @throws JsonException */
    public function test_accepts_one_bounded_private_crop(): void
    {
        $contents = $this->jpegBytes();
        file_put_contents($this->cropPath, $contents);
        $payload = $this->payload($contents);

        $result = (new VehiclePlateDetectorResultValidator)->validate(
            json_encode($payload, JSON_THROW_ON_ERROR),
            $this->run(),
            $this->cropPath,
        );

        $this->assertTrue($result->detected());
        $this->assertSame(0.91, $result->score);
        $this->assertSame([0, 0, 4, 2], $result->cropBbox);
        $this->assertSame($contents, $result->cropContents);
    }

    /** @throws JsonException */
    public function test_refuses_full_frame_ocr_or_automatic_updates(): void
    {
        $contents = $this->jpegBytes();
        file_put_contents($this->cropPath, $contents);
        $payload = $this->payload($contents);
        $payload['safeguards']['full_frame_ocr_allowed'] = true;

        try {
            (new VehiclePlateDetectorResultValidator)->validate(
                json_encode($payload, JSON_THROW_ON_ERROR),
                $this->run(),
                $this->cropPath,
            );
            $this->fail('The unsafe detector payload should have been rejected.');
        } catch (VehiclePlateHybridExecutionException $exception) {
            $this->assertSame('DETECTOR_OUTPUT_CONTRACT_INVALID', $exception->failureCode());
        }
    }

    /** @throws JsonException */
    public function test_accepts_closed_no_detection_abstention_without_crop(): void
    {
        @unlink($this->cropPath);
        $payload = $this->payload(null);
        $payload['status'] = 'no_detection';
        $payload['score'] = null;
        $payload['bbox'] = null;
        $payload['detection'] = ['eligible_count' => 0, 'ambiguous' => false];
        $payload['crop'] = null;

        $result = (new VehiclePlateDetectorResultValidator)->validate(
            json_encode($payload, JSON_THROW_ON_ERROR),
            $this->run(),
            $this->cropPath,
        );

        $this->assertSame('no_detection', $result->status);
        $this->assertFalse($result->detected());
        $this->assertNull($result->cropContents);
    }

    private function run(): VehiclePlatePredictionRun
    {
        return (new VehiclePlatePredictionRun)->forceFill([
            'run_id' => '123e4567-e89b-12d3-a456-426614174000',
            'input_width' => 4,
            'input_height' => 2,
            'input_sha256' => str_repeat('b', 64),
            'detector_checkpoint_sha256' => str_repeat('a', 64),
            'detector_threshold' => 0.075,
            'detector_padding_ratio' => 0.04,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(?string $cropContents): array
    {
        return [
            'schema_version' => VehiclePlateDetectorContract::RESULT_SCHEMA_VERSION,
            'model_name' => VehiclePlateDetectorContract::MODEL_NAME,
            'architecture' => VehiclePlateDetectorContract::ARCHITECTURE,
            'run_id' => '123e4567-e89b-12d3-a456-426614174000',
            'status' => 'detected',
            'checkpoint_sha256' => str_repeat('a', 64),
            'threshold' => 0.075,
            'score' => 0.91,
            'bbox' => [0.0, 0.0, 4.0, 2.0],
            'image' => [
                'width' => 4,
                'height' => 2,
                'sha256' => str_repeat('b', 64),
            ],
            'detection' => ['eligible_count' => 1, 'ambiguous' => false],
            'crop' => $cropContents === null ? null : [
                'mime' => 'image/jpeg',
                'bytes' => strlen($cropContents),
                'sha256' => hash('sha256', $cropContents),
                'width' => 4,
                'height' => 2,
                'padding_ratio' => 0.04,
                'bbox' => [0, 0, 4, 2],
            ],
            'timings_seconds' => ['model_load' => 0.5, 'inference' => 0.1],
            'environment' => [
                'python' => '3.12.13',
                'torch' => '2.11.0',
                'torchvision' => '0.26.0',
                'device' => 'cpu',
                'isolated_process' => true,
                'min_size' => 768,
                'max_size' => 1280,
            ],
            'safeguards' => [
                'development_only' => true,
                'human_review_required' => true,
                'automatic_vehicle_update_allowed' => false,
                'full_frame_ocr_allowed' => false,
            ],
        ];
    }

    private function jpegBytes(): string
    {
        $bytes = base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAMCAgMCAgMDAwMEAwMEBQgFBQQEBQoHBwYIDAoMDAsKCwsNDhIQDQ4RDgsLEBYQERMUFRUVDA8XGBYUGBIUFRT/2wBDAQMEBAUEBQkFBQkUDQsNFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBT/wAARCAACAAQDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD9U6KKKAP/2Q==',
            true,
        );
        if (! is_string($bytes)) {
            throw new \LogicException('Invalid JPEG fixture.');
        }

        return $bytes;
    }
}
