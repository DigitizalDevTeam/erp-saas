<?php

namespace Database\Seeders;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceProduct;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\ProductServiceUnit;
use App\Models\Tax;
use App\Models\User;
use App\Models\Vender;
use App\Services\DocumentVoidService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * PT Invoice Traceability demo data for client screenshots.
 *
 * Default company: company@example.com (password: password)
 * Override: PT_SEED_COMPANY_EMAIL=your@email.com
 *
 * Run:
 *   php artisan db:seed --class=PtInvoiceTraceabilitySeeder
 */
class PtInvoiceTraceabilitySeeder extends Seeder
{
    private const MARKER = 'PT-TRACE-DEMO';

    public function run(): void
    {
        $email = env('PT_SEED_COMPANY_EMAIL', 'company@example.com');

        $company = User::where('email', $email)->where('type', 'company')->first();

        if (! $company) {
            $this->command?->error("Company user not found: {$email}");
            $this->command?->warn('Create it first (CompanyUserSeeder) or set PT_SEED_COMPANY_EMAIL.');

            return;
        }

        $creatorId = (int) $company->id;

        $company->lang = 'pt';
        $company->save();

        Auth::login($company);

        $this->upsertSettings($creatorId, [
            'company_name' => 'Demo PT Traceability Lda',
            'vat_number' => '400123456',
            'company_address' => 'Av. 25 de Setembro, Maputo, Moçambique',
            'company_city' => 'Maputo',
            'company_state' => 'Maputo',
            'company_country' => 'Moçambique',
            'company_zipcode' => '1100',
            'company_telephone' => '+258 21 000 000',
            'site_currency' => 'MZN',
            'site_currency_symbol' => 'MT',
            'vat_tax_name' => 'IVA',
            'invoice_prefix' => '#Fatura',
            'credit_note_prefix' => '#NC',
            'debit_note_prefix' => '#ND',
            'document_void_credit_note' => 'auto',
            'document_void_restore_stock' => 'off',
            'stock_deduction_timing' => 'none',
            'footer_title' => 'Demo PT Traceability',
            'footer_notes' => self::MARKER,
        ]);

        $tax = Tax::firstOrCreate(
            ['name' => 'IVA 16%', 'created_by' => $creatorId],
            ['rate' => 16]
        );

        $productCategory = ProductServiceCategory::firstOrCreate(
            ['name' => 'Serviços Demo PT', 'created_by' => $creatorId, 'type' => 'product & service'],
            []
        );

        $incomeCategory = ProductServiceCategory::firstOrCreate(
            ['name' => 'Receitas Demo PT', 'created_by' => $creatorId, 'type' => 'income'],
            []
        );

        $unit = ProductServiceUnit::firstOrCreate(
            ['name' => 'Unidade', 'created_by' => $creatorId],
            []
        );

        $customer = Customer::firstOrNew(
            ['email' => 'cliente.demo.pt@example.com', 'created_by' => $creatorId]
        );
        if (! $customer->exists) {
            $customer->customer_id = $this->nextCustomerNumber($creatorId);
        }
        $customer->fill([
            'name' => 'Cliente Demo Maputo Lda',
            'contact' => '840000001',
            'tax_number' => '400987654',
            'billing_name' => 'Cliente Demo Maputo Lda',
            'billing_country' => 'Moçambique',
            'billing_state' => 'Maputo',
            'billing_city' => 'Maputo',
            'billing_phone' => '840000001',
            'billing_zip' => '1100',
            'billing_address' => 'Rua da Resistência 100, Maputo',
            'shipping_name' => 'Cliente Demo Maputo Lda',
            'shipping_country' => 'Moçambique',
            'shipping_state' => 'Maputo',
            'shipping_city' => 'Maputo',
            'shipping_phone' => '840000001',
            'shipping_zip' => '1100',
            'shipping_address' => 'Rua da Resistência 100, Maputo',
            'payment_type' => 'Transferência Bancária',
            'is_active' => 1,
            'email_verified_at' => now(),
        ]);
        $customer->save();

        $supplier = Vender::firstOrNew(
            ['email' => 'fornecedor.demo.pt@example.com', 'created_by' => $creatorId]
        );
        if (! $supplier->exists) {
            $supplier->vender_id = $this->nextVenderNumber($creatorId);
            $supplier->password = Hash::make('password');
        }
        $supplier->fill([
            'name' => 'Fornecedor Demo Beira Lda',
            'contact' => '840000002',
            'tax_number' => '400555666',
            'billing_name' => 'Fornecedor Demo Beira Lda',
            'billing_country' => 'Moçambique',
            'billing_state' => 'Sofala',
            'billing_city' => 'Beira',
            'billing_phone' => '840000002',
            'billing_zip' => '2100',
            'billing_address' => 'Av. Samora Machel 50, Beira',
            'shipping_name' => 'Fornecedor Demo Beira Lda',
            'shipping_country' => 'Moçambique',
            'shipping_state' => 'Sofala',
            'shipping_city' => 'Beira',
            'shipping_phone' => '840000002',
            'shipping_zip' => '2100',
            'shipping_address' => 'Av. Samora Machel 50, Beira',
            'payment_type' => 'Numerário',
            'is_active' => 1,
            'email_verified_at' => now(),
        ]);
        $supplier->save();

        // tax_number already set above when column exists

        $product = ProductService::updateOrCreate(
            ['sku' => 'PT-DEMO-SRV-001', 'created_by' => $creatorId],
            [
                'name' => 'Consultoria ERP Demo PT',
                'sale_price' => 10000,
                'purchase_price' => 5000,
                'tax_id' => (string) $tax->id,
                'category_id' => $productCategory->id,
                'unit_id' => $unit->id,
                'type' => 'service',
                'affect_stock' => 0,
                'sale_chartaccount_id' => 0,
                'expense_chartaccount_id' => 0,
            ]
        );

        // Clean previous demo invoices/notes for this customer (idempotent re-run)
        $this->cleanupPreviousDemo($creatorId, (int) $customer->id);

        $issueDate = now()->toDateString();
        $dueDate = now()->addDays(30)->toDateString();

        // Invoice 1 — issued (Sent) — for create/issue/list/debit note screenshots
        $invoiceSent = $this->createInvoice(
            $creatorId,
            (int) $customer->id,
            (int) $incomeCategory->id,
            (int) $product->id,
            (string) $tax->id,
            Invoice::STATUS_SENT,
            $issueDate,
            $dueDate,
            'PT Trace demo — invoice issued',
            2,
            10000
        );

        // Invoice 2 — will be voided with auto credit note
        $invoiceToVoid = $this->createInvoice(
            $creatorId,
            (int) $customer->id,
            (int) $incomeCategory->id,
            (int) $product->id,
            (string) $tax->id,
            Invoice::STATUS_SENT,
            $issueDate,
            $dueDate,
            'PT Trace demo — invoice to cancel',
            1,
            10000
        );

        $voidLog = DocumentVoidService::voidInvoice($invoiceToVoid, [
            'reason' => 'Cancelamento de teste PT — documento emitido por engano ('.self::MARKER.')',
            'stock_mode' => 'off',
            'accounting_mode' => 'auto',
        ]);

        $invoiceToVoid->refresh();

        $creditNote = CreditNote::where('invoice', $invoiceToVoid->id)
            ->where('is_debit', 0)
            ->latest('id')
            ->first();

        // Sales debit note linked to issued invoice
        $exDebit = CreditNote::where(['created_by' => $creatorId, 'is_debit' => 1])->latest('id')->first();
        $debitUid = $exDebit ? ((int) $exDebit->uid + 1) : 1;

        $debitNote = new CreditNote();
        $debitNote->uid = $debitUid;
        $debitNote->invoice = $invoiceSent->id;
        $debitNote->customer = $customer->id;
        $debitNote->date = $issueDate;
        $debitNote->amount = 1160; // example adjustment amount
        $debitNote->description = 'Nota de Débito de teste PT — ajuste adicional ('.self::MARKER.')';
        $debitNote->created_by = $creatorId;
        $debitNote->is_debit = 1;
        $debitNote->save();

        $fmt = function (Invoice $inv) use ($company) {
            return $company->invoiceNumberFormat($inv->invoice_id);
        };

        $this->command?->info('=== PT Invoice Traceability seed complete ===');
        $this->command?->info("Login: {$email} / password");
        $this->command?->info('Language: pt');
        $this->command?->info('Company: Demo PT Traceability Lda | NUIT: 400123456');
        $this->command?->info('Customer: Cliente Demo Maputo Lda');
        $this->command?->info('Supplier: Fornecedor Demo Beira Lda');
        $this->command?->info('Product: Consultoria ERP Demo PT (10,000 MT + IVA 16%)');
        $this->command?->info('Issued invoice: '.$fmt($invoiceSent).' (status Sent)');
        $this->command?->info('Cancelled invoice: '.$fmt($invoiceToVoid).' (status Cancelada)');
        $this->command?->info('Credit Note id: '.($creditNote?->id ?? 'n/a').' | amount: '.($creditNote?->amount ?? 'n/a'));
        $this->command?->info('Sales Debit Note uid: '.$debitNote->uid.' | amount: '.$debitNote->amount);
        $this->command?->info('Void log id: '.$voidLog->id);
        $this->command?->info('See document/SCREENSHOT_GUIDE.txt for where to capture each image.');
    }

