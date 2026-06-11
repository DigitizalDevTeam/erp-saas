<?php

$extra = [
    'General' => ['zh' => '常规', 'es' => 'General', 'fr' => 'Général', 'pt' => 'Geral'],
    'Full hotel management' => ['zh' => '酒店完全管理权限', 'es' => 'Gestión hotelera completa', 'fr' => 'Gestion hôtelière complète', 'pt' => 'Gestão hoteleira completa'],
    'Hotel dashboard (view)' => ['zh' => '酒店仪表板（查看）', 'es' => 'Panel hotelero (ver)', 'fr' => 'Tableau de bord hôtel (consultation)', 'pt' => 'Painel hoteleiro (visualização)'],
    'Rooms & rates' => ['zh' => '客房与房价', 'es' => 'Habitaciones y tarifas', 'fr' => 'Chambres et tarifs', 'pt' => 'Quartos e tarifas'],
    'Rooms, types, floors, rate plans (manage)' => ['zh' => '客房、房型、楼层、价格方案（管理）', 'es' => 'Habitaciones, tipos, plantas, planes de tarifas (gestionar)', 'fr' => 'Chambres, types, étages, plans tarifaires (gestion)', 'pt' => 'Quartos, tipos, pisos, planos tarifários (gerir)'],
    'Rooms & setup (view)' => ['zh' => '客房与设置（查看）', 'es' => 'Habitaciones y configuración (ver)', 'fr' => 'Chambres et configuration (consultation)', 'pt' => 'Quartos e configuração (visualização)'],
    'Reservations & groups (manage)' => ['zh' => '预订与团队（管理）', 'es' => 'Reservas y grupos (gestionar)', 'fr' => 'Réservations et groupes (gestion)', 'pt' => 'Reservas e grupos (gerir)'],
    'Reservations & calendar (view)' => ['zh' => '预订与日历（查看）', 'es' => 'Reservas y calendario (ver)', 'fr' => 'Réservations et calendrier (consultation)', 'pt' => 'Reservas e calendário (visualização)'],
    'Front desk & folio' => ['zh' => '前台与账单', 'es' => 'Recepción y cuenta', 'fr' => 'Réception et folio', 'pt' => 'Receção e conta'],
    'Check-in/out, housekeeping, night audit (manage)' => ['zh' => '入住/退房、客房清洁、夜审（管理）', 'es' => 'Check-in/out, limpieza, auditoría nocturna (gestionar)', 'fr' => 'Enregistrement/départ, housekeeping, audit de nuit (gestion)', 'pt' => 'Check-in/out, limpeza, auditoria nocturna (gerir)'],
    'Front desk & housekeeping (view)' => ['zh' => '前台与客房清洁（查看）', 'es' => 'Recepción y limpieza (ver)', 'fr' => 'Réception et housekeeping (consultation)', 'pt' => 'Receção e limpeza (visualização)'],
    'Guest folio (view)' => ['zh' => '客人账单（查看）', 'es' => 'Cuenta de huésped (ver)', 'fr' => 'Folio client (consultation)', 'pt' => 'Conta de hóspede (visualização)'],
    'Force checkout (override)' => ['zh' => '强制退房（覆盖）', 'es' => 'Forzar check-out (anular)', 'fr' => 'Forcer le départ (outrepasser)', 'pt' => 'Forçar check-out (substituir)'],
];

$dictFile = __DIR__ . '/hotel-i18n-dict.php';
$dict = require $dictFile;

foreach ($extra as $en => $locales) {
    foreach ($locales as $locale => $value) {
        $dict[$locale][$en] = $value;
    }
}

$export = "<?php\n\nreturn " . var_export($dict, true) . ";\n";
file_put_contents($dictFile, $export);
echo 'Appended ' . count($extra) . " permission strings\n";
