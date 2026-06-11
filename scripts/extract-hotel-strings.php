<?php

error_reporting(E_ALL & ~E_WARNING);

$paths = [
    __DIR__ . '/../Modules/HotelManagement',
    __DIR__ . '/../resources/views/emails/hotel_reservation_created.blade.php',
    __DIR__ . '/../resources/views/role/partials/hotel_permissions.blade.php',
];

$strings = [];

function extractFromFile(string $path, array &$strings): void
{
    if (!is_file($path)) {
        return;
    }
    $content = file_get_contents($path);
    if (preg_match_all("/__\(\s*['\"]((?:[^'\\\\]|\\\\.)*)['\"]/", $content, $m)) {
        foreach ($m[1] as $s) {
            $strings[stripcslashes($s)] = true;
        }
    }
    if (preg_match_all("/@lang\(\s*['\"]((?:[^'\\\\]|\\\\.)*)['\"]/", $content, $m2)) {
        foreach ($m2[1] as $s) {
            $strings[stripcslashes($s)] = true;
        }
    }
}

foreach ($paths as $path) {
    if (is_dir($path)) {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($rii as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if (!preg_match('/\.(php|js)$/', $name)) {
                continue;
            }
            extractFromFile($file->getPathname(), $strings);
        }
    } else {
        extractFromFile($path, $strings);
    }
}

ksort($strings);
$keys = array_values(array_keys($strings));
$out = __DIR__ . '/hotel-strings.json';
file_put_contents($out, json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo count($keys) . " strings written to {$out}\n";
