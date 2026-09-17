<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\DocumentLayout;
use App\Models\Invoice;
use App\Models\Pos;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\Utility;
use App\Services\DocumentLayoutCatalog;
use App\Services\DocumentLayoutRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DocumentLayoutController extends Controller
{
    protected function authorizeLayouts(): void
    {
        $user = \Auth::user();
        if (
            $user->can('manage document layouts')
            || $user->can('menu.accounting_system.layout_management')
            || $user->can('manage print settings')
            || $user->type === 'company'
        ) {
            return;
        }
        abort(403, __('Permission Denied.'));
    }

    protected function creatorId(): int
    {
        return (int) \Auth::user()->creatorId();
    }

    public function index(Request $request)
    {
        $this->authorizeLayouts();

        $creatorId = $this->creatorId();
        $types = DocumentLayoutCatalog::documentTypes();
        $type = $request->input('document_type', 'invoice');
        if (! array_key_exists($type, $types)) {
            $type = 'invoice';
        }

        // Soft-hide orphan drafts (empty base_template / layout_* only) from Manage targets
        $layouts = DocumentLayout::query()
            ->where('created_by', $creatorId)
            ->where('document_type', $type)
            ->where(function ($q) {
                $q->whereNotNull('base_template')
                    ->where('base_template', '!=', '')
                    ->where(function ($q2) {
                        $q2->whereNull('print_template_key')
                            ->orWhere('print_template_key', '')
                            ->orWhere('print_template_key', 'not like', 'layout_%');
                    });
            })
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $printTemplates = DocumentLayoutCatalog::printTemplatesForType($type);
        $selectedTemplate = (string) $request->input('base_template', '');

        $existingByBase = [];
        foreach ($layouts as $layout) {
            $key = (string) ($layout->print_template_key ?: $layout->base_template);
            if ($key !== '' && ! str_starts_with($key, 'layout_') && empty($existingByBase[$key])) {
                $existingByBase[$key] = route('document-layouts.edit', $layout->id);
            }
        }

        $previewColor = $this->printColorForType($type);

        return view('document_layouts.index', compact(
            'layouts',
            'types',
            'type',
            'printTemplates',
            'selectedTemplate',
            'existingByBase',
            'previewColor'
        ));
    }

    public function create(Request $request)
    {
        $this->authorizeLayouts();

        $types = DocumentLayoutCatalog::documentTypes();
        $type = $request->input('document_type', 'invoice');
        if (! array_key_exists($type, $types)) {
            $type = 'invoice';
        }

        $baseTemplate = (string) $request->input('base_template', 'template11');
        $printCatalog = DocumentLayoutCatalog::printTemplatesForType($type);
        if ($baseTemplate !== '' && ! array_key_exists($baseTemplate, $printCatalog['templates'])) {
            $baseTemplate = array_key_first($printCatalog['templates']) ?: 'template11';
        }

        $existing = DocumentLayout::forBaseTemplate($type, $this->creatorId(), $baseTemplate);
        if ($existing) {
            return redirect()->route('document-layouts.edit', $existing->id);
        }

        $page = DocumentLayoutCatalog::pageSizeForTemplate($baseTemplate);
        $templateLabel = $printCatalog['templates'][$baseTemplate] ?? $baseTemplate;

        $layout = new DocumentLayout([
            'document_type' => $type,
            'name' => $templateLabel,
            'base_template' => $baseTemplate,
            'print_template_key' => $baseTemplate,
            'page_width' => $page['width'],
            'page_height' => $page['height'],
            'elements' => DocumentLayoutCatalog::presetElementsForTemplate($baseTemplate),
            'is_active' => false,
        ]);

        $blocks = DocumentLayoutCatalog::blocks();
        $bankFields = DocumentLayoutCatalog::bankFieldOptions();
        $printTemplates = $printCatalog;
        $designerPayload = $this->designerPayload($type);
        $previewColor = $this->printColorForType($type);
        $bladePreviewUrls = $this->bladePreviewUrlMap($type, $previewColor);

        return view('document_layouts.edit', compact(
            'layout',
            'types',
            'blocks',
            'bankFields',
            'printTemplates',
            'designerPayload',
            'previewColor',
            'bladePreviewUrls'
        ));
    }

    public function store(Request $request)
    {
        $this->authorizeLayouts();

        $data = $this->validated($request);
        $creatorId = $this->creatorId();
        $baseTemplate = (string) ($data['base_template'] ?: 'template11');
        $catalog = DocumentLayoutCatalog::printTemplatesForType($data['document_type']);
        $label = $catalog['templates'][$baseTemplate] ?? $data['name'];

        // Prefer updating existing override for this base template
        $existing = DocumentLayout::forBaseTemplate($data['document_type'], $creatorId, $baseTemplate);
        if ($existing) {
            return $this->update($request, $existing->id);
        }

        $layout = DocumentLayout::create([
            'created_by' => $creatorId,
            'document_type' => $data['document_type'],
            'name' => $label,
            'base_template' => $baseTemplate,
            'print_template_key' => $baseTemplate,
            'page_width' => $data['page_width'],
            'page_height' => $data['page_height'],
            'elements' => $data['elements'],
            'is_active' => false,
        ]);

        $this->finalizeLayoutSave($layout, $request);

        return redirect()
            ->route('document-layouts.edit', $layout->id)
            ->with('success', __('Layout saved successfully.'));
    }

    public function edit($id)
    {
        $this->authorizeLayouts();

        $layout = DocumentLayout::where('created_by', $this->creatorId())->findOrFail($id);
        $this->bindPrintIdentity($layout);
        $layout->refresh();

        $base = (string) ($layout->base_template ?: 'template11');
        if (count($layout->elements ?? []) < 6) {
            $layout->elements = DocumentLayoutCatalog::presetElementsForTemplate($base);
            $page = DocumentLayoutCatalog::pageSizeForTemplate($base);
            $layout->page_width = $page['width'];
            $layout->page_height = $page['height'];
            $layout->save();
        }

        $types = DocumentLayoutCatalog::documentTypes();
        $blocks = DocumentLayoutCatalog::blocks();
        $bankFields = DocumentLayoutCatalog::bankFieldOptions();
        $printTemplates = DocumentLayoutCatalog::printTemplatesForType($layout->document_type);
        $designerPayload = $this->designerPayload($layout->document_type);
        $previewColor = $this->printColorForType($layout->document_type);
        $bladePreviewUrls = $this->bladePreviewUrlMap($layout->document_type, $previewColor);

        return view('document_layouts.edit', compact(
            'layout',
            'types',
            'blocks',
            'bankFields',
            'printTemplates',
            'designerPayload',
            'previewColor',
            'bladePreviewUrls'
        ));
    }

    public function update(Request $request, $id)
    {
        $this->authorizeLayouts();

        $layout = DocumentLayout::where('created_by', $this->creatorId())->findOrFail($id);
        $data = $this->validated($request, $layout->id);
        $baseTemplate = (string) ($data['base_template'] ?: ($layout->base_template ?: 'template11'));
        $catalog = DocumentLayoutCatalog::printTemplatesForType($data['document_type']);
        $label = $catalog['templates'][$baseTemplate] ?? $data['name'];

        $layout->update([
            'document_type' => $data['document_type'],
            'name' => $label,
            'base_template' => $baseTemplate,
            'print_template_key' => $baseTemplate,
            'page_width' => $data['page_width'],
            'page_height' => $data['page_height'],
            'elements' => $data['elements'],
        ]);

        $this->finalizeLayoutSave($layout->fresh(), $request);

        return redirect()
            ->route('document-layouts.edit', $layout->id)
            ->with('success', __('Layout updated successfully.'));
    }

    public function destroy($id)
    {
        $this->authorizeLayouts();

        $layout = DocumentLayout::where('created_by', $this->creatorId())->findOrFail($id);
        $type = $layout->document_type;
        $layout->delete();

        return redirect()
            ->route('document-layouts.index', ['document_type' => $type])
            ->with('success', __('Layout deleted successfully.'));
    }

    public function activate($id)
    {
        $this->authorizeLayouts();

        $layout = DocumentLayout::where('created_by', $this->creatorId())->findOrFail($id);
        $this->bindPrintIdentity($layout);
        $this->cleanupDuplicateLayoutKeys($layout);
        $this->syncToPrintSettings($layout, true);

        return redirect()
            ->route('document-layouts.index', ['document_type' => $layout->document_type])
            ->with('success', __('Layout activated. Print Settings still uses the same template key.'));
    }

    public function deactivate($id)
    {
        $this->authorizeLayouts();

        $layout = DocumentLayout::where('created_by', $this->creatorId())->findOrFail($id);
        $layout->update(['is_active' => false]);

        return redirect()
            ->route('document-layouts.index', ['document_type' => $layout->document_type])
            ->with('success', __('Layout deactivated. Default Blade templates will be used unless another custom template is selected in Print Settings.'));
    }

    public function preview(Request $request, $id = null)
    {
        $this->authorizeLayouts();

        $creatorId = $this->creatorId();

        if ($id) {
            $layout = DocumentLayout::where('created_by', $creatorId)->findOrFail($id);
        } else {
            $type = $request->input('document_type', 'invoice');
            $elements = json_decode($request->input('elements', '[]'), true) ?: DocumentLayoutCatalog::defaultElements();
            $layout = new DocumentLayout([
                'document_type' => $type,
                'name' => __('Preview'),
                'page_width' => (int) $request->input('page_width', 794),
                'page_height' => (int) $request->input('page_height', 1123),
                'elements' => $elements,
                'created_by' => $creatorId,
            ]);
        }

        $document = $this->sampleDocument($layout->document_type, $creatorId);
        if (! $document) {
            return response('<p style="padding:2rem;font-family:sans-serif;">' . e(__('No sample document found for preview. Create one first.')) . '</p>');
        }

        $settings = Utility::settingsById($creatorId);
        $payload = DocumentLayoutRenderer::buildPayload($layout->document_type, $document, [
            'settings' => $settings,
            'preview' => true,
        ]);

        return view('document_layouts.render', [
            'layout' => $layout,
            'documentType' => $layout->document_type,
            'payload' => $payload,
            'preview' => true,
        ]);
    }

    /**
     * Finalize save: bind identity, clean duplicates, optionally activate into Print Settings.
     */
    protected function finalizeLayoutSave(DocumentLayout $layout, Request $request): void
    {
        $this->bindPrintIdentity($layout);
        $this->cleanupDuplicateLayoutKeys($layout);

        if ($request->boolean('activate')) {
            $this->syncToPrintSettings($layout, true);
        } elseif ($request->boolean('sync_to_print_settings')) {
            // Persist override under the same Print Settings key without forcing activation switch
            $layout->print_template_key = $layout->base_template;
            $layout->save();
        }
    }

    /**
     * print_template_key === base_template; name = catalog label.
     */
    protected function bindPrintIdentity(DocumentLayout $layout): void
    {
        $base = (string) ($layout->base_template ?: 'template11');
        $catalog = DocumentLayoutCatalog::printTemplatesForType($layout->document_type);
        $label = $catalog['templates'][$base] ?? $layout->name;

        // Free unique key before assigning (legacy layout_* or other row)
        DocumentLayout::query()
            ->where('created_by', $layout->created_by)
            ->where('document_type', $layout->document_type)
            ->where('id', '!=', $layout->id)
            ->where('print_template_key', $base)
            ->update(['print_template_key' => null, 'is_active' => false]);

        $layout->base_template = $base;
        $layout->print_template_key = $base;
        $layout->name = $label;
        $layout->save();
    }

    /**
     * Remove legacy layout_* duplicate rows for the same base template.
     */
    protected function cleanupDuplicateLayoutKeys(DocumentLayout $layout): void
    {
        $base = (string) $layout->base_template;
        if ($base === '') {
            return;
        }

        $dupes = DocumentLayout::query()
            ->where('created_by', $layout->created_by)
            ->where('document_type', $layout->document_type)
            ->where('id', '!=', $layout->id)
            ->where(function ($q) use ($base) {
                $q->where('base_template', $base)
                    ->orWhere('print_template_key', 'like', 'layout_%')
                    ->orWhere('print_template_key', $base);
            })
            ->get();

        foreach ($dupes as $dupe) {
            // Prefer keeping the canonical base_template row; delete layout_* clones
            $key = (string) ($dupe->print_template_key ?? '');
            if (str_starts_with($key, 'layout_') || $dupe->base_template === $base) {
                // If dupe has richer elements and canonical is empty, migrate first
                if (
                    count($dupe->elements ?? []) > count($layout->elements ?? [])
                    && count($layout->elements ?? []) < 5
                ) {
                    $layout->elements = $dupe->elements;
                    $layout->save();
                }
                $dupe->delete();
            }
        }
    }

    /**
     * Activate override and set Print Settings to the same base template key (no new catalog entry).
     */
    protected function syncToPrintSettings(DocumentLayout $layout, bool $activate = true): void
    {
        DB::transaction(function () use ($layout, $activate) {
            $base = (string) ($layout->base_template ?: 'template11');
            $layout->print_template_key = $base;

            if ($activate) {
                DocumentLayout::where('created_by', $layout->created_by)
                    ->where('document_type', $layout->document_type)
                    ->where('id', '!=', $layout->id)
                    ->update(['is_active' => false]);
                $layout->is_active = true;
            }

            $layout->save();

            if ($activate && in_array($layout->document_type, ['invoice', 'bill', 'quotation', 'purchase', 'pos'], true)) {
                $settingName = $layout->document_type . '_template';
                DB::insert(
                    'insert into settings (`value`, `name`,`created_by`) values (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
                    [$base, $settingName, $layout->created_by]
                );
            }
        });
    }

    protected function activateLayout(DocumentLayout $layout): void
    {
        $this->syncToPrintSettings($layout, true);
    }

    protected function validated(Request $request, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'document_type' => ['required', Rule::in(DocumentLayout::TYPES)],
            'name' => ['required', 'string', 'max:191'],
            'base_template' => ['nullable', 'string', 'max:64'],
            'page_width' => ['nullable', 'integer', 'min:300', 'max:2000'],
            'page_height' => ['nullable', 'integer', 'min:400', 'max:3000'],
            'elements_json' => ['required', 'string'],
        ]);

        $elements = json_decode($validated['elements_json'], true);
        if (! is_array($elements)) {
            abort(422, __('Invalid layout elements.'));
        }

        $cleaned = [];
        $known = array_keys(DocumentLayoutCatalog::blocks());
        foreach ($elements as $el) {
            if (! is_array($el) || empty($el['key']) || ! in_array($el['key'], $known, true)) {
                continue;
            }
            $options = is_array($el['options'] ?? null) ? $el['options'] : [];
            if (($el['key'] ?? '') === 'bank_accounts') {
                $options['custom_text'] = isset($options['custom_text'])
                    ? (string) $options['custom_text']
                    : '';
                if (isset($options['limit'])) {
                    $options['limit'] = max(1, min(5, (int) $options['limit']));
                }
                if (isset($options['fields']) && is_array($options['fields'])) {
                    $options['fields'] = array_values(array_filter($options['fields'], 'is_string'));
                }
            }
            $cleaned[] = [
                'id' => (string) ($el['id'] ?? uniqid($el['key'] . '_')),
                'key' => $el['key'],
                'x' => max(0, (int) ($el['x'] ?? 0)),
                'y' => max(0, (int) ($el['y'] ?? 0)),
                'w' => max(40, (int) ($el['w'] ?? 100)),
                'h' => max(30, (int) ($el['h'] ?? 40)),
                'visible' => (bool) ($el['visible'] ?? true),
                'options' => $options,
            ];
        }

        return [
            'document_type' => $validated['document_type'],
            'name' => $validated['name'],
            'base_template' => $validated['base_template'] ?? null,
            'page_width' => (int) ($validated['page_width'] ?? 794),
            'page_height' => (int) ($validated['page_height'] ?? 1123),
            'elements' => $cleaned,
        ];
    }

    protected function sampleDocument(string $type, int $creatorId)
    {
        switch ($type) {
            case 'invoice':
                $doc = Invoice::where('created_by', $creatorId)->latest('id')->first();
                if ($doc) {
                    $doc->load(['customer', 'items.product']);
                    $this->hydrateInvoiceLikeItems($doc);
                }
                return $doc;
            case 'bill':
                $doc = Bill::where('created_by', $creatorId)->latest('id')->first();
                if ($doc) {
                    $doc->load(['vender', 'items.product']);
                    $this->hydrateInvoiceLikeItems($doc);
                }
                return $doc;
            case 'purchase':
                $doc = Purchase::where('created_by', $creatorId)->latest('id')->first();
                if ($doc) {
                    $doc->load(['vender', 'items.product']);
                }
                return $doc;
            case 'quotation':
                $doc = Quotation::where('created_by', $creatorId)->latest('id')->first();
                if ($doc) {
                    $doc->load(['customer', 'items.product']);
                }
                return $doc;
            case 'pos':
                $doc = Pos::where('created_by', $creatorId)->latest('id')->first();
                if ($doc) {
                    $doc->load(['customer', 'items.product']);
                }
                return $doc;
        }

        return null;
    }

    protected function hydrateInvoiceLikeItems($doc): void
    {
        if (! empty($doc->itemData)) {
            return;
        }
        $items = [];
        foreach ($doc->items ?? [] as $item) {
            $item->name = $item->product->name ?? ($item->product_name ?? '');
            $items[] = $item;
        }
        $doc->itemData = $items;
    }

    protected function printColorForType(string $documentType): string
    {
        $settings = Utility::settingsById($this->creatorId());
        $raw = (string) ($settings[$documentType . '_color'] ?? $settings['invoice_color'] ?? 'f9b034');

        return ltrim($raw, '#');
    }

    /**
     * Print Settings Blade preview URLs per template key (classic Blade; no DocumentLayout override).
     *
     * @return array<string, string>
     */
    protected function bladePreviewUrlMap(string $documentType, string $colorHex): array
    {
        $catalog = DocumentLayoutCatalog::printTemplatesForType($documentType);
        $map = [];
        foreach (array_keys($catalog['templates'] ?? []) as $key) {
            $map[$key] = $this->bladePreviewUrl($documentType, $key, $colorHex);
        }

        return $map;
    }

    protected function bladePreviewUrl(string $documentType, string $templateKey, string $colorHex): string
    {
        $color = ltrim($colorHex, '#') ?: 'f9b034';
        $route = match ($documentType) {
            'bill' => 'bill.preview',
            'quotation' => 'quotation.preview',
            'purchase' => 'purchase.preview',
            'pos' => 'pos.preview',
            default => 'invoice.preview',
        };

        try {
            return route($route, ['template' => $templateKey, 'color' => $color]) . '?layout_canvas=1';
        } catch (\Throwable $e) {
            return route('invoice.preview', ['template' => $templateKey, 'color' => $color]) . '?layout_canvas=1';
        }
    }

    /**
     * Sample payload for designer (kept for compatibility).
     */
    protected function designerPayload(string $documentType): array
    {
        $creatorId = $this->creatorId();
        $settings = Utility::settingsById($creatorId);
        $color = '#' . ($settings[$documentType . '_color'] ?? $settings['invoice_color'] ?? 'f9b034');
        $document = $this->sampleDocument($documentType, $creatorId);

        if ($document) {
            return DocumentLayoutRenderer::buildPayload($documentType, $document, [
                'settings' => $settings,
                'color' => $color,
                'preview' => true,
            ]);
        }

        // Synthetic preview when no document exists yet
        $party = (object) [
            'name' => 'Customer Name',
            'billing_name' => 'Customer Name',
            'shipping_name' => 'Customer Name',
            'billing_address' => 'Address line',
            'shipping_address' => 'Address line',
            'tax_number' => '400123456',
            'email' => 'customer@example.com',
            'billing_phone' => '840000000',
            'id' => 1,
        ];
        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            $items[] = (object) [
                'name' => 'Item ' . $i,
                'quantity' => 1,
                'price' => 100,
                'discount' => 0,
                'tax' => 16,
                'description' => 'Sample',
                'sku' => 'SKU-' . $i,
            ];
        }

        return [
            'settings' => $settings,
            'color' => $color,
            'font_color' => '#fff',
            'img' => '',
            'party' => $party,
            'party_label' => __('Bill To'),
            'number' => '00001',
            'date' => date('Y-m-d'),
            'due_date' => date('Y-m-d', strtotime('+7 days')),
            'status' => 0,
            'items' => $items,
            'sub_total' => 300,
            'discount_total' => 0,
            'tax_total' => 48,
            'grand_total' => 348,
            'notes' => '',
            'qr_html' => '',
            'banks' => collect(),
            'document' => null,
            'title' => DocumentLayoutCatalog::documentTypes()[$documentType] ?? __('Invoice'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function designerBlockHtml(array $payload, string $baseTemplate): array
    {
        return [];
    }
}
