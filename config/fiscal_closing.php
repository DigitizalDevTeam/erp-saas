<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IRPC (corporate income tax) rate applied to profit on account 83
    |--------------------------------------------------------------------------
    */
    'irpc_rate' => (float) env('FISCAL_IRPC_RATE', 0.32),

    /*
    |--------------------------------------------------------------------------
    | Account code prefixes (numeric chart: first two digits = class)
    |--------------------------------------------------------------------------
    | Matches SNC-style codes stored as integers (e.g. 61102, 71101).
    */
    'classes' => [
        'operating_expense' => [61, 62, 63, 64, 65, 66, 67, 68],
        'operating_income' => [71, 72, 73, 74, 75, 76],
        'financial_expense' => [69],
        'financial_income' => [78],
    ],

    /*
    |--------------------------------------------------------------------------
    | Result and equity accounts (matched by code string prefix)
    |--------------------------------------------------------------------------
    */
    'accounts' => [
        'operating_results' => '81',
        'financial_results' => '82',
        'current_results' => '83',
        'current_tax' => '85',
        'net_profits' => '88',
        'income_tax_payable' => '44101',
        'vat_settlement' => '4435',
        'vat_collected' => '44331',
        'vat_payable' => '4437',
        'vat_recoverable' => '4438',
        'vat_deductible_inventories' => '44321',
        'vat_deductible_tangible' => '44322',
        'vat_deductible_services' => '44323',
    ],

    /*
    |--------------------------------------------------------------------------
    | Period 0 — opening balances (carry-forward from prior year)
    |--------------------------------------------------------------------------
    | First digit of account code = balance-sheet class carried to the new year.
    */
    'opening_balance_class_digits' => [1, 2, 3, 4, 5],

    'opening_reference_prefix' => 'OPEN',

    'manual_opening_reference_prefix' => 'OPEN-MAN',

];