    private function upsertSettings(int $creatorId, array $pairs): void
    {
        $now = now()->toDateTimeString();

        foreach ($pairs as $name => $value) {
            DB::insert(
                'insert into settings (`value`, `name`, `created_by`, `created_at`, `updated_at`) values (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = VALUES(`updated_at`)',
                [(string) $value, $name, $creatorId, $now, $now]
            );
        }
    }

    private function nextCustomerNumber(int $creatorId): int
    {
        $latest = Customer::where('created_by', $creatorId)->max('customer_id');

        return $latest ? ((int) $latest + 1) : 1;
    }

    private function nextVenderNumber(int $creatorId): int
    {
        $latest = Vender::where('created_by', $creatorId)->max('vender_id');

        return $latest ? ((int) $latest + 1) : 1;
    }

    private function nextInvoiceNumber(int $creatorId): int
    {
        $latest = Invoice::where('created_by', $creatorId)->max('invoice_id');

        return $latest ? ((int) $latest + 1) : 1;
    }

    private function createInvoice(
        int $creatorId,
        int $customerId,
        int $categoryId,
        int $productId,
        string $taxId,
        int $status,
        string $issueDate,
        string $dueDate,
        string $ref,
        float $qty,
        float $price
    ): Invoice {
        $invoice = new Invoice();
        $invoice->invoice_id = $this->nextInvoiceNumber($creatorId);
        $invoice->customer_id = $customerId;
        $invoice->status = $status;
        $invoice->issue_date = $issueDate;
        $invoice->due_date = $dueDate;
        $invoice->category_id = $categoryId;
        $invoice->ref_number = $ref.' | '.self::MARKER;
        $invoice->created_by = $creatorId;
        $invoice->save();

        $line = new InvoiceProduct();
        $line->invoice_id = $invoice->id;
        $line->product_id = $productId;
        $line->quantity = $qty;
        $line->tax = $taxId;
        $line->discount = 0;
        $line->price = $price;
        $line->description = 'Linha de teste PT Traceability';
        $line->save();

        return $invoice->fresh('items');
    }

    private function cleanupPreviousDemo(int $creatorId, int $customerId): void
    {
        $oldInvoices = Invoice::where('created_by', $creatorId)
            ->where('customer_id', $customerId)
            ->where('ref_number', 'like', '%'.self::MARKER.'%')
            ->get();

        foreach ($oldInvoices as $inv) {
            CreditNote::where('invoice', $inv->id)->delete();
            InvoiceProduct::where('invoice_id', $inv->id)->delete();

            if (Schema::hasTable('document_void_logs')) {
                DB::table('document_void_logs')
                    ->where('document_type', 'invoice')
                    ->where('document_id', $inv->id)
                    ->delete();
            }

            $inv->delete();
        }

        CreditNote::where('created_by', $creatorId)
            ->where('description', 'like', '%'.self::MARKER.'%')
            ->delete();
    }
}
