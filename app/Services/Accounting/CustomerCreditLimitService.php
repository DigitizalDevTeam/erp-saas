<?php

namespace App\Services\Accounting;

use App\Models\Customer;
use App\Models\Invoice;

class CustomerCreditLimitService
{
    /**
     * Check whether releasing this invoice would exceed the customer's credit limits
     * (amount and/or overdue days).
     *
     * @return array{exceeded: bool, reasons: string[], outstanding: float, credit_limit: float, overdue_amount: float, projected: float}
     */
    public function evaluate(Customer $customer, float $additionalAmount = 0, ?int $excludeInvoiceId = null): array
    {
        $outstanding = $this->openReceivable($customer->id, (int) $customer->created_by, $excludeInvoiceId);
        $overdueAmount = $this->overdueReceivable($customer->id, (int) $customer->created_by, $excludeInvoiceId);
        $creditLimit = (float) ($customer->credit_limit ?? 0);
        $projected = round($outstanding + $additionalAmount, 2);

        $reasons = [];

        if ($creditLimit > 0 && $projected > $creditLimit) {
            $reasons[] = 'amount';
        }

        if ($overdueAmount > 0) {
            $reasons[] = 'days';
        }

        return [
            'exceeded' => count($reasons) > 0,
            'reasons' => $reasons,
            'outstanding' => round($outstanding, 2),
            'credit_limit' => $creditLimit,
            'overdue_amount' => round($overdueAmount, 2),
            'projected' => $projected,
        ];
    }

    public function reasonLabels(array $evaluation): array
    {
        $labels = [];
        foreach ($evaluation['reasons'] ?? [] as $reason) {
            if ($reason === 'amount') {
                $labels[] = __('Credit amount limit exceeded (outstanding + invoice :projected > limit :limit)', [
                    'projected' => number_format((float) $evaluation['projected'], 2),
                    'limit' => number_format((float) $evaluation['credit_limit'], 2),
                ]);
            }
            if ($reason === 'days') {
                $labels[] = __('Customer has overdue invoices (credit days exceeded)');
            }
        }

        return $labels;
    }

    protected function openReceivable(int $customerId, int $companyId, ?int $excludeInvoiceId = null): float
    {
        $query = Invoice::where('customer_id', $customerId)
            ->where('created_by', $companyId)
            ->whereIn('status', [1, 2, 3])
            ->with('payments');

        if ($excludeInvoiceId) {
            $query->where('id', '!=', $excludeInvoiceId);
        }

        $total = 0.0;
        foreach ($query->get() as $invoice) {
            $total += (float) $invoice->getDue();
        }

        return $total;
    }

    protected function overdueReceivable(int $customerId, int $companyId, ?int $excludeInvoiceId = null): float
    {
        $query = Invoice::where('customer_id', $customerId)
            ->where('created_by', $companyId)
            ->whereIn('status', [1, 2, 3])
            ->whereDate('due_date', '<', date('Y-m-d'))
            ->with('payments');

        if ($excludeInvoiceId) {
            $query->where('id', '!=', $excludeInvoiceId);
        }

        $total = 0.0;
        foreach ($query->get() as $invoice) {
            $due = (float) $invoice->getDue();
            if ($due > 0) {
                $total += $due;
            }
        }

        return $total;
    }
}
