<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guard test: every user-visible __() string used in app/ and resources/views/
 * must have a translation in EVERY non-English locale shipped in
 * resources/lang/*.json, and every file.key (pos.*, validation.*, ...) string
 * must exist in that locale's PHP lang files.
 *
 * Why this test exists: the header language dropdown offers 15 non-English
 * languages, but most locale files only had a handful of keys, so switching
 * language showed English (or a mix). New module strings were never added
 * to the non-English files at all.
 *
 * Deliberately skipped keys (documented, not translated):
 *  - keys with no letters at all ('0', '0.00', '#', '123456', '—'): numbers
 *    and symbols, not language.
 *  - group-prefix fragments from string concatenation, e.g. __('pos.' . $x)
 *    which statically extracts as 'pos.': there is no such translation key.
 *  - keys built dynamically at runtime, e.g. __($variable): they cannot be
 *    found by a static scan, so they are out of scope for this test.
 *  - log / audit internals are NOT wrapped in __() in the first place; if a
 *    string is wrapped in __() it is treated as user-visible by definition.
 */
class TranslationCompletenessTest extends TestCase
{
    /** PHP lang file groups (resources/lang/{locale}/{group}.php). */
    private const PHP_GROUPS = ['auth', 'errors', 'installer_messages', 'pagination', 'passwords', 'pos', 'validation'];

    /** Locales offered by the header dropdown (Utility::langList()), minus English. */
    private const LOCALES = ['ar', 'zh', 'da', 'de', 'es', 'fr', 'he', 'it', 'ja', 'nl', 'pl', 'pt', 'ru', 'tr', 'pt-br'];

    public function test_every_used_key_exists_in_every_locale(): void
    {
        [$jsonKeys, $phpKeys] = $this->usedKeys();

        $this->assertNotEmpty($jsonKeys, 'No __() keys found — the scanner is broken.');

        $failures = [];
        foreach (self::LOCALES as $locale) {
            $jsonPath = lang_path($locale . '.json');
            $this->assertFileExists($jsonPath, "Missing locale file: {$jsonPath}");
            $translations = json_decode((string) file_get_contents($jsonPath), true);
            $this->assertIsArray($translations, "Invalid JSON in {$jsonPath}");
            foreach ($jsonKeys as $key) {
                if (! array_key_exists($key, $translations) || $translations[$key] === '') {
                    $failures[$locale]['json'][] = $key;
                }
            }
            $phpLines = $this->flattenPhpLangFiles($locale);
            foreach ($phpKeys as $key) {
                if (! array_key_exists($key, $phpLines) || $phpLines[$key] === '') {
                    $failures[$locale]['php'][] = $key;
                }
            }
        }

        if (! empty($failures)) {
            $message = "Missing translations:\n";
            foreach ($failures as $locale => $groups) {
                foreach ($groups as $group => $keys) {
                    $sample = implode(', ', array_slice($keys, 0, 20));
                    $message .= "- {$locale} ({$group}): " . count($keys) . " missing, e.g. {$sample}\n";
                }
            }
            $this->fail($message);
        }
    }

    public function test_new_module_strings_resolve_to_portuguese(): void
    {
        // pt is the client's language: these must NOT fall back to English.
        app()->setLocale('pt');
        $this->assertNotSame('Kitchen Production', __('Kitchen Production'));
        $this->assertNotSame('Cost Centre', __('Cost Centre'));
        $this->assertNotSame('Treasury', __('Treasury'));
        $this->assertNotSame('Billing Contracts', __('Billing Contracts'));
        $this->assertNotSame('Fixed Assets', __('Fixed Assets'));
        $this->assertNotSame('Buffet Events', __('Buffet Events'));
        $this->assertNotSame('Daily Specials', __('Daily Specials'));
        $this->assertNotSame('Production Orders', __('Production Orders'));
        $this->assertNotSame('Internal Consumption', __('Internal Consumption'));
        $this->assertNotSame('Depreciation', __('Depreciation'));
        // Known-good pre-existing translations still resolve.
        $this->assertSame('Painel de Controle', __('Dashboard'));
        $this->assertSame('Permissão negada.', __('Permission denied.'));
        // A file.key (PHP group) string resolves in pt too.
        $this->assertNotSame('pos.add', __('pos.add'));
        $this->assertNotSame('Add closing balance and close shift', __('pos.add_closing_balance'));
    }

    /**
     * @return array{0: string[], 1: string[]} [jsonKeys, phpKeys]
     */
    private function usedKeys(): array
    {
        $jsonKeys = [];
        $phpKeys = [];
        foreach ([app_path(), resource_path('views')] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                foreach ($this->extractKeys($contents) as $key) {
                    $first = explode('.', $key, 2)[0];
                    if (str_contains($key, '.') && in_array($first, self::PHP_GROUPS, true)) {
                        $phpKeys[$key] = true;
                    } else {
                        $jsonKeys[$key] = true;
                    }
                }
            }
        }

        return [array_keys($jsonKeys), array_keys($phpKeys)];
    }

    /** Extract literal __()/trans()/@lang() keys from file contents. */
    private function extractKeys(string $contents): array
    {
        $keys = [];
        // Single- and double-quoted first arguments, with backslash escapes.
        preg_match_all(
            "/(?:__|trans|@lang|Lang::get|Lang::choice)\s*\(\s*(?:'((?:\\\\.|[^'\\\\])*)'|\"((?:\\\\.|[^\"\\\\])*)\")/",
            $contents,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $match) {
            $key = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
            // Double-quoted strings interpolate $variables: dynamic, skip.
            if (str_contains($key, '$')) {
                continue;
            }
            $key = str_replace(["\\'", '\\"'], ["'", '"'], $key);
            $key = str_replace('\\\\', '\\', $key);
            if ($this->isSkippable($key)) {
                continue;
            }
            $keys[] = $key;
        }

        return $keys;
    }

    private function isSkippable(string $key): bool
    {
        if ($key === '') {
            return true;
        }
        // No letters at all: numbers, symbols, punctuation.
        if (! preg_match('/[A-Za-z]/', $key)) {
            return true;
        }
        // Concatenation fragments like 'pos.' / 'validation.' (group prefix + dot).
        if (preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)*\.$/', $key)) {
            return true;
        }

        return false;
    }

    /** Load and flatten every PHP lang file for a locale: ['group.key' => value]. */
    private function flattenPhpLangFiles(string $locale): array
    {
        $flat = [];
        foreach (self::PHP_GROUPS as $group) {
            $path = lang_path($locale . '/' . $group . '.php');
            if (! file_exists($path)) {
                continue;
            }
            $data = require $path;
            if (is_array($data)) {
                $flat = array_merge($flat, $this->flatten($data, $group));
            }
        }

        return $flat;
    }

    private function flatten(array $data, string $prefix): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            $full = $prefix . '.' . $key;
            if (is_array($value)) {
                $flat = array_merge($flat, $this->flatten($value, $full));
            } else {
                $flat[$full] = $value;
            }
        }

        return $flat;
    }
}
