<?php

namespace Tests\Unit;

use App\Support\Ui\SessionDevice;
use PHPUnit\Framework\TestCase;

class SessionDeviceTest extends TestCase
{
    public function test_browser_and_platform_labels_handle_overlapping_user_agents(): void
    {
        foreach ([
            ['Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36 Edg/130.0', 'Microsoft Edge', 'Windows'],
            ['Mozilla/5.0 (Linux; Android 15) Chrome/130.0 Safari/537.36', 'Chrome', 'Android'],
            ['Mozilla/5.0 (iPhone) FxiOS/130.0 Safari/605.1', 'Firefox', 'iOS'],
            ['Mozilla/5.0 (Macintosh) Safari/605.1', 'Safari', 'macOS'],
            ['Mozilla/5.0 (Windows) Chrome/130.0 OPR/116.0', 'Opera', 'Windows'],
        ] as [$agent, $browser, $platform]) {
            $this->assertSame(compact('browser', 'platform'), SessionDevice::describe($agent));
        }
    }
}
