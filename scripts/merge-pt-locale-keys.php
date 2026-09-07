<?php

$enPath = __DIR__ . '/../resources/lang/en.json';
$ptPath = __DIR__ . '/../resources/lang/pt.json';
$en = json_decode(file_get_contents($enPath), true);
$pt = json_decode(file_get_contents($ptPath), true);
if (!is_array($en) || !is_array($pt)) {
    fwrite(STDERR, "Failed to decode JSON\n");
    exit(1);
}

$both = [
    'Sales Users' => 'Utilizadores de Vendas',
    'Sales Users & Clients' => 'Utilizadores de Vendas e Clientes',
    'Sales Users Authorization' => 'Autorização de Utilizadores de Vendas',
    'Product system' => 'Sistema de Produtos',
    'Product system reports' => 'Relatórios do sistema de produtos',
    'Store check' => 'Verificação de Loja',
    'Warehouse stock' => 'Stock de Armazém',
    'Last 5 supplies' => 'Últimos 5 abastecimentos',
    'Row & column check' => 'Verificação de linha e coluna',
    'Monthly Reports' => 'Relatórios Mensais',
    'Management Reports' => 'Relatórios de Gestão',
    'Analytical Reports' => 'Relatórios Analíticos',
    'VAT (Modelo A IVA)' => 'IVA (Modelo A IVA)',
    'Trial Balance (Balancete)' => 'Balancete',
    'IRPS (Modelo 19)' => 'IRPS (Modelo 19)',
    'IRPS Report (Modelo 19)' => 'Relatório IRPS (Modelo 19)',
    'IRPS / Payroll (Model 22)' => 'IRPS / Folha de Pagamento (Modelo 22)',
    'IRPS / Payroll report (Model 22)' => 'Relatório IRPS / Folha (Modelo 22)',
    'IRPS (Model 22)' => 'IRPS (Modelo 22)',
    'IRPC Payment Guide (Annual)' => 'Guia de Pagamento IRPC (Anual)',
    'IRPC Payment Guide' => 'Guia de Pagamento IRPC',
    'Income statement (comparative)' => 'Demonstração de resultados (comparativa)',
    'Credit Note Report' => 'Relatório de Notas de Crédito',
    'Debit Note Report' => 'Relatório de Notas de Débito',
    'PowerBlox - Management Pack' => 'PowerBlox - Pacote de Gestão',
    'PowerBlox' => 'PowerBlox',
    'PowerBlox - Analytical Report' => 'PowerBlox - Relatório Analítico',
    'PowerBlox management pack (Excel-based)' => 'Pacote de gestão PowerBlox (baseado em Excel)',
    'These reports follow the client Excel structure (PowerBlox).' => 'Estes relatórios seguem a estrutura Excel do cliente (PowerBlox).',
    'Search...' => 'Pesquisar...',
    '{select} entries per page' => '{select} registos por página',
    'No results match your search query' => 'Nenhum resultado corresponde à sua pesquisa',
    'Showing {start} to {end} of {rows} entries' => 'A mostrar {start} a {end} de {rows} registos',
    'Proforma Prefix' => 'Prefixo de Proforma',
    'Enter Proforma Prefix' => 'Introduza o prefixo de proforma',
    'Vendor Quote Prefix' => 'Prefixo de Cotação do Fornecedor',
    'Enter Vendor Quote Prefix' => 'Introduza o prefixo de cotação do fornecedor',
    'Credit Note Prefix' => 'Prefixo de Nota de Crédito',
    'Enter Credit Note Prefix' => 'Introduza o prefixo de nota de crédito',
    'Debit Note Prefix' => 'Prefixo de Nota de Débito',
    'Enter Debit Note Prefix' => 'Introduza o prefixo de nota de débito',
];

$ptOnly = [
    'Front Office' => 'Frente de Hotel',
    'Reception' => 'Receção',
    'Room Management' => 'Gestão de Quartos',
    'Room Map' => 'Mapa de Quartos',
    'Guest Map' => 'Mapa de Hóspedes',
    'Booking Calendar' => 'Calendário de Reservas',
    'Booking Site' => 'Site de Reservas',
    'Group Bookings' => 'Reservas de Grupo',
    'Sales / Booking' => 'Vendas / Reservas',
    'Daily Minibar' => 'Minibar Diário',
    'Hotel Accounting (COA)' => 'Contabilidade do Hotel (Plano de Contas)',
    'Hotel Expenses' => 'Despesas do Hotel',
    'OTA / Integrations' => 'OTA / Integrações',
    'Pricing Plans' => 'Planos de Preços',
    'Cashier Shifts' => 'Turnos de Caixa',
    'Add-ons & Extras' => 'Extras e Complementos',
    'Invalid user or PIN.' => 'Utilizador ou PIN inválido.',
    'June' => 'Junho',
    'No POS users configured. Ask your administrator to set POS PIN on staff accounts.' => 'Nenhum utilizador de POS configurado. Peça ao administrador para definir o PIN de POS nas contas da equipa.',
    'Select your name, enter POS PIN, and open Restaurant POS.' => 'Selecione o seu nome, introduza o PIN do POS e abra o POS de Restaurante.',
];

$addedEn = 0;
$addedPt = 0;
foreach ($both as $enKey => $ptVal) {
    if (!isset($en[$enKey])) {
        $en[$enKey] = $enKey;
        $addedEn++;
    }
    if (!isset($pt[$enKey])) {
        $pt[$enKey] = $ptVal;
        $addedPt++;
    } elseif ($pt[$enKey] === $enKey) {
        $pt[$enKey] = $ptVal;
    }
}
foreach ($ptOnly as $enKey => $ptVal) {
    if (!isset($pt[$enKey])) {
        $pt[$enKey] = $ptVal;
        $addedPt++;
    } elseif ($pt[$enKey] === $enKey) {
        $pt[$enKey] = $ptVal;
    }
}

ksort($en, SORT_NATURAL | SORT_FLAG_CASE);
ksort($pt, SORT_NATURAL | SORT_FLAG_CASE);
file_put_contents($enPath, json_encode($en, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents($ptPath, json_encode($pt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo "Added EN: {$addedEn}, Added/updated PT: {$addedPt}\n";
echo 'EN keys: ' . count($en) . ', PT keys: ' . count($pt) . "\n";
