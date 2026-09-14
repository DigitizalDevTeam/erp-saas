@extends('layouts.admin')
@section('page-title')
    {{ $layout->exists ? __('Edit Layout') : __('Create Layout') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('document-layouts.index') }}">{{ __('Layout Management') }}</a></li>
    <li class="breadcrumb-item">{{ $layout->exists ? __('Edit') : __('Create') }}</li>
@endsection

@push('css-page')
<style>
    .dl-designer { display: flex; gap: 12px; align-items: flex-start; flex-wrap: wrap; }
    .dl-palette, .dl-props {
        width: 220px;
        flex: 0 0 220px;
        max-height: 80vh;
        overflow: auto;
    }
    .dl-canvas-wrap {
        flex: 1 1 500px;
        overflow: auto;
        background: #e9ecef;
        padding: 16px;
        border-radius: 6px;
        max-height: 80vh;
    }
    .dl-canvas {
        position: relative;
        background: #fff;
        margin: 0 auto;
        box-shadow: 0 2px 12px rgba(0,0,0,.12);
        border: 1px solid #ccc;
        user-select: none;
    }
    .dl-block {
        position: absolute;
        border: 1px dashed #6c757d;
        background: rgba(111, 217, 67, 0.12);
        cursor: move;
        overflow: hidden;
        font-size: 12px;
        padding: 4px 6px;
        box-sizing: border-box;
    }
    .dl-block.selected {
        border: 2px solid #0d6efd;
        background: rgba(13, 110, 253, 0.12);
        z-index: 5;
    }
    .dl-block.hidden-block {
        opacity: 0.35;
        background: rgba(108,117,125,.15);
    }
    .dl-block .dl-label { font-weight: 600; pointer-events: none; }
    .dl-resize {
        position: absolute;
        right: 0; bottom: 0;
        width: 12px; height: 12px;
        background: #0d6efd;
        cursor: se-resize;
    }
    .dl-palette-item {
        display: block;
        width: 100%;
        text-align: left;
        margin-bottom: 6px;
    }
</style>
@endpush

@section('content')
@php
    $isEdit = $layout->exists;
    $action = $isEdit ? route('document-layouts.update', $layout->id) : route('document-layouts.store');
    $elements = $layout->elements ?: \App\Services\DocumentLayoutCatalog::defaultElements();
@endphp

