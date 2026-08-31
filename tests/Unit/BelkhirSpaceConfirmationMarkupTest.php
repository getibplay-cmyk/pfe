<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class BelkhirSpaceConfirmationMarkupTest extends TestCase
{
    #[Test]
    public function destructive_forms_use_the_shared_confirmation_dialog_and_keep_csrf_protection(): void
    {
        $protectedForms = 0;

        foreach ($this->bladeFiles() as $path) {
            $source = file_get_contents($path);

            $this->assertIsString($source, "Impossible de lire {$path}.");
            $this->assertDoesNotMatchRegularExpression(
                '/\bwindow\s*\.\s*confirm\s*\(/i',
                $source,
                "Une confirmation navigateur subsiste dans {$path}.",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/onsubmit\s*=\s*(["\']).*?\bconfirm\s*\(/i',
                $source,
                "Une confirmation inline subsiste dans {$path}.",
            );

            preg_match_all(
                '/<form\b(?=[^>]*\bx-belkhir-space-confirm\b)(?<attributes>[^>]*)>(?<body>.*?)<\/form>/si',
                $source,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $form) {
                $protectedForms++;
                $attributes = $form['attributes'];

                $this->assertMatchesRegularExpression('/\bmethod\s*=\s*(["\'])POST\1/i', $attributes, "Le formulaire confirmé doit rester en POST dans {$path}.");
                $this->assertStringContainsString('@csrf', $form['body'], "Le formulaire confirmé doit conserver @csrf dans {$path}.");

                foreach (['data-confirm-title', 'data-confirm-resource', 'data-confirm-consequence', 'data-confirm-label'] as $attribute) {
                    $this->assertStringContainsString($attribute.'=', $attributes, "Le contexte de confirmation {$attribute} manque dans {$path}.");
                }
            }
        }

        $this->assertGreaterThan(0, $protectedForms, 'Aucun formulaire BELKHIR SPACE protégé n’a été contrôlé.');
    }

    /**
     * @return list<string>
     */
    private function bladeFiles(): array
    {
        $root = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
