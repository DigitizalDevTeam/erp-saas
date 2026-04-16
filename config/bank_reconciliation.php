<?php

return [

    'date_tolerance_days' => (int) env('BANK_RECONCILIATION_DATE_TOLERANCE', 3),

    'amount_epsilon' => 0.01,

    /*
    |--------------------------------------------------------------------------
    | Optional layout suggestions (auto-detect). Core flow is always manual mapping.
    | Add new banks by registering a Preset class implementing BankStatementPresetInterface.
    |--------------------------------------------------------------------------
    */
    'presets' => [
        'flexible' => [
            'label' => 'Any bank (manual column mapping)',
            'detector' => null,
        ],
        'bci_mozambique' => [
            'label' => 'Suggestion: BCI (Mozambique extrato-style)',
            'detector' => \App\Services\Accounting\BankReconciliation\Presets\BciMozambiquePreset::class,
        ],
        'standard_bank' => [
            'label' => 'Suggestion: Standard Bank-style (Date + Debit/Credit)',
            'detector' => \App\Services\Accounting\BankReconciliation\Presets\StandardBankPreset::class,
        ],
    ],
];
