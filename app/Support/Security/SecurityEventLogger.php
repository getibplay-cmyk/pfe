<?php

namespace App\Support\Security;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class SecurityEventLogger
{
    public static function record(string $event, string $outcome, ?User $user = null): void
    {
        $request = request();
        Log::channel('security')->notice('Security event.', [
            'event' => $event, 'outcome' => $outcome, 'at' => now()->utc()->toIso8601String(),
            'user_id' => $user?->id, 'tenant_id' => $user?->tenant_id,
            'route' => Str::limit((string) $request->route()?->getName(), 150, ''),
            'source_fingerprint' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
            'correlation_id' => $request->attributes->get('correlation_id'),
        ]);
    }
}
