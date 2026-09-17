@extends('layouts.admin')
@section('page-title')
    {{ $layout->exists ? __('Edit Layout') : __('Manage Layout') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('document-layouts.index') }}">{{ __('Layout Management') }}</a></li>
    <li class="breadcrumb-item">{{ $layout->exists ? __('Edit') : __('Manage') }}</li>
@endsection

@php
    $isEdit = $layout->exists;
    $action = $isEdit ? route('document-layouts.update', $layout->id) : route('document-layouts.store');
    $printTemplates = $printTemplates ?? \App\Services\DocumentLayoutCatalog::printTemplatesForType($layout->document_type ?: 'invoice');
    $baseKey = old('base_template', $layout->base_template ?: 'template11');
    $previewColor = $previewColor ?? 'f9b034';
    $bladePreviewUrls = $bladePreviewUrls ?? [];
    $presetMap = [];
    foreach (array_keys($printTemplates['templates'] ?? []) as $tk) {
        $page = \App\Services\DocumentLayoutCatalog::pageSizeForTemplate($tk);
        $presetMap[$tk] = [
            'elements' => \App\Services\DocumentLayoutCatalog::regionMapForTemplate($tk),
            'width' => $page['width'],
            'height' => $page['height'],
            'label' => $printTemplates['templates'][$tk] ?? $tk,
            'previewUrl' => $bladePreviewUrls[$tk] ?? '',
        ];
    }
    $elements = $layout->elements ?: \App\Services\DocumentLayoutCatalog::regionMapForTemplate($baseKey);
    $initialPreview = $bladePreviewUrls[$baseKey] ?? ($presetMap[$baseKey]['previewUrl'] ?? '');
@endphp

@section('action-btn')
    <div class="float-end d-flex flex-wrap gap-2">
        <button type="submit" form="layout-form" class="btn btn-sm btn-primary">{{ __('Save layout') }}</button>
        <a href="{{ route('document-layouts.index', ['document_type' => $layout->document_type ?: 'invoice']) }}" class="btn btn-sm btn-secondary">{{ __('Cancel') }}</a>
    </div>
@endsection