<form id="layout-form" method="POST" action="{{ $action }}">
    @csrf
    @if ($isEdit) @method('PUT') @endif

    <div class="row mb-3">
        <div class="col-md-4">
            <label class="form-label">{{ __('Layout name') }}</label>
            <input type="text" name="name" class="form-control" required value="{{ old('name', $layout->name) }}" placeholder="{{ __('e.g. Invoice Mozambique custom') }}">
        </div>
        <div class="col-md-3">
            <label class="form-label">{{ __('Document type') }}</label>
            <select name="document_type" id="document_type" class="form-control" required>
                @foreach ($types as $key => $label)
                    <option value="{{ $key }}" @selected(old('document_type', $layout->document_type) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">{{ __('Page width') }}</label>
            <input type="number" name="page_width" id="page_width" class="form-control" value="{{ old('page_width', $layout->page_width ?: 794) }}">
        </div>
        <div class="col-md-2">
            <label class="form-label">{{ __('Page height') }}</label>
            <input type="number" name="page_height" id="page_height" class="form-control" value="{{ old('page_height', $layout->page_height ?: 1123) }}">
        </div>
        <div class="col-md-1 d-flex align-items-end">
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="activate" value="1" id="activate">
                <label class="form-check-label" for="activate">{{ __('Activate') }}</label>
            </div>
        </div>
    </div>

    <input type="hidden" name="elements_json" id="elements_json" value="">

    <div class="dl-designer mb-3">
        <div class="dl-palette card">
            <div class="card-header py-2"><strong>{{ __('Available blocks') }}</strong></div>
            <div class="card-body p-2">
                @foreach ($blocks as $key => $block)
                    <button type="button" class="btn btn-sm btn-outline-secondary dl-palette-item" data-add-block="{{ $key }}"
                            title="{{ $block['description'] }}">
                        + {{ $block['label'] }}
                    </button>
                @endforeach
                <hr>
                <button type="button" class="btn btn-sm btn-outline-danger w-100" id="remove-block">{{ __('Remove selected') }}</button>
            </div>
        </div>

        <div class="dl-canvas-wrap">
            <div id="dl-canvas" class="dl-canvas"
                 style="width: {{ (int)($layout->page_width ?: 794) }}px; height: {{ (int)($layout->page_height ?: 1123) }}px;"></div>
        </div>

        <div class="dl-props card">
            <div class="card-header py-2"><strong>{{ __('Block options') }}</strong></div>
            <div class="card-body p-2" id="block-props">
                <p class="text-muted small mb-0">{{ __('Select a block on the canvas.') }}</p>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <button type="submit" class="btn btn-primary">{{ __('Save layout') }}</button>
        <a href="{{ route('document-layouts.index', ['document_type' => $layout->document_type ?: 'invoice']) }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
        @if ($isEdit)
            <a href="{{ route('document-layouts.preview', $layout->id) }}" target="_blank" class="btn btn-outline-info">{{ __('Preview') }}</a>
        @endif
    </div>
</form>
@endsection

@push('script-page')
<script>
(function () {
    const blocksMeta = @json($blocks);
    const bankFieldOptions = @json($bankFields);
    let elements = @json($elements);
    const canvas = document.getElementById('dl-canvas');
    const propsEl = document.getElementById('block-props');
    let selectedId = null;
    let dragState = null;

    function uid(key) {
        return key + '_' + Math.random().toString(36).slice(2, 10);
    }

    function syncHidden() {
        document.getElementById('elements_json').value = JSON.stringify(elements);
    }

    function renderCanvas() {
        const w = parseInt(document.getElementById('page_width').value, 10) || 794;
        const h = parseInt(document.getElementById('page_height').value, 10) || 1123;
        canvas.style.width = w + 'px';
        canvas.style.height = h + 'px';
        canvas.innerHTML = '';

        elements.forEach(function (el) {
            const meta = blocksMeta[el.key] || { label: el.key };
            const div = document.createElement('div');
            div.className = 'dl-block' + (el.id === selectedId ? ' selected' : '') + (el.visible === false ? ' hidden-block' : '');
            div.dataset.id = el.id;
            div.style.left = el.x + 'px';
            div.style.top = el.y + 'px';
            div.style.width = el.w + 'px';
            div.style.height = el.h + 'px';
            div.innerHTML = '<div class="dl-label">' + meta.label + '</div><div class="dl-resize" data-resize="1"></div>';
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
            propsEl.innerHTML = '<p class="text-muted small mb-0">{{ __('Select a block on the canvas.') }}</p>';
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
            html += '<label class="form-label small">{{ __('Accounts to show') }}</label>';
            html += '<input type="number" min="1" max="5" class="form-control form-control-sm mb-2" id="prop-bank-limit" value="' + limit + '">';
            html += '<div class="small mb-1">{{ __('Fields') }}</div>';
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
                renderCanvas();
            });
        }
        const lim = document.getElementById('prop-bank-limit');
        if (lim) {
            lim.addEventListener('change', function () {
                el.options = el.options || {};
                el.options.limit = Math.max(1, Math.min(5, parseInt(lim.value, 10) || 2));
                syncHidden();
            });
        }
        document.querySelectorAll('.prop-bank-field').forEach(function (cb) {
            cb.addEventListener('change', function () {
                el.options = el.options || {};
                el.options.fields = Array.from(document.querySelectorAll('.prop-bank-field:checked')).map(function (c) { return c.value; });
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
        const startX = e.clientX;
        const startY = e.clientY;
        const orig = { x: el.x, y: el.y, w: el.w, h: el.h };
        dragState = { resize: isResize, startX: startX, startY: startY, orig: orig, el: el };
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
            const el = {
                id: uid(key),
                key: key,
                x: 40,
                y: 40,
                w: meta.default_w || 200,
                h: meta.default_h || 80,
                visible: true,
                options: key === 'bank_accounts' ? { limit: 2, fields: Object.keys(bankFieldOptions) } : {}
            };
            elements.push(el);
            selectedId = el.id;
            renderCanvas();
        });
    });

    document.getElementById('remove-block').addEventListener('click', function () {
        if (!selectedId) return;
        elements = elements.filter(function (e) { return e.id !== selectedId; });
        selectedId = null;
        renderCanvas();
    });

    ['page_width', 'page_height'].forEach(function (id) {
        document.getElementById(id).addEventListener('change', renderCanvas);
    });

    document.getElementById('layout-form').addEventListener('submit', function () {
        syncHidden();
    });

    renderCanvas();
})();
</script>
@endpush
