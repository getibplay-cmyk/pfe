<?php

namespace Tests\Unit;

use App\Support\Ui\BusinessNumber;
use App\Support\Ui\UiLabel;
use App\Support\Ui\UiText;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

class LocalizationCatalogTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function catalog(): array
    {
        return json_decode(file_get_contents($this->root().'/lang/ar.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_arabic_catalog_preserves_placeholders_and_covers_authored_messages(): void
    {
        $catalog = $this->catalog();
        $issues = [];
        foreach ($catalog as $key => $value) {
            if (! is_string($value) || trim($value) === '') {
                $issues[] = $key.' has no translation';

                continue;
            }
            preg_match_all('/:[A-Za-z][A-Za-z0-9_]*|%(?:\d+\$)?[dsf]/', $key, $source);
            preg_match_all('/:[A-Za-z][A-Za-z0-9_]*|%(?:\d+\$)?[dsf]/', $value, $target);
            sort($source[0]);
            sort($target[0]);
            if ($source[0] !== $target[0]) {
                $issues[] = $key.' changes placeholders';
            }
        }
        $required = [];
        foreach (['app', 'resources/views'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root().'/'.$directory));
            foreach ($files as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                preg_match_all('/(?:\b__|UiText::t)\(\s*(\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")/u', $source, $matches);
                foreach ($matches[1] as $literal) {
                    $key = strtr(substr($literal, 1, -1), ["\\'" => "'", '\\"' => '"', '\\\\' => '\\']);
                    if (! str_starts_with($key, 'auth.') && ! str_starts_with($key, 'passwords.')) {
                        $required[$key] = true;
                    }
                }
                $this->assertDoesNotMatchRegularExpression('/->(?:selectRaw|orderByRaw|format)\(\s*(?:__|[\\\\\w]*UiText::t)\(/', $source, $file->getPathname());
            }
        }
        $constants = (new ReflectionClass(UiLabel::class))->getConstants();
        foreach (['LABELS', 'ACTIONS', 'REPORT_LABELS', 'PERMISSION_GROUPS', 'PERMISSION_ENTITIES', 'PERMISSION_ACTIONS', 'SPECIAL_PERMISSIONS', 'ENTITIES'] as $constant) {
            foreach ($constants[$constant] as $label) {
                $required[$label] = true;
            }
        }
        foreach (array_diff_key($required, $catalog) as $key => $_) {
            $issues[] = $key.' is missing';
        }
        $this->assertSame([], $issues);
    }

    public function test_frontend_and_server_use_the_same_arabic_messages(): void
    {
        $source = file_get_contents($this->root().'/resources/js/arabic-messages.js');
        $json = substr($source, strpos($source, '{'));
        $messages = json_decode(rtrim(trim($json), ';'), true, flags: JSON_THROW_ON_ERROR);
        $catalog = $this->catalog();
        foreach ($messages as $key => $value) {
            $this->assertSame($catalog[$key] ?? null, $value, $key);
        }
        $this->assertArrayHasKey('Photo :value1', $messages);
    }

    public function test_locale_changes_labels_without_changing_money_or_business_values(): void
    {
        $previous = Container::getInstance();
        $container = new Container;
        $translator = new Translator(new FileLoader(new Filesystem, $this->root().'/lang'), 'fr');
        $container->instance('translator', $translator);
        Container::setInstance($container);
        try {
            $amount = BusinessNumber::money('1200.50', 'MAD');
            $this->assertSame('Confirmée', UiLabel::get('confirmed'));
            $translator->setLocale('ar');
            $this->assertSame('مؤكدة', UiLabel::get('confirmed'));
            $this->assertSame('2 مركبات', BusinessNumber::count(2, 'véhicule'));
            $this->assertSame($amount, BusinessNumber::money('1200.50', 'MAD'));
            $this->assertSame('Nom personnel inconnu', UiText::t('Nom personnel inconnu'));
            $this->assertSame('active', UiText::t('active'));
            $this->assertSame('غير مقروءة: 3', UiText::t(':count non lues', ['count' => 3]));
            $this->assertSame('add', UiLabel::buttonIcon('إضافة مركبة'));
            $this->assertSame('logout', UiLabel::buttonIcon('تسجيل الخروج'));
            $this->assertSame('reset', UiLabel::buttonIcon('Réinitialiser'));
        } finally {
            Container::setInstance($previous);
        }
    }
}
