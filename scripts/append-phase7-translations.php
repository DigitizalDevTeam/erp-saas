<?php

$extra = [
    'Bulk update' => ['zh' => '批量更新', 'es' => 'Actualización masiva', 'fr' => 'Mise à jour groupée', 'pt' => 'Atualização em massa'],
    'Set status' => ['zh' => '设置状态', 'es' => 'Establecer estado', 'fr' => 'Définir le statut', 'pt' => 'Definir estado'],
    'Apply to selected' => ['zh' => '应用到所选', 'es' => 'Aplicar a seleccionados', 'fr' => 'Appliquer à la sélection', 'pt' => 'Aplicar aos seleccionados'],
    'Select at least one room.' => ['zh' => '请至少选择一个房间。', 'es' => 'Seleccione al menos una habitación.', 'fr' => 'Sélectionnez au moins une chambre.', 'pt' => 'Seleccione pelo menos um quarto.'],
    'Mark ready' => ['zh' => '标记可售', 'es' => 'Marcar lista', 'fr' => 'Marquer prête', 'pt' => 'Marcar pronto'],
    'Room :room is out of order (maintenance).' => ['zh' => '房间 :room 停用（维护中）。', 'es' => 'La habitación :room está fuera de servicio (mantenimiento).', 'fr' => 'La chambre :room est hors service (maintenance).', 'pt' => 'O quarto :room está fora de serviço (manutenção).'],
    'Room :room is being cleaned and cannot be assigned yet.' => ['zh' => '房间 :room 正在清洁，暂不可分配。', 'es' => 'La habitación :room se está limpiando y aún no puede asignarse.', 'fr' => 'La chambre :room est en nettoyage et ne peut pas encore être attribuée.', 'pt' => 'O quarto :room está a ser limpo e ainda não pode ser atribuído.'],
    'Room :room is already occupied.' => ['zh' => '房间 :room 已在住。', 'es' => 'La habitación :room ya está ocupada.', 'fr' => 'La chambre :room est déjà occupée.', 'pt' => 'O quarto :room já está ocupado.'],
    'Room :room has an in-house guest. Check out before changing status.' => ['zh' => '房间 :room 有在住客人，请先退房再更改状态。', 'es' => 'La habitación :room tiene un huésped en casa. Haga el check-out antes de cambiar el estado.', 'fr' => 'La chambre :room a un client en maison. Effectuez le départ avant de changer le statut.', 'pt' => 'O quarto :room tem hóspede em casa. Faça check-out antes de alterar o estado.'],
    'Room :room has no in-house guest. Use check-in to mark occupied.' => ['zh' => '房间 :room 无在住客人，请通过入住标记为在住。', 'es' => 'La habitación :room no tiene huésped en casa. Use el check-in para marcarla ocupada.', 'fr' => 'La chambre :room n\'a pas de client en maison. Utilisez l\'enregistrement pour la marquer occupée.', 'pt' => 'O quarto :room não tem hóspede em casa. Use o check-in para marcar como ocupado.'],
    'Only rooms in cleaning status can be marked ready.' => ['zh' => '仅清洁中的房间可标记为可售。', 'es' => 'Solo las habitaciones en limpieza pueden marcarse como listas.', 'fr' => 'Seules les chambres en nettoyage peuvent être marquées prêtes.', 'pt' => 'Apenas quartos em limpeza podem ser marcados como prontos.'],
    'Complete minibar restock before marking room ready.' => ['zh' => '标记可售前请完成迷你吧补货。', 'es' => 'Complete la reposición del minibar antes de marcar la habitación lista.', 'fr' => 'Terminez le réapprovisionnement minibar avant de marquer la chambre prête.', 'pt' => 'Conclua o reabastecimento do minibar antes de marcar o quarto pronto.'],
    'Room :room is ready for the next guest.' => ['zh' => '房间 :room 已可接待下一位客人。', 'es' => 'La habitación :room está lista para el próximo huésped.', 'fr' => 'La chambre :room est prête pour le prochain client.', 'pt' => 'O quarto :room está pronto para o próximo hóspede.'],
    ':count room(s) updated.' => ['zh' => '已更新 :count 个房间。', 'es' => ':count habitación(es) actualizada(s).', 'fr' => ':count chambre(s) mise(s) à jour.', 'pt' => ':count quarto(s) actualizado(s).'],
    'Some rooms could not be updated: :details' => ['zh' => '部分房间未能更新：:details', 'es' => 'Algunas habitaciones no se pudieron actualizar: :details', 'fr' => 'Certaines chambres n\'ont pas pu être mises à jour : :details', 'pt' => 'Alguns quartos não puderam ser actualizados: :details'],
    'Max guests' => ['zh' => '最多客人数', 'es' => 'Máximo de huéspedes', 'fr' => 'Nombre max. de clients', 'pt' => 'Máximo de hóspedes'],
    'Guest count exceeds room capacity.' => ['zh' => '客人数超过房间容量。', 'es' => 'El número de huéspedes supera la capacidad de la habitación.', 'fr' => 'Le nombre de clients dépasse la capacité de la chambre.', 'pt' => 'O número de hóspedes excede a capacidade do quarto.'],
    'Invalid room status.' => ['zh' => '无效房态。', 'es' => 'Estado de habitación no válido.', 'fr' => 'Statut de chambre invalide.', 'pt' => 'Estado de quarto inválido.'],
];

$dict = require __DIR__ . '/hotel-i18n-dict.php';
foreach ($extra as $en => $locales) {
    foreach ($locales as $locale => $value) {
        $dict[$locale][$en] = $value;
    }
}
file_put_contents(__DIR__ . '/hotel-i18n-dict.php', "<?php\n\nreturn " . var_export($dict, true) . ";\n");
echo "Appended " . count($extra) . " strings\n";
