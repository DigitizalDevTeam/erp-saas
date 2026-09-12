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
    // Existing / menu accounting & sales
    'Sales Users' => 'Utilizadores de Vendas',
    'Sales Users & Clients' => 'Utilizadores de Vendas e Clientes',
    'Sales Users Authorization' => 'Autorização de Utilizadores de Vendas',
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

    // Product system — menu & hub titles
    'Product system' => 'Sistema de Produtos',
    'Product system reports' => 'Relatórios do sistema de produtos',
    'Store check' => 'Verificação de Loja',
    'Store Check' => 'Verificação de Loja',
    'Warehouse stock' => 'Stock de Armazém',
    'Total stock value' => 'Valor total do stock',
    'High-value stock movements' => 'Movimentos de stock de alto valor',
    'Out-of-stock products' => 'Produtos sem stock',
    'Minimum Stock Alert' => 'Alerta de stock mínimo',
    'Products near expiry' => 'Produtos próximos do vencimento',
    'Expired products' => 'Produtos expirados',
    'Product transactions' => 'Transacções de produto',
    'Last 5 supplies' => 'Últimos 5 abastecimentos',
    'Row & column check' => 'Verificação de linha e coluna',
    'Classic product stock log' => 'Registo clássico de stock de produto',
    'History' => 'Histórico',
    'Batch / Lot monitor' => 'Monitor de lote',

    // Product system — index card descriptions
    'Count physical stock by warehouse and date. Saved checks stay in history for later reference.' => 'Conte o stock físico por armazém e data. As verificações guardadas ficam no histórico para referência posterior.',
    'Quantity by warehouse with row / shelf from the product card.' => 'Quantidade por armazém com linha / prateleira do cartão do produto.',
    'Stock quantity with purchase and sale values, with and without VAT.' => 'Quantidade em stock com valores de compra e venda, com e sem IVA.',
    'Products with the highest sales rotation (quantity sold via invoice and POS).' => 'Produtos com maior rotação de vendas (quantidade vendida via factura e POS).',
    'Products and kits with stock equal to zero or below.' => 'Produtos e kits com stock igual a zero ou inferior.',
    'Products with stock equal to or less than their Low stock alert at value.' => 'Produtos com stock igual ou inferior ao valor de alerta de stock mínimo.',
    'Lots with remaining stock expiring within the selected number of days.' => 'Lotes com stock restante a expirar dentro do número de dias seleccionado.',
    'Lots whose expiry date has passed.' => 'Lotes cuja data de validade já passou.',
    'Purchase, sale, POS, manual, loss, and warehouse transfers (IN / OUT).' => 'Compra, venda, POS, manual, perda e transferências de armazém (ENTRADA / SAÍDA).',
    'Select a product to see recent purchases: supplier, unit price, date.' => 'Seleccione um produto para ver compras recentes: fornecedor, preço unitário, data.',
    'Products with row/shelf set, grouped by location.' => 'Produtos com linha/prateleira definida, agrupados por localização.',
    'Historical stock report lines (existing screen).' => 'Linhas históricas do relatório de stock (ecrã existente).',
    'Stock in warehouse bins that have a row and/or shelf set (from purchases or manual stock-in), plus products with location only on the product card when no bin line exists yet.' => 'Stock em contentores de armazém com linha e/ou prateleira definida (de compras ou entrada manual), mais produtos com localização apenas no cartão do produto quando ainda não existe linha de contentor.',

    // Filters & UI
    'All warehouses' => 'Todos os armazéns',
    'All Warehouses' => 'Todos os armazéns',
    'All categories' => 'Todas as categorias',
    'All products' => 'Todos os produtos',
    'All periods' => 'Todos os períodos',
    'Search name / SKU' => 'Pesquisar nome / SKU',
    'Search name / SKU / batch' => 'Pesquisar nome / SKU / lote',
    'Search ID / SKU / Description' => 'Pesquisar ID / SKU / Descrição',
    'Search…' => 'Pesquisar…',
    'Export CSV' => 'Exportar CSV',
    'Custom range' => 'Intervalo personalizado',
    'Week reference date' => 'Data de referência da semana',
    'Default threshold' => 'Limiar predefinido',
    '(for items without a per-product setting)' => '(para itens sem definição por produto)',
    'Filter row…' => 'Filtrar linha…',
    'Filter column…' => 'Filtrar coluna…',
    'Row contains' => 'Linha contém',
    'Shelf contains' => 'Prateleira contém',
    'e.g. A1' => 'ex. A1',
    'e.g. 3' => 'ex. 3',
    'location' => 'localização',
    'Days ahead' => 'Dias à frente',
    'Respect product expiry alert days' => 'Respeitar dias de alerta de validade do produto',
    'Remaining stock' => 'Stock restante',
    'With remaining stock' => 'Com stock restante',
    'Depleted' => 'Esgotado',
    'Invoice + POS' => 'Factura + POS',
    'Invoice (sale)' => 'Factura (venda)',
    'Sort by' => 'Ordenar por',
    'Sale value' => 'Valor de venda',
    'Top' => 'Top',
    'Movement type' => 'Tipo de movimento',
    'Loss / write-off' => 'Perda / abate',
    'Select product' => 'Seleccionar produto',
    'Last 5 purchase lines' => 'Últimas 5 linhas de compra',
    'Quick print' => 'Impressão rápida',
    'Default' => 'Predefinido',
    'days' => 'dias',
    'set dates' => 'definir datas',
    'Count notes, team, etc.' => 'Notas da contagem, equipa, etc.',
    'Save stock audit' => 'Guardar auditoria de stock',
    'Saved At' => 'Guardado em',

    // Table / column labels
    'Row' => 'Linha',
    'Column' => 'Coluna',
    'Shelf' => 'Prateleira',
    'Shelf number' => 'Número da prateleira',
    'System quantity' => 'Quantidade do sistema',
    'Physical quantity' => 'Quantidade física',
    'Difference' => 'Diferença',
    'Current Stock' => 'Stock actual',
    'Current stock' => 'Stock actual',
    'Sale Price' => 'Preço de venda',
    'Warehouse Stock' => 'Stock do armazém',
    'Low stock alert at' => 'Alerta de stock mínimo em',
    'Shortage' => 'Falta',
    'Batch / Lot' => 'Lote',
    'Batch' => 'Lote',
    'Batch No' => 'Nº do lote',
    'Expiry date' => 'Data de validade',
    'Expiry Date' => 'Data de validade',
    'MFG Date' => 'Data de fabrico',
    'Days left' => 'Dias restantes',
    'Days overdue' => 'Dias em atraso',
    'Qty remaining' => 'Qtd. restante',
    'Alert days' => 'Dias de alerta',
    'Purchase Value (without VAT)' => 'Valor de compra (sem IVA)',
    'Purchase Value (with VAT)' => 'Valor de compra (com IVA)',
    'Sale Value (without VAT)' => 'Valor de venda (sem IVA)',
    'Sale Value (with VAT)' => 'Valor de venda (com IVA)',
    'QTY' => 'QTD',
    'ID' => 'ID',
    'Rank' => 'Posição',
    'Qty sold' => 'Qtd. vendida',
    'Movements' => 'Movimentos',
    'Est. sale value' => 'Valor est. de venda',
    'Rotation' => 'Rotação',
    'IN / OUT' => 'ENTRADA / SAÍDA',
    'IN' => 'ENTRADA',
    'OUT' => 'SAÍDA',
    'Purchase #' => 'Compra nº',
    'Line total' => 'Total da linha',
    'Unit price' => 'Preço unitário',
    'Warehouse bins' => 'Contentores do armazém',
    'Qty in bin' => 'Qtd. no contentor',
    'System qty' => 'Qtd. do sistema',
    'Product card location only' => 'Apenas localização do cartão do produto',
    'Product card location only (global qty)' => 'Apenas localização do cartão do produto (qtd. global)',
    'SKU' => 'SKU',
    'Total' => 'Total',
    'Subtotal' => 'Subtotal',
    'Manual' => 'Manual',
    'Item' => 'Item',
    'Serial / MAC' => 'Serial / MAC',

    // Empty / flash / document strings
    'No warehouse stock rows.' => 'Sem linhas de stock de armazém.',
    'No stock found for the selected filters.' => 'Nenhum stock encontrado para os filtros seleccionados.',
    'No out-of-stock products found.' => 'Nenhum produto sem stock encontrado.',
    'No products at or below the minimum stock alert.' => 'Nenhum produto no ou abaixo do alerta de stock mínimo.',
    'No products near expiry for the selected filters.' => 'Nenhum produto próximo do vencimento para os filtros seleccionados.',
    'No expired products found.' => 'Nenhum produto expirado encontrado.',
    'No sales movements found for the selected period.' => 'Nenhum movimento de venda no período seleccionado.',
    'No movements for the selected filters.' => 'Sem movimentos para os filtros seleccionados.',
    'No purchase history for this product.' => 'Sem histórico de compras para este produto.',
    'No warehouse stock with row or shelf. Add row/shelf on a purchase line or when adding product stock.' => 'Sem stock de armazém com linha ou prateleira. Adicione linha/prateleira numa linha de compra ou ao adicionar stock de produto.',
    'Stock audit saved successfully.' => 'Auditoria de stock guardada com sucesso.',
    'Invalid product selection.' => 'Selecção de produto inválida.',
    'Please select a product.' => 'Por favor seleccione um produto.',
    'Product not found.' => 'Produto não encontrado.',
    'Transfer in' => 'Transferência de entrada',
    'Transfer out' => 'Transferência de saída',
    'Warehouse transfer' => 'Transferência de armazém',
    'Warehouse transfers' => 'Transferências de armazém',
    'Purchase detail' => 'Detalhe da compra',
    'Open purchase' => 'Abrir compra',
    'Bill detail' => 'Detalhe da factura de compra',
    'Open bill' => 'Abrir factura',
    'POS sale detail' => 'Detalhe da venda POS',
    'Linked invoice' => 'Factura associada',
    'Invoice detail' => 'Detalhe da factura',
    'Open in Sales' => 'Abrir em Vendas',
    'Issue date' => 'Data de emissão',
    'Low stock is checked per warehouse against each product\'s minimum stock level.' => 'O stock baixo é verificado por armazém em relação ao nível mínimo de stock de cada produto.',

    // Recent features — purchase proforma / vendor quote
    'Convert to purchase order' => 'Converter em encomenda de compra',
    'View purchase order' => 'Ver encomenda de compra',
    'Convert to proforma' => 'Converter em proforma',
    'Proforma from vendor quote' => 'Proforma a partir da cotação do fornecedor',
    'Create proforma invoice' => 'Criar factura proforma',
    'Create a proforma invoice (agreed price) from this vendor quote. Choose the customer who will receive the proforma. You can still convert this vendor quote to a purchase order separately.' => 'Crie uma factura proforma (preço acordado) a partir desta cotação do fornecedor. Escolha o cliente que receberá a proforma. Ainda pode converter esta cotação numa encomenda de compra separadamente.',
    'Vendor Quote' => 'Cotação do fornecedor',
    'Vendor quote' => 'Cotação do fornecedor',
    'Vendor quotes' => 'Cotações do fornecedor',
    'Quote date' => 'Data da cotação',
    'Quote number' => 'Número da cotação',
    'Quote ID' => 'ID da cotação',
    'Purchase Order' => 'Encomenda de compra',

    // warehouse-transfer / loss / productstock
    'Issued By' => 'Emitido por',
    'Max in source' => 'Máx. na origem',
    'Products to transfer' => 'Produtos a transferir',
    'Transfer several products in one go. Choose source and destination warehouses once, then add lines.' => 'Transfira vários produtos de uma vez. Escolha os armazéns de origem e destino uma vez e adicione linhas.',
    'Stock by Variant' => 'Stock por variante',
    'Variant' => 'Variante',
    'Stock is counted in:' => 'O stock é contado em:',
    'Enter one per line' => 'Introduza um por linha',
    'Enter quantities to add to stock for each variant.' => 'Introduza quantidades a adicionar ao stock para cada variante.',
    'Optional. Used for expiry alerts.' => 'Opcional. Usado para alertas de validade.',
    'Required: exactly N identifiers where N = quantity. No duplicates allowed.' => 'Obrigatório: exactamente N identificadores onde N = quantidade. Sem duplicados.',
    'MAC Address List' => 'Lista de endereços MAC',
    'Serial/MAC List' => 'Lista Serial/MAC',
    'Serial Number List' => 'Lista de números de série',

    // Other menu leftovers found in admin menu scan
    'Bank reconciliation' => 'Reconciliação bancária',
    'Fiscal year closing' => 'Fecho do ano fiscal',
    'Email Logs' => 'Registos de email',
    'Lead' => 'Lead',
    'Leads' => 'Leads',
    'POS ' => 'POS ',
    'POS by Branch' => 'POS por Filial',
    'Tax report' => 'Relatório de impostos',
    'Void Audit Log' => 'Registo de anulações',
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
$updatedPt = 0;
foreach ($both as $enKey => $ptVal) {
    if (!isset($en[$enKey])) {
        $en[$enKey] = $enKey;
        $addedEn++;
    }
    if (!isset($pt[$enKey])) {
        $pt[$enKey] = $ptVal;
        $addedPt++;
    } elseif ($pt[$enKey] === $enKey && $ptVal !== $enKey) {
        $pt[$enKey] = $ptVal;
        $updatedPt++;
    }
}
foreach ($ptOnly as $enKey => $ptVal) {
    if (!isset($pt[$enKey])) {
        $pt[$enKey] = $ptVal;
        $addedPt++;
    } elseif ($pt[$enKey] === $enKey && $ptVal !== $enKey) {
        $pt[$enKey] = $ptVal;
        $updatedPt++;
    }
}

ksort($en, SORT_NATURAL | SORT_FLAG_CASE);
ksort($pt, SORT_NATURAL | SORT_FLAG_CASE);
file_put_contents($enPath, json_encode($en, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents($ptPath, json_encode($pt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo "Added EN: {$addedEn}, Added PT: {$addedPt}, Updated PT (was English): {$updatedPt}\n";
echo 'EN keys: ' . count($en) . ', PT keys: ' . count($pt) . "\n";
