<?php

namespace App\Services;

use App\Models\Utility;

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
     * @return array{templates: array<string, string>, template_sizes: array<string, string>}
     */
    public static function printTemplatesForType(string $documentType): array
    {
        $data = Utility::templateData(false);
        $templates = $data['templates'] ?? [];
        $sizes = $data['template_sizes'] ?? [];

        $builtIn = [];
        foreach ($templates as $key => $label) {
            if (str_starts_with((string) $key, 'layout_')) {
                continue;
            }
            $builtIn[$key] = $label;
        }

        return [
            'templates' => $builtIn,
            'template_sizes' => array_intersect_key($sizes, $builtIn),
        ];
    }

    /**
     * @return array{width: int, height: int}
     */
    public static function pageSizeForTemplate(string $templateKey): array
    {
        $data = Utility::templateData(false);
        $size = strtolower((string) (($data['template_sizes'][$templateKey] ?? 'a4')));

        if ($size === 'a5' || $templateKey === 'template13') {
            return ['width' => 559, 'height' => 794];
        }

        return ['width' => 794, 'height' => 1123];
    }

    /**
     * Alias used by designer — always template-specific.
     */
    public static function regionMapForTemplate(string $templateKey): array
    {
        return self::presetElementsForTemplate($templateKey);
    }

    public static function presetElementsForTemplate(string $templateKey): array
    {
        return match ($templateKey) {
            'template1' => self::cityPreset(1, 20, 20, 'left'),
            'template2' => self::cityPreset(2, 40, 30, 'right'),
            'template3' => self::cityPreset(3, 16, 24, 'center'),
            'template4' => self::cityPreset(4, 28, 18, 'left'),
            'template5' => self::cityPreset(5, 36, 40, 'right'),
            'template6' => self::cityPreset(6, 22, 28, 'left'),
            'template7' => self::cityPreset(7, 44, 22, 'center'),
            'template8' => self::cityPreset(8, 18, 36, 'right'),
            'template9' => self::cityPreset(9, 32, 16, 'left'),
            'template10' => self::cityPreset(10, 26, 32, 'center'),
            'template11' => self::mozambiqueLayoutElements(),
            'template12' => self::mozambiqueStandardA4Elements(),
            'template13' => self::mozambiqueStandardA5Elements(),
            default => self::defaultElements(),
        };
    }

    /**
     * Distinct city-template region maps (offsets differ so Template A ≠ Template B).
     *
     * @param  'left'|'right'|'center'  $metaSide
     */
    protected static function cityPreset(int $seed, int $ox, int $oy, string $metaSide): array
    {
        $bankFields = array_keys(self::bankFieldOptions());
        $pageW = 794;
        $logoW = 140 + ($seed % 3) * 10;
        $metaW = 220 + ($seed % 4) * 8;

        if ($metaSide === 'right') {
            $logoX = 24 + $ox;
            $headerX = $logoX + $logoW + 16;
            $metaX = $pageW - 24 - $metaW - $ox;
            $headerW = max(200, $metaX - $headerX - 16);
        } elseif ($metaSide === 'center') {
            $metaX = (int) (($pageW - $metaW) / 2);
            $logoX = 24 + $ox;
            $headerX = $logoX + $logoW + 12;
            $headerW = max(180, $metaX - $headerX - 12);
        } else {
            $logoX = 24 + $ox;
            $headerX = $logoX + $logoW + 16;
            $metaX = $pageW - 24 - $metaW;
            $headerW = max(200, $metaX - $headerX - 16);
        }

        $partyY = 120 + $oy;
        $itemsY = $partyY + 130;
        $taxY = $itemsY + 300;
        $bankY = $taxY + 120;
        $notesY = $bankY + 140;
        $footerY = min(1040, $notesY + 90);

        return [
            self::el('logo', $logoX, 20 + (int) ($oy / 2), $logoW, 65),
            self::el('company_header', $headerX, 20 + (int) ($oy / 2), $headerW, 80),
            self::el('document_meta', $metaX, 20 + (int) ($oy / 2), $metaW, 100),
            self::el('party', 24 + $ox, $partyY, 420 + ($seed % 5) * 10, 110),
            self::el('totals', 500 + ($seed % 3) * 8, $partyY, 270, 110),
            self::el('line_items', 24 + (int) ($ox / 2), $itemsY, 746 - $ox, 280),
            self::el('tax_breakdown', 24 + $ox, $taxY, 320, 100),
            self::el('bank_accounts', 24 + $ox, $bankY, 440, 120, true, [
                'limit' => 2,
                'fields' => $bankFields,
                'custom_text' => '',
            ]),
            self::el('qr_code', 520 + ($seed % 4) * 10, $bankY, 100, 100),
            self::el('notes', 24 + $ox, $notesY, 380, 70),
            self::el('footer', 24, $footerY, 746, 50),
        ];
    }

    public static function mozambiqueLayoutElements(): array
    {
        $bankFields = array_keys(self::bankFieldOptions());

        return [
            self::el('logo', 24, 20, 140, 70),
            self::el('company_header', 180, 20, 340, 80),
            self::el('document_meta', 540, 20, 230, 100),
            self::el('party', 24, 130, 500, 120),
            self::el('totals', 540, 130, 230, 110),
            self::el('line_items', 24, 270, 746, 280),
            self::el('tax_breakdown', 24, 570, 340, 100),
            self::el('bank_accounts', 24, 690, 460, 130, true, [
                'limit' => 2,
                'fields' => $bankFields,
                'custom_text' => '',
            ]),
            self::el('qr_code', 520, 690, 100, 100),
            self::el('notes', 24, 840, 400, 70),
            self::el('footer', 24, 940, 746, 50),
        ];
    }

    public static function isMozambiqueTheme(?string $templateKey): bool
    {
        return in_array((string) $templateKey, ['template11', 'template12', 'template13'], true);
    }

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

    public static function defaultElements(): array
    {
        return self::cityPreset(0, 30, 30, 'left');
    }

    public static function mozambiqueStandardA4Elements(): array
    {
        $bankFields = array_keys(self::bankFieldOptions());

        return [
            self::el('logo', 30, 30, 180, 70),
            self::el('company_header', 220, 30, 300, 90),
            self::el('document_meta', 540, 30, 224, 100),
            self::el('party', 30, 140, 400, 120),
            self::el('line_items', 30, 280, 734, 280),
            self::el('tax_breakdown', 30, 580, 320, 110),
            self::el('totals', 460, 580, 304, 140),
            self::el('bank_accounts', 30, 740, 480, 120, true, [
                'limit' => 2,
                'fields' => $bankFields,
                'custom_text' => '',
            ]),
            self::el('qr_code', 540, 740, 100, 100),
            self::el('notes', 30, 880, 420, 70),
            self::el('footer', 30, 1040, 734, 50),
        ];
    }

    public static function mozambiqueStandardA5Elements(): array
    {
        $bankFields = array_keys(self::bankFieldOptions());

        return [
            self::el('logo', 16, 16, 120, 50),
            self::el('company_header', 146, 16, 200, 60),
            self::el('document_meta', 360, 16, 180, 70),
            self::el('party', 16, 90, 250, 90),
            self::el('line_items', 16, 190, 527, 220),
            self::el('tax_breakdown', 16, 420, 240, 80),
            self::el('totals', 280, 420, 263, 100),
            self::el('bank_accounts', 16, 540, 340, 90, true, [
                'limit' => 2,
                'fields' => $bankFields,
                'custom_text' => '',
            ]),
            self::el('qr_code', 380, 540, 80, 80),
            self::el('notes', 16, 640, 300, 60),
            self::el('footer', 16, 720, 527, 40),
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
            'id' => $key . '_' . substr(md5($key . $x . $y . $w), 0, 8),
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
