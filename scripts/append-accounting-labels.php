<?php

$keys = [
    'en' => [
        'Saldo Devedor' => 'Debit Balance',
        'Saldo Credor' => 'Credit Balance',
        'Debito' => 'Debit',
        'Credito' => 'Credit',
        'State / Tax authorities' => 'State / Tax authorities',
        'Purchases / Inventory' => 'Purchases / Inventory',
        'Bank deposits' => 'Bank deposits',
        'Clients / Trade receivables' => 'Clients / Trade receivables',
        'Net results' => 'Net results',
        'Current / interim results' => 'Current / interim results',
        'Total Equity + Liabilities' => 'Total Equity + Liabilities',
        'Balance Difference (Assets - Equity - Liabilities)' => 'Balance Difference (Assets - Equity - Liabilities)',
    ],
    'pt' => [
        'Saldo Devedor' => 'Saldo Devedor',
        'Saldo Credor' => 'Saldo Credor',
        'Debito' => 'Débito',
        'Credito' => 'Crédito',
        'State / Tax authorities' => 'Estado / Autoridades fiscais',
        'Purchases / Inventory' => 'Compras / Inventários',
        'Bank deposits' => 'Depósitos bancários',
        'Clients / Trade receivables' => 'Clientes / Contas a receber',
        'Net results' => 'Resultados líquidos',
        'Current / interim results' => 'Resultados correntes / intercalares',
        'Total Equity + Liabilities' => 'Total Capital Próprio + Passivo',
        'Balance Difference (Assets - Equity - Liabilities)' => 'Diferença (Ativo - Capital - Passivo)',
    ],
];

foreach ($keys as $locale => $pairs) {
    $file = __DIR__ . "/../resources/lang/{$locale}.json";
    $data = json_decode(file_get_contents($file), true);
    foreach ($pairs as $k => $v) {
        $data[$k] = $v;
    }
    ksort($data, SORT_STRING);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    echo "{$locale}: ok\n";
}
