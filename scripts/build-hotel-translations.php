<?php

/**
 * Build resources/lang/hotel-translations.json from hotel-i18n-dict.php
 * Usage: php scripts/build-hotel-translations.php
 */

$baseDir = __DIR__ . '/..';
$dict = require __DIR__ . '/hotel-i18n-dict.php';
$locales = ['en', 'zh', 'es', 'fr', 'pt'];

// Collect all English keys from dictionary
$keys = array_keys($dict['zh'] ?? []);
sort($keys, SORT_STRING);

$output = [];
foreach ($locales as $locale) {
    $output[$locale] = [];
    foreach ($keys as $key) {
        if ($locale === 'en') {
            $output[$locale][$key] = $key;
        } else {
            $output[$locale][$key] = $dict[$locale][$key] ?? $key;
        }
    }
}

$target = $baseDir . '/resources/lang/hotel-translations.json';
file_put_contents(
    $target,
    json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
);

echo 'Wrote ' . count($keys) . " keys x " . count($locales) . " locales to {$target}\n";