@push('css-page')
<style>
    .dl-toolbar-card { margin-bottom: 12px; }
    .dl-toolbar-card .card-body { padding: 12px 16px; }
    .dl-toolbar-row {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        gap: 12px 16px;
        width: 100%;
    }
    .dl-toolbar-row .dl-field { margin: 0; flex: 0 1 auto; }
    .dl-toolbar-row .dl-field label { display: block; margin-bottom: 4px; font-size: 12px; font-weight: 600; }
    .dl-toolbar-row .dl-field-template { min-width: 200px; max-width: 260px; }
    .dl-toolbar-row .dl-field-type { min-width: 140px; max-width: 180px; }
    .dl-toolbar-row .dl-field-size { width: 90px; }
    .dl-toolbar-row .dl-checks {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 12px 16px;
        padding-bottom: 4px;
    }
    .dl-toolbar-row .dl-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-left: auto;
        padding-bottom: 2px;
    }
    .dl-workspace {
        display: grid;
        grid-template-columns: 180px minmax(0, 1fr) 240px;
        gap: 12px;
        align-items: start;
    }
    @media (max-width: 1200px) {
        .dl-workspace { grid-template-columns: 1fr; }
        .dl-toolbar-row .dl-actions { margin-left: 0; }
    }
    .dl-palette, .dl-props {
        max-height: 82vh;
        overflow: auto;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
    }
    .dl-palette .card-header, .dl-props .card-header {
        padding: 10px 12px;
        border-bottom: 1px solid #e5e7eb;
        font-weight: 600;
        background: #f8fafc;
    }
    .dl-palette-item {
        display: block;
        width: 100%;
        text-align: left;
        margin-bottom: 6px;
    }
    .dl-canvas-wrap {
        overflow: auto;
        background: #c5ccd4;
        padding: 20px;
        border-radius: 8px;
        max-height: 82vh;
        border: 1px solid #b0b7bf;
    }
    .dl-canvas {
        position: relative;
        background: #fff;
        margin: 0 auto;
        box-shadow: 0 8px 32px rgba(0,0,0,.18);
        border: 1px solid #999;
        user-select: none;
        overflow: hidden;
    }
    .dl-blade-frame {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        border: 0;
        pointer-events: none;
        background: #fff;
        z-index: 0;
    }
    .dl-block {
        position: absolute;
        border: 1px dashed rgba(13,110,253,.55);
        background: rgba(13,110,253,.06);
        cursor: move;
        overflow: hidden;
        box-sizing: border-box;
        z-index: 1;
    }
    .dl-block:hover { border-color: #0d6efd; background: rgba(13,110,253,.12); }
    .dl-block.selected {
        border: 2px solid #0d6efd;
        background: rgba(13,110,253,.16);
        box-shadow: 0 0 0 2px rgba(13,110,253,.25);
        z-index: 5;
    }
    .dl-block.hidden-block { opacity: .3; border-style: dotted; }
    .dl-block-chrome {
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 18px;
        background: rgba(15,23,42,.78);
        color: #fff;
        font-size: 10px;
        font-weight: 600;
        padding: 1px 6px;
        pointer-events: none;
        z-index: 2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .dl-resize {
        position: absolute;
        right: 0; bottom: 0;
        width: 12px; height: 12px;
        background: #0d6efd;
        cursor: se-resize;
        z-index: 3;
    }
    .dl-hint { font-size: 12px; color: #64748b; margin: 0 0 10px; }
</style>
@endpush

@section('content')
<form id="layout-form" method="POST" action="{{ $action }}">
    @csrf
    @if ($isEdit) @method('PUT') @endif

    <div class="card dl-toolbar-card">
        <div class="card-body">
            <div class="dl-toolbar-row">
                <div class="dl-field dl-field-template">
                    <label for="base_template">{{ __('Template') }}</label>
                    <select name="base_template" id="base_template" class="form-control form-control-sm">
                        @foreach ($printTemplates['templates'] ?? [] as $tplKey => $tplLabel)
                            <option value="{{ $tplKey }}" @selected($baseKey === $tplKey)>
                                {{ $tplLabel }}
                                @if(($printTemplates['template_sizes'][$tplKey] ?? 'a4') === 'a5') (A5) @else (A4) @endif
                            </option>
                        @endforeach
                    </select>
                    <input type="hidden" name="name" id="layout_name" value="{{ old('name', $layout->name) }}">
                </div>
                <div class="dl-field dl-field-type">
                    <label for="document_type">{{ __('Document type') }}</label>
                    <select name="document_type" id="document_type" class="form-control form-control-sm" required>
                        @foreach ($types as $key => $label)
                            <option value="{{ $key }}" @selected(old('document_type', $layout->document_type) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="dl-field dl-field-size">
                    <label for="page_width">{{ __('Width') }}</label>
                    <input type="number" name="page_width" id="page_width" class="form-control form-control-sm" value="{{ old('page_width', $layout->page_width ?: 794) }}">
                </div>
                <div class="dl-field dl-field-size">
                    <label for="page_height">{{ __('Height') }}</label>
                    <input type="number" name="page_height" id="page_height" class="form-control form-control-sm" value="{{ old('page_height', $layout->page_height ?: 1123) }}">
                </div>
                <div class="dl-checks">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" name="sync_to_print_settings" value="1" id="sync_to_print_settings" checked>
                        <label class="form-check-label" for="sync_to_print_settings">{{ __('Update this template') }}</label>
                    </div>
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" name="activate" value="1" id="activate" @checked($layout->is_active)>
                        <label class="form-check-label" for="activate">{{ __('Activate') }}</label>
                    </div>
                </div>
                <div class="dl-actions">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="reset-to-template">{{ __('Reset to template design') }}</button>
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Save layout') }}</button>
                </div>
            </div>
        </div>
    </div>

    <p class="dl-hint">{{ __('The Print Settings design is shown below. Drag the blue overlays to place blocks. Save updates this same template — no new Print Settings option is created.') }}</p>

    <input type="hidden" name="elements_json" id="elements_json" value="">

    <div class="dl-workspace">
        <div class="dl-palette">
            <div class="card-header">{{ __('Available blocks') }}</div>
            <div class="p-2">
                @foreach ($blocks as $key => $block)
                    <button type="button" class="btn btn-sm btn-outline-secondary dl-palette-item" data-add-block="{{ $key }}" title="{{ $block['description'] }}">
                        + {{ $block['label'] }}
                    </button>
                @endforeach
                <hr>
                <button type="button" class="btn btn-sm btn-outline-danger w-100" id="remove-block">{{ __('Remove selected') }}</button>
            </div>
        </div>

        <div class="dl-canvas-wrap">
            <div id="dl-canvas" class="dl-canvas"
                 style="width: {{ (int)($layout->page_width ?: 794) }}px; height: {{ (int)($layout->page_height ?: 1123) }}px;">
                <iframe id="dl-blade-preview" class="dl-blade-frame" src="{{ $initialPreview }}" title="{{ __('Print Settings preview') }}" tabindex="-1"></iframe>
            </div>
        </div>

        <div class="dl-props">
            <div class="card-header">{{ __('Block options') }}</div>
            <div class="p-2" id="block-props">
                <p class="text-muted small mb-0">{{ __('Select a block overlay.') }}</p>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 flex-wrap mt-3">
        <button type="submit" class="btn btn-primary">{{ __('Save layout') }}</button>
        <a href="{{ route('document-layouts.index', ['document_type' => $layout->document_type ?: 'invoice']) }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
        @if ($isEdit)
            <a href="{{ route('document-layouts.preview', $layout->id) }}" target="_blank" class="btn btn-outline-info">{{ __('Override preview') }}</a>
        @endif
    </div>
</form>
@endsection

@push('script-page')
<script>
(function () {
    const blocksMeta = @json($blocks);
    const bankFieldOptions = @json($bankFields);
    const presets = @json($presetMap);
    const manageUrl = @json(route('document-layouts.create'));
    let elements = @json($elements);
    const canvas = document.getElementById('dl-canvas');
    const bladeFrame = document.getElementById('dl-blade-preview');
    const propsEl = document.getElementById('block-props');
    let selectedId = null;
    let dragState = null;
    let dirty = false;

    function uid(key) {
        return key + '_' + Math.random().toString(36).slice(2, 10);
    }

    function syncHidden() {
        document.getElementById('elements_json').value = JSON.stringify(elements);
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function setBladePreview(url) {
        if (!bladeFrame || !url) return;
        if (bladeFrame.getAttribute('src') !== url) {
            bladeFrame.setAttribute('src', url);
        }
    }

    function renderCanvas() {
        const w = parseInt(document.getElementById('page_width').value, 10) || 794;
        const h = parseInt(document.getElementById('page_height').value, 10) || 1123;
        canvas.style.width = w + 'px';
        canvas.style.height = h + 'px';

        Array.from(canvas.querySelectorAll('.dl-block')).forEach(function (n) { n.remove(); });

        elements.forEach(function (el) {
            const meta = blocksMeta[el.key] || { label: el.key };
            const div = document.createElement('div');
            div.className = 'dl-block' + (el.id === selectedId ? ' selected' : '') + (el.visible === false ? ' hidden-block' : '');
            div.dataset.id = el.id;
            div.style.left = el.x + 'px';
            div.style.top = el.y + 'px';
            div.style.width = el.w + 'px';
            div.style.height = el.h + 'px';
            div.innerHTML =
                '<div class="dl-block-chrome">' + escapeHtml(meta.label) + '</div>' +
                '<div class="dl-resize" data-resize="1"></div>';
            canvas.appendChild(div);
        });
        syncHidden();
        renderProps();
    }

    function findEl(id) {
        return elements.find(function (e) { return e.id === id; });
    }

    function renderProps() {
        const el = findEl(selectedId);
        if (!el) {
            propsEl.innerHTML = '<p class="text-muted small mb-0">{{ __('Select a block overlay.') }}</p>';
            return;
        }
        const meta = blocksMeta[el.key] || { label: el.key };
        let html = '<div class="mb-2"><strong>' + meta.label + '</strong></div>';
        html += '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="prop-visible" ' + (el.visible !== false ? 'checked' : '') + '>';
        html += '<label class="form-check-label" for="prop-visible">{{ __('Visible') }}</label></div>';
        html += '<div class="mb-2 small text-muted">X:' + el.x + ' Y:' + el.y + ' W:' + el.w + ' H:' + el.h + '</div>';

        if (el.key === 'bank_accounts') {
            const opts = el.options || {};
            const fields = opts.fields || Object.keys(bankFieldOptions);
            const limit = opts.limit || 2;
            const customText = opts.custom_text || '';
            html += '<label class="form-label small">{{ __('Bank details text') }}</label>';
            html += '<textarea class="form-control form-control-sm mb-2" id="prop-bank-text" rows="5" placeholder="{{ __('Enter bank details…') }}">' + escapeHtml(customText) + '</textarea>';
            html += '<p class="small text-muted">{{ __('If empty, company bank accounts are used.') }}</p>';
            html += '<label class="form-label small">{{ __('Accounts to show (fallback)') }}</label>';
            html += '<input type="number" min="1" max="5" class="form-control form-control-sm mb-2" id="prop-bank-limit" value="' + limit + '">';
            html += '<div class="small mb-1">{{ __('Fields (fallback)') }}</div>';
            Object.keys(bankFieldOptions).forEach(function (fk) {
                const checked = fields.indexOf(fk) >= 0 ? 'checked' : '';
                html += '<div class="form-check"><input class="form-check-input prop-bank-field" type="checkbox" value="' + fk + '" id="bf_' + fk + '" ' + checked + '>';
                html += '<label class="form-check-label" for="bf_' + fk + '">' + bankFieldOptions[fk] + '</label></div>';
            });
        }

        propsEl.innerHTML = html;

        const vis = document.getElementById('prop-visible');
        if (vis) {
            vis.addEventListener('change', function () {
                el.visible = vis.checked;
                dirty = true;
                renderCanvas();
            });
        }
        const txt = document.getElementById('prop-bank-text');
        if (txt) {
            txt.addEventListener('input', function () {
                el.options = el.options || {};
                el.options.custom_text = txt.value;
                dirty = true;
                syncHidden();
            });
        }
        const lim = document.getElementById('prop-bank-limit');
        if (lim) {
            lim.addEventListener('change', function () {
                el.options = el.options || {};
                el.options.limit = Math.max(1, Math.min(5, parseInt(lim.value, 10) || 2));
                dirty = true;
                syncHidden();
            });
        }
        document.querySelectorAll('.prop-bank-field').forEach(function (cb) {
            cb.addEventListener('change', function () {
                el.options = el.options || {};
                el.options.fields = Array.from(document.querySelectorAll('.prop-bank-field:checked')).map(function (c) { return c.value; });
                dirty = true;
                syncHidden();
            });
        });
    }

    canvas.addEventListener('mousedown', function (e) {
        const block = e.target.closest('.dl-block');
        if (!block) {
            selectedId = null;
            renderCanvas();
            return;
        }
        selectedId = block.dataset.id;
        const el = findEl(selectedId);
        if (!el) return;

        const isResize = e.target.getAttribute('data-resize') === '1';
        dragState = {
            resize: isResize,
            startX: e.clientX,
            startY: e.clientY,
            orig: { x: el.x, y: el.y, w: el.w, h: el.h },
            el: el
        };
        renderCanvas();
        e.preventDefault();
    });

    document.addEventListener('mousemove', function (e) {
        if (!dragState) return;
        const dx = e.clientX - dragState.startX;
        const dy = e.clientY - dragState.startY;
        const el = dragState.el;
        if (dragState.resize) {
            el.w = Math.max(40, dragState.orig.w + dx);
            el.h = Math.max(30, dragState.orig.h + dy);
        } else {
            el.x = Math.max(0, dragState.orig.x + dx);
            el.y = Math.max(0, dragState.orig.y + dy);
        }
        dirty = true;
        renderCanvas();
    });

    document.addEventListener('mouseup', function () {
        dragState = null;
        syncHidden();
    });

    document.querySelectorAll('[data-add-block]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const key = btn.getAttribute('data-add-block');
            const meta = blocksMeta[key];
            if (!meta) return;
            if (elements.some(function (e) { return e.key === key; })) {
                alert('{{ __('This block is already on the canvas. Select it to adjust.') }}');
                return;
            }
            elements.push({
                id: uid(key),
                key: key,
                x: 40,
                y: 40,
                w: meta.default_w || 200,
                h: meta.default_h || 80,
                visible: true,
                options: key === 'bank_accounts'
                    ? { limit: 2, fields: Object.keys(bankFieldOptions), custom_text: '' }
                    : {}
            });
            selectedId = elements[elements.length - 1].id;
            dirty = true;
            renderCanvas();
        });
    });

    document.getElementById('remove-block').addEventListener('click', function () {
        if (!selectedId) return;
        elements = elements.filter(function (e) { return e.id !== selectedId; });
        selectedId = null;
        dirty = true;
        renderCanvas();
    });

    function loadTemplateKey(key, force) {
        const preset = presets[key];
        if (!preset) return false;
        if (!force && dirty && !confirm(@json(__('Switch template? Unsaved changes will be lost.')))) {
            return false;
        }
        // Navigate to upsert-by-key so each template loads its own override / region map
        window.location.href = manageUrl
            + '?document_type=' + encodeURIComponent(document.getElementById('document_type').value)
            + '&base_template=' + encodeURIComponent(key);
        return true;
    }

    function applyLocalPreset(key) {
        const preset = presets[key];
        if (!preset) return;
        elements = JSON.parse(JSON.stringify(preset.elements));
        document.getElementById('page_width').value = preset.width;
        document.getElementById('page_height').value = preset.height;
        document.getElementById('layout_name').value = preset.label || key;
        setBladePreview(preset.previewUrl);
        selectedId = null;
        dirty = false;
        renderCanvas();
    }

    document.getElementById('base_template').addEventListener('change', function () {
        const next = this.value;
        const prev = @json($baseKey);
        if (!loadTemplateKey(next, false)) {
            this.value = prev;
        }
    });

    document.getElementById('reset-to-template').addEventListener('click', function () {
        const key = document.getElementById('base_template').value;
        if (confirm(@json(__('Reset all blocks to the original template design?')))) {
            applyLocalPreset(key);
        }
    });

    ['page_width', 'page_height'].forEach(function (id) {
        document.getElementById(id).addEventListener('change', renderCanvas);
    });

    document.getElementById('layout-form').addEventListener('submit', function () {
        syncHidden();
    });

    setBladePreview(@json($initialPreview));
    renderCanvas();
})();
</script>
@endpush
