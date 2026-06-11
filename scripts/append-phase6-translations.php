<?php

$extra = [
    'Pre-audit checklist' => ['zh' => '夜审前检查清单', 'es' => 'Lista de verificación previa a la auditoría', 'fr' => 'Liste de contrôle pré-audit', 'pt' => 'Lista de verificação pré-auditoria'],
    'This business day is already closed.' => ['zh' => '该营业日已关闭。', 'es' => 'Este día hábil ya está cerrado.', 'fr' => 'Cette journée d\'activité est déjà clôturée.', 'pt' => 'Este dia de negócio já está fechado.'],
    'Open folios' => ['zh' => '未结账单', 'es' => 'Folios abiertos', 'fr' => 'Folios ouverts', 'pt' => 'Folios em aberto'],
    'Unpaid in-house bills' => ['zh' => '在住未付账单', 'es' => 'Cuentas en casa sin pagar', 'fr' => 'Factures en maison impayées', 'pt' => 'Contas em casa por pagar'],
    'All in-house folios are settled.' => ['zh' => '所有在住账单已结清。', 'es' => 'Todos los folios en casa están liquidados.', 'fr' => 'Tous les folios en maison sont réglés.', 'pt' => 'Todos os folios em casa estão liquidados.'],
    'Room status anomalies' => ['zh' => '房态异常', 'es' => 'Anomalías de estado de habitación', 'fr' => 'Anomalies de statut des chambres', 'pt' => 'Anomalias de estado do quarto'],
    'No room status mismatches.' => ['zh' => '无房态不一致。', 'es' => 'Sin discrepancias de estado de habitación.', 'fr' => 'Aucune incohérence de statut de chambre.', 'pt' => 'Sem inconsistências de estado do quarto.'],
    'Pending minibar restocks' => ['zh' => '待补货迷你吧', 'es' => 'Reposiciones minibar pendientes', 'fr' => 'Réapprovisionnements minibar en attente', 'pt' => 'Reabastecimentos minibar pendentes'],
    'Night audit report' => ['zh' => '夜审报表', 'es' => 'Informe de auditoría nocturna', 'fr' => 'Rapport d\'audit de nuit', 'pt' => 'Relatório de auditoria nocturna'],
    'Room marked occupied but no in-house stay.' => ['zh' => '房间标记为在住但无在住记录。', 'es' => 'Habitación marcada como ocupada pero sin estancia en casa.', 'fr' => 'Chambre marquée occupée sans séjour en maison.', 'pt' => 'Quarto marcado como ocupado mas sem estadia em casa.'],
    'In-house guest but room status is :status.' => ['zh' => '有在住客人但房态为 :status。', 'es' => 'Huésped en casa pero el estado de la habitación es :status.', 'fr' => 'Client en maison mais le statut de la chambre est :status.', 'pt' => 'Hóspede em casa mas o estado do quarto é :status.'],
    'Folio cash payments' => ['zh' => '账单现金付款', 'es' => 'Pagos en efectivo del folio', 'fr' => 'Paiements espèces du folio', 'pt' => 'Pagamentos em dinheiro do folio'],
    'Selected date' => ['zh' => '所选日期', 'es' => 'Fecha seleccionada', 'fr' => 'Date sélectionnée', 'pt' => 'Data seleccionada'],
    'POS cash' => ['zh' => 'POS 现金', 'es' => 'Efectivo POS', 'fr' => 'Espèces POS', 'pt' => 'Dinheiro POS'],
    'Cash total' => ['zh' => '现金合计', 'es' => 'Total en efectivo', 'fr' => 'Total espèces', 'pt' => 'Total em dinheiro'],
    'Open folio balance' => ['zh' => '未结账单余额', 'es' => 'Saldo de folios abiertos', 'fr' => 'Solde des folios ouverts', 'pt' => 'Saldo de folios em aberto'],
    'No folio payments on this date.' => ['zh' => '该日期无账单付款。', 'es' => 'No hay pagos de folio en esta fecha.', 'fr' => 'Aucun paiement de folio à cette date.', 'pt' => 'Sem pagamentos de folio nesta data.'],
];

$dict = require __DIR__ . '/hotel-i18n-dict.php';
foreach ($extra as $en => $locales) {
    foreach ($locales as $locale => $value) {
        $dict[$locale][$en] = $value;
    }
}
file_put_contents(__DIR__ . '/hotel-i18n-dict.php', "<?php\n\nreturn " . var_export($dict, true) . ";\n");
echo "Appended " . count($extra) . " strings\n";
