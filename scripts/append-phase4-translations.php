<?php

$extra = [
    'Tax invoice PDF' => ['zh' => '税务发票 PDF', 'es' => 'Factura fiscal PDF', 'fr' => 'Facture fiscale PDF', 'pt' => 'Factura fiscal PDF'],
    'Could not generate tax invoice for this folio.' => ['zh' => '无法为此账单生成税务发票。', 'es' => 'No se pudo generar la factura fiscal para este folio.', 'fr' => 'Impossible de générer la facture fiscale pour ce folio.', 'pt' => 'Não foi possível gerar a factura fiscal para esta conta.'],
    'Hotel service' => ['zh' => '酒店服务', 'es' => 'Servicio hotelero', 'fr' => 'Service hôtelier', 'pt' => 'Serviço hoteleiro'],
    'ERP invoice' => ['zh' => 'ERP 发票', 'es' => 'Factura ERP', 'fr' => 'Facture ERP', 'pt' => 'Factura ERP'],
    'VAT rate' => ['zh' => '增值税率', 'es' => 'Tipo de IVA', 'fr' => 'Taux de TVA', 'pt' => 'Taxa de IVA'],
    'Line tax amounts and hotel accounting settings apply.' => ['zh' => '适用明细税额及酒店会计设置。', 'es' => 'Se aplican importes de impuestos por línea y la configuración contable del hotel.', 'fr' => 'Les montants de taxe par ligne et les paramètres comptables hôtel s\'appliquent.', 'pt' => 'Aplicam-se montantes de imposto por linha e as definições contabilísticas do hotel.'],
];

$dict = require __DIR__ . '/hotel-i18n-dict.php';
foreach ($extra as $en => $locales) {
    foreach ($locales as $locale => $value) {
        $dict[$locale][$en] = $value;
    }
}
file_put_contents(__DIR__ . '/hotel-i18n-dict.php', "<?php\n\nreturn " . var_export($dict, true) . ";\n");
echo "Appended " . count($extra) . " strings\n";
