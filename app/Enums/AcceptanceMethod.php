<?php

namespace App\Enums;

enum AcceptanceMethod: string
{
    case Checkbox = 'checkbox';
    case TypedName = 'typed_name';
    case HandwrittenCapture = 'handwritten_capture';
}
