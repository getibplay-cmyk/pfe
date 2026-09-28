<?php

namespace Tests\Unit;

use App\Rules\SafePassword;
use Illuminate\Container\Container;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

class PasswordInputSafetyTest extends TestCase
{
    public function test_null_bytes_are_rejected_before_any_hashing_or_external_request(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $container->instance('translator', new Translator(new ArrayLoader, 'fr'));
        Container::setInstance($container);
        try {
            $errors = [];
            // No hash service or HTTP client exists in this isolated fixture.
            (new SafePassword)->validate('password', "une phrase contenant\0un octet nul", function ($message) use (&$errors) {
                $errors[] = $message;
            });
            $this->assertSame(['Le mot de passe contient un caractère non autorisé.'], $errors);
        } finally {
            Container::setInstance($previous);
        }
    }
}
