<?php

/**
 * Merge hotel module translations into main locale JSON files.
 * Usage: php scripts/merge-hotel-translations.php
 */

$baseDir = __DIR__ . '/..';
$locales = ['en', 'zh', 'es', 'fr', 'pt'];
$hotelTranslationsFile = $baseDir . '/resources/lang/hotel-translations.json';

if (!is_file($hotelTranslationsFile)) {
    fwrite(STDERR, "Missing: resources/lang/hotel-translations.json\n");
    exit(1);
}

$hotelData = json_decode(file_get_contents($hotelTranslationsFile), true, 512, JSON_THROW_ON_ERROR);

foreach ($locales as $locale) {
    $langFile = $baseDir . "/resources/lang/{$locale}.json";
    if (!is_file($langFile)) {
        fwrite(STDERR, "Skip missing locale file: {$locale}.json\n");
        continue;
    }

    $existing = json_decode(file_get_contents($langFile), true, 512, JSON_THROW_ON_ERROR);
    $translations = $hotelData[$locale] ?? [];

    $added = 0;
    $updated = 0;
    foreach ($translations as $key => $value) {
        if (!isset($existing[$key])) {
            $existing[$key] = $value;
            $added++;
        } elseif ($existing[$key] !== $value && $locale !== 'en') {
            $existing[$key] = $value;
            $updated++;
        }
    }

    ksort($existing, SORT_STRING);
    file_put_contents(
        $langFile,
        json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
    );

    echo "{$locale}.json: added {$added}, updated {$updated}\n";
}

echo "Done.\n";
