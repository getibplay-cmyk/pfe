<?php

namespace App\Support\Export;

class SpreadsheetSafeCsv
{
    public static function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/^[=+\-@\t\r\n]/u', $text) === 1 ? "'".$text : $text;
    }
}
