<?php

namespace App\Support\Ui;

final class SessionDevice
{
    /** User-agent labels are informational only, never authentication evidence.
     * @return array{browser: string, platform: string}
     */
    public static function describe(string $agent): array
    {
        $browser = match (true) {
            preg_match('/Edg(?:e|A|iOS)?\//i', $agent) === 1 => 'Microsoft Edge',
            preg_match('/(?:OPR|Opera)\//i', $agent) === 1 => 'Opera',
            preg_match('/(?:Firefox|FxiOS)\//i', $agent) === 1 => 'Firefox',
            preg_match('/(?:Chrome|CriOS)\//i', $agent) === 1 => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => __('Navigateur non identifié'),
        };
        $platform = match (true) {
            str_contains($agent, 'Android') => 'Android',
            preg_match('/iPhone|iPad|iPod/', $agent) === 1 => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => __('Appareil non identifié'),
        };

        return compact('browser', 'platform');
    }
}
