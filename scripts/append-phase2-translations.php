<?php

$extra = [
    'Addons & extras' => ['zh' => '附加服务', 'es' => 'Complementos y extras', 'fr' => 'Suppléments et extras', 'pt' => 'Complementos e extras'],
    'Add addon' => ['zh' => '添加附加服务', 'es' => 'Añadir complemento', 'fr' => 'Ajouter un supplément', 'pt' => 'Adicionar complemento'],
    'Edit addon' => ['zh' => '编辑附加服务', 'es' => 'Editar complemento', 'fr' => 'Modifier le supplément', 'pt' => 'Editar complemento'],
    'Extra bed' => ['zh' => '加床', 'es' => 'Cama extra', 'fr' => 'Lit supplémentaire', 'pt' => 'Cama extra'],
    'Breakfast' => ['zh' => '早餐', 'es' => 'Desayuno', 'fr' => 'Petit-déjeuner', 'pt' => 'Pequeno-almoço'],
    'Parking' => ['zh' => '停车', 'es' => 'Aparcamiento', 'fr' => 'Parking', 'pt' => 'Estacionamento'],
    'Per night' => ['zh' => '每晚', 'es' => 'Por noche', 'fr' => 'Par nuit', 'pt' => 'Por noite'],
    'Per stay' => ['zh' => '每次住宿', 'es' => 'Por estancia', 'fr' => 'Par séjour', 'pt' => 'Por estadia'],
    'Taxable' => ['zh' => '应税', 'es' => 'Sujeto a impuestos', 'fr' => 'Taxable', 'pt' => 'Tributável'],
    'Addon created successfully.' => ['zh' => '附加服务创建成功。', 'es' => 'Complemento creado correctamente.', 'fr' => 'Supplément créé avec succès.', 'pt' => 'Complemento criado com sucesso.'],
    'Addon updated successfully.' => ['zh' => '附加服务更新成功。', 'es' => 'Complemento actualizado correctamente.', 'fr' => 'Supplément mis à jour avec succès.', 'pt' => 'Complemento atualizado com sucesso.'],
    'Addon deleted successfully.' => ['zh' => '附加服务删除成功。', 'es' => 'Complemento eliminado correctamente.', 'fr' => 'Supplément supprimé avec succès.', 'pt' => 'Complemento eliminado com sucesso.'],
    'Addon is linked to posted folio charges and cannot be deleted.' => ['zh' => '附加服务已计入账单，无法删除。', 'es' => 'El complemento está vinculado a cargos publicados y no puede eliminarse.', 'fr' => 'Le supplément est lié à des charges comptabilisées et ne peut pas être supprimé.', 'pt' => 'O complemento está associado a encargos na conta e não pode ser eliminado.'],
    'Run migrations to enable addons catalog.' => ['zh' => '请运行数据库迁移以启用附加服务目录。', 'es' => 'Ejecute las migraciones para habilitar el catálogo de complementos.', 'fr' => 'Exécutez les migrations pour activer le catalogue de suppléments.', 'pt' => 'Execute as migrações para ativar o catálogo de complementos.'],
    'No addons configured yet.' => ['zh' => '尚未配置附加服务。', 'es' => 'Aún no hay complementos configurados.', 'fr' => 'Aucun supplément configuré pour le moment.', 'pt' => 'Ainda não existem complementos configurados.'],
    'Select addon' => ['zh' => '选择附加服务', 'es' => 'Seleccionar complemento', 'fr' => 'Sélectionner un supplément', 'pt' => 'Selecionar complemento'],
    'Addon' => ['zh' => '附加服务', 'es' => 'Complemento', 'fr' => 'Supplément', 'pt' => 'Complemento'],
    'Posted to room bill' => ['zh' => '已计入房账', 'es' => 'Publicado en la cuenta', 'fr' => 'Comptabilisé sur le folio', 'pt' => 'Lançado na conta'],
    'Extra bed is billed per night. Other addons follow their catalog billing mode.' => ['zh' => '加床按每晚计费，其他附加服务按其目录计费方式。', 'es' => 'La cama extra se factura por noche. Otros complementos siguen su modo de facturación.', 'fr' => 'Le lit supplémentaire est facturé par nuit. Les autres suppléments suivent leur mode de facturation.', 'pt' => 'A cama extra é faturada por noite. Outros complementos seguem o modo de faturação do catálogo.'],
    'Extra bed is always billed per night.' => ['zh' => '加床始终按每晚计费。', 'es' => 'La cama extra siempre se factura por noche.', 'fr' => 'Le lit supplémentaire est toujours facturé par nuit.', 'pt' => 'A cama extra é sempre faturada por noite.'],
    'Guest count exceeds room capacity. Max :max (including :beds extra bed(s)).' => ['zh' => '入住人数超过客房容量。最多 :max 人（含 :beds 张加床）。', 'es' => 'El número de huéspedes supera la capacidad. Máx. :max (incl. :beds cama(s) extra).', 'fr' => 'Le nombre de clients dépasse la capacité. Max :max (dont :beds lit(s) supplémentaire(s)).', 'pt' => 'O número de hóspedes excede a capacidade. Máx. :max (incl. :beds cama(s) extra).'],
];

$dict = require __DIR__ . '/hotel-i18n-dict.php';
foreach ($extra as $en => $locales) {
    foreach ($locales as $locale => $value) {
        $dict[$locale][$en] = $value;
    }
}
file_put_contents(__DIR__ . '/hotel-i18n-dict.php', "<?php\n\nreturn " . var_export($dict, true) . ";\n");
echo "Appended " . count($extra) . " strings\n";
