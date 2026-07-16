<?php

namespace App\Support\Auth;

use Illuminate\Support\Str;

class TemporaryPassword
{
    public static function generate(): string
    {
        return Str::password(24, true, true, true, false);
    }
}
