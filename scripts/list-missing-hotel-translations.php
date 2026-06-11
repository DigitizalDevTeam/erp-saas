<?php

error_reporting(E_ALL & ~E_WARNING);

$baseDir = __DIR__ . '/..';
$strings = json_decode(file_get_contents($baseDir . '/scripts/hotel-strings.json'), true);
$locales = ['en', 'zh', 'es', 'fr', 'pt'];

// Filter out broken extraction artifacts
$strings = array_values(array_filter($strings, function ($s) {
    return !preg_match('/[\"\'\)]\s*\}\}/', $s)
        && !preg_match('/<\/(div|option)>/', $s)
        && strlen($s) < 200
        && $s !== '—'
        && $s !== 'ΓÇö'
        && $s !== 'João Mateus';
}));

foreach ($locales as $locale) {
    $langFile = $baseDir . "/resources/lang/{$locale}.json";
    $existing = json_decode(file_get_contents($langFile), true);
    $missing = [];
    foreach ($strings as $s) {
        if (!isset($existing[$s])) {
            $missing[] = $s;
        }
    }
    echo "{$locale}: " . count($missing) . " missing of " . count($strings) . PHP_EOL;
    if ($locale === 'en' && count($missing) > 0) {
        file_put_contents($baseDir . '/scripts/hotel-strings-missing.json', json_encode($missing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
