<?php

namespace Tests\Unit;

use App\Models\ChartOfAccount;
use Tests\TestCase;

/**
 * Chart-of-account names must render in the user's language on every
 * page (bill/expense/payment/bank/category dropdowns, ledger filter,
 * view pages) — the locale-aware replacement for the raw English
 * CONCAT(code, ' - ', name) builders.
 */
class ChartOfAccountLocaleLabelTest extends TestCase
{
    private function account(array $attrs): ChartOfAccount
    {
        return new ChartOfAccount(array_merge([
            'code' => '42',
            'name' => 'Suppliers',
            'name_pt_br' => 'Fornecedores',
            'name_fr' => 'Fournisseurs',
            'name_es' => null,
            'name_zh' => null,
        ], $attrs));
    }

    public function test_portuguese_label_uses_pt_br_name(): void
    {
        $account = $this->account([]);

        $this->assertSame('Fornecedores', $account->labelForLocale('pt'));
        $this->assertSame('Fornecedores', $account->labelForLocale('pt-br'));
        $this->assertSame('42 - Fornecedores', $account->codeNameForLocale('pt'));
    }

    public function test_english_label_unchanged(): void
    {
        $account = $this->account([]);

        $this->assertSame('Suppliers', $account->labelForLocale('en'));
        $this->assertSame('42 - Suppliers', $account->codeNameForLocale('en'));
    }

    public function test_missing_translation_falls_back_to_english_name(): void
    {
        $account = $this->account(['name_es' => '']);

        // Spanish column empty -> English name, never blank.
        $this->assertSame('Suppliers', $account->labelForLocale('es'));
    }

    public function test_portuguese_only_account_never_renders_blank(): void
    {
        $account = $this->account(['name' => '']);

        // Portuguese-only chart account: any locale falls back to the
        // translated name instead of a blank label.
        $this->assertSame('Fornecedores', $account->labelForLocale('en'));
        $this->assertSame('42 - Fornecedores', $account->codeNameForLocale('en'));
    }
}
