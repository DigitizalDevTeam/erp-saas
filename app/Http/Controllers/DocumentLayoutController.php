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

        $layouts = DocumentLayout::query()
            ->where('created_by', $creatorId)
            ->where('document_type', $type)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return view('document_layouts.index', compact('layouts', 'types', 'type'));
    }

    public function create(Request $request)
    {
        $this->authorizeLayouts();

        $types = DocumentLayoutCatalog::documentTypes();
        $type = $request->input('document_type', 'invoice');
        if (! array_key_exists($type, $types)) {
            $type = 'invoice';
        }

        $layout = new DocumentLayout([
            'document_type' => $type,
            'name' => '',
            'page_width' => 794,
            'page_height' => 1123,
            'elements' => DocumentLayoutCatalog::defaultElements(),
            'is_active' => false,
        ]);

        $blocks = DocumentLayoutCatalog::blocks();
        $bankFields = DocumentLayoutCatalog::bankFieldOptions();

        return view('document_layouts.edit', compact('layout', 'types', 'blocks', 'bankFields'));
    }

    public function store(Request $request)
    {
        $this->authorizeLayouts();

        $data = $this->validated($request);
        $creatorId = $this->creatorId();

        $layout = DocumentLayout::create([
            'created_by' => $creatorId,
            'document_type' => $data['document_type'],
            'name' => $data['name'],
            'page_width' => $data['page_width'],
            'page_height' => $data['page_height'],
            'elements' => $data['elements'],
            'is_active' => false,
        ]);

        if ($request->boolean('activate')) {
            $this->activateLayout($layout);
        }

        return redirect()
            ->route('document-layouts.edit', $layout->id)
            ->with('success', __('Layout created successfully.'));
    }

    public function edit($id)
    {
        $this->authorizeLayouts();

        $layout = DocumentLayout::where('created_by', $this->creatorId())->findOrFail($id);
        $types = DocumentLayoutCatalog::documentTypes();
        $blocks = DocumentLayoutCatalog::blocks();
        $bankFields = DocumentLayoutCatalog::bankFieldOptions();

        return view('document_layouts.edit', compact('layout', 'types', 'blocks', 'bankFields'));
    }

    public function update(Request $request, $id)
    {
        $this->authorizeLayouts();

        $layout = DocumentLayout::where('created_by', $this->creatorId())->findOrFail($id);
        $data = $this->validated($request, $layout->id);

        $layout->update([
            'document_type' => $data['document_type'],
            'name' => $data['name'],
            'page_width' => $data['page_width'],
            'page_height' => $data['page_height'],
            'elements' => $data['elements'],
        ]);

        if ($request->boolean('activate')) {
            $this->activateLayout($layout);
        }

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
        $this->activateLayout($layout);

        return redirect()
            ->route('document-layouts.index', ['document_type' => $layout->document_type])
            ->with('success', __('Layout activated. It will be used when printing this document type.'));
    }

    public function deactivate($id)
    {
        $this->authorizeLayouts();

        $layout = DocumentLayout::where('created_by', $this->creatorId())->findOrFail($id);
        $layout->update(['is_active' => false]);

        return redirect()
            ->route('document-layouts.index', ['document_type' => $layout->document_type])
            ->with('success', __('Layout deactivated. Default Blade templates will be used.'));
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

    protected function activateLayout(DocumentLayout $layout): void
    {
        DB::transaction(function () use ($layout) {
            DocumentLayout::where('created_by', $layout->created_by)
                ->where('document_type', $layout->document_type)
                ->update(['is_active' => false]);

            $layout->is_active = true;
            $layout->save();
        });
    }

    protected function validated(Request $request, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'document_type' => ['required', Rule::in(DocumentLayout::TYPES)],
            'name' => ['required', 'string', 'max:191'],
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
            $cleaned[] = [
                'id' => (string) ($el['id'] ?? uniqid($el['key'] . '_')),
                'key' => $el['key'],
                'x' => max(0, (int) ($el['x'] ?? 0)),
                'y' => max(0, (int) ($el['y'] ?? 0)),
                'w' => max(40, (int) ($el['w'] ?? 100)),
                'h' => max(30, (int) ($el['h'] ?? 40)),
                'visible' => (bool) ($el['visible'] ?? true),
                'options' => is_array($el['options'] ?? null) ? $el['options'] : [],
            ];
        }

        return [
            'document_type' => $validated['document_type'],
            'name' => $validated['name'],
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
}
