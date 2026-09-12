<?php

namespace App\Support\Ui;

use Illuminate\Container\Container;

/** UI translation also works in standalone formatter tests without an application. */
final class UiText
{
    public static function t(string $message, array $replace = []): string
    {
        $container = Container::getInstance();
        if ($container->bound('translator')) {
            return (string) $container->make('translator')->get($message, $replace);
        }
        foreach ($replace as $key => $value) {
            $message = str_replace(':'.$key, (string) $value, $message);
        }

        return $message;
    }
}
