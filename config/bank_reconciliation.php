<?php

return [

    'date_tolerance_days' => (int) env('BANK_RECONCILIATION_DATE_TOLERANCE', 3),

    'amount_epsilon' => 0.01,

    /*
    |--------------------------------------------------------------------------
    | Built-in presets (extend by adding detectors in Presets/)
    |--------------------------------------------------------------------------
    */
    'presets' => [
        'bci_mozambique' => [
            'label' => 'BCI (Mozambique — Extrato)',
            'detector' => \App\Services\Accounting\BankReconciliation\Presets\BciMozambiquePreset::class,
        ],
        'standard_bank' => [
            'label' => 'Standard Bank (Debit/Credit columns)',
            'detector' => \App\Services\Accounting\BankReconciliation\Presets\StandardBankPreset::class,
        ],
        'custom' => [
            'label' => 'Custom (manual column mapping)',
            'detector' => null,
        ],
    ],
];
