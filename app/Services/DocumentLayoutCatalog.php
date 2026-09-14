<?php

namespace App\Services;

class DocumentLayoutCatalog
{
    public static function documentTypes(): array
    {
        return [
            'invoice' => __('Invoice'),
            'bill' => __('Bill'),
            'purchase' => __('Purchase'),
            'quotation' => __('Quotation'),
            'pos' => __('POS'),
        ];
    }

    /**
     * Fixed UTHARA Mozambique print pack labels (not drag-drop designer).
     */
    public static function utharaPresets(): array
    {
        return [
            'factura_a4' => __('UTHARA Factura A4'),
            'factura_a5' => __('UTHARA Factura A5'),
            'talao_80mm' => __('UTHARA Talão 80mm'),
            'mapa_inventario' => __('UTHARA Inventory Map'),
        ];
    }

    /**
     * Predefined blocks available in the designer.
     *
     * @return array<string, array{label: string, description: string, default_w: int, default_h: int}>
     */
    public static function blocks(): array
    {
        return [
            'company_header' => [
                'label' => __('Company header'),
                'description' => __('Company name and address'),
                'default_w' => 360,
                'default_h' => 90,
            ],
            'logo' => [
                'label' => __('Logo'),
                'description' => __('Document / company logo'),
                'default_w' => 160,
                'default_h' => 70,
            ],
            'party' => [
                'label' => __('Party details'),
                'description' => __('Customer or vendor block'),
                'default_w' => 340,
                'default_h' => 110,
            ],
            'document_meta' => [
                'label' => __('Document meta'),
                'description' => __('Number, dates, status'),
                'default_w' => 280,
                'default_h' => 100,
            ],
            'line_items' => [
                'label' => __('Line items'),
                'description' => __('Product / service table'),
                'default_w' => 740,
                'default_h' => 280,
            ],
            'totals' => [
                'label' => __('Totals'),
                'description' => __('Subtotal, tax, grand total'),
                'default_w' => 280,
                'default_h' => 120,
            ],
            'tax_breakdown' => [
                'label' => __('Tax breakdown'),
                'description' => __('VAT summary'),
                'default_w' => 280,
                'default_h' => 90,
            ],
            'bank_accounts' => [
                'label' => __('Bank account details'),
                'description' => __('Company bank accounts on the document'),
                'default_w' => 460,
                'default_h' => 120,
            ],
            'qr_code' => [
                'label' => __('QR code'),
                'description' => __('Optional QR code'),
                'default_w' => 100,
                'default_h' => 100,
            ],
            'notes' => [
                'label' => __('Notes'),
                'description' => __('Notes / terms'),
                'default_w' => 360,
                'default_h' => 80,
            ],
            'footer' => [
                'label' => __('Footer'),
                'description' => __('Footer notes'),
                'default_w' => 740,
                'default_h' => 50,
            ],
        ];
    }

    public static function bankFieldOptions(): array
    {
        return [
            'holder_name' => __('Holder name'),
            'bank_name' => __('Bank name'),
            'account_number' => __('Account number'),
            'branch' => __('Branch / address'),
            'swift' => __('SWIFT / contact'),
        ];
    }

    /**
     * Default Mozambique-like layout elements for a new layout.
     */
    public static function defaultElements(): array
    {
        $bankFields = array_keys(self::bankFieldOptions());

        return [
            self::el('logo', 30, 30, 150, 60),
            self::el('company_header', 200, 30, 360, 80),
            self::el('document_meta', 500, 30, 260, 100),
            self::el('party', 30, 130, 360, 110),
            self::el('line_items', 30, 260, 734, 300),
            self::el('tax_breakdown', 30, 580, 300, 100),
            self::el('totals', 480, 580, 284, 130),
            self::el('bank_accounts', 30, 730, 460, 130, true, [
                'limit' => 2,
                'fields' => $bankFields,
            ]),
            self::el('qr_code', 540, 730, 100, 100),
            self::el('notes', 30, 880, 400, 80),
            self::el('footer', 30, 1040, 734, 50),
        ];
    }

    protected static function el(
        string $key,
        int $x,
        int $y,
        int $w,
        int $h,
        bool $visible = true,
        array $options = []
    ): array {
        return [
            'id' => $key . '_' . substr(md5($key . $x . $y), 0, 8),
            'key' => $key,
            'x' => $x,
            'y' => $y,
            'w' => $w,
            'h' => $h,
            'visible' => $visible,
            'options' => $options,
        ];
    }
}
