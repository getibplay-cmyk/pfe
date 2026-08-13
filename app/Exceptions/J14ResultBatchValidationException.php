<?php

namespace App\Exceptions;

use RuntimeException;

class J14ResultBatchValidationException extends RuntimeException
{
    public static function at(string $path, string $message): self
    {
        return new self($path.' : '.$message);
    }
}
