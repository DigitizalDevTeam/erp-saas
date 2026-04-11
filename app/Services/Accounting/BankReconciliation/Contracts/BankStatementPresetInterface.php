<?php

namespace App\Services\Accounting\BankReconciliation\Contracts;

interface BankStatementPresetInterface
{
    /**
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<string, mixed>|null  Column mapping or null if not detected
     */
    public function detect(array $rows): ?array;
}
