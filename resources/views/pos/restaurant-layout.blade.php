@extends('layouts.admin')
@section('page-title')
    {{ __('Layout') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos.report') }}">{{ __('POS') }}</a></li>
    <li class="breadcrumb-item">{{ __('Layout') }}</li>
@endsection

@section('content')
@php
    $posLayoutPalette = [
        ['title' => __('Tables'), 'items' => [
            ['type' => 'table-round-2', 'label' => __('Round 2'), 'mini' => 'mini-round'],
            ['type' => 'table-round-4', 'label' => __('Round 4'), 'mini' => 'mini-round'],
            ['type' => 'table-round-6', 'label' => __('Round 6'), 'mini' => 'mini-round'],
            ['type' => 'table-square-2', 'label' => __('Square 2'), 'mini' => 'mini-square'],
            ['type' => 'table-square-4', 'label' => __('Square 4'), 'mini' => 'mini-square'],
            ['type' => 'table-rect-4', 'label' => __('Rectangle 4'), 'mini' => 'mini-rect'],
            ['type' => 'table-rect-6', 'label' => __('Rectangle 6'), 'mini' => 'mini-rect'],
            ['type' => 'table-banquet', 'label' => __('Banquet 8-12'), 'mini' => 'mini-banquet'],
        ]],
        ['title' => __('Structure'), 'items' => [
            ['type' => 'wall-h', 'label' => __('Wall (Horizontal)'), 'mini' => 'mini-wall-h'],
            ['type' => 'wall-v', 'label' => __('Wall (Vertical)'), 'mini' => 'mini-wall-v'],
            ['type' => 'door', 'label' => __('Door'), 'mini' => 'mini-door'],
            ['type' => 'window', 'label' => __('Window'), 'mini' => 'mini-window'],
        ]],
        ['title' => __('Counters'), 'items' => [
            ['type' => 'bar', 'label' => __('Bar Counter'), 'mini' => 'mini-bar'],
            ['type' => 'reception', 'label' => __('Reception'), 'mini' => 'mini-reception'],
        ]],
        ['title' => __('Decor'), 'items' => [
            ['type' => 'plant', 'label' => __('Plant'), 'mini' => 'mini-plant'],
            ['type' => 'waiter', 'label' => __('Waiter Station'), 'mini' => 'mini-waiter'],
        ]],
    ];
@endphp
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <label class="form-label mb-0 fw-bold" for="posLayoutAreaSelect">{{ __('Area') }}</label>
                <select id="posLayoutAreaSelect" class="form-select form-select-sm" style="width:auto;min-width:220px">
                    @forelse ($areas as $area)
                        <option value="{{ $area->id }}" {{ $area->id == $initialAreaId ? 'selected' : '' }}>{{ $area->name }}{{ $area->branch ? ' — ' . $area->branch->name : '' }}</option>
                    @empty
                        <option value="" disabled selected>{{ __('No areas yet') }}</option>
                    @endforelse
                </select>
                <button type="button" class="btn btn-sm btn-light" id="posLayoutNewArea" title="{{ __('New area') }}">+ {{ __('New area') }}</button>
                <a class="btn btn-sm btn-light" href="{{ route('pos.restaurant.index') }}" title="{{ __('View on POS') }}">{{ __('View on POS') }}</a>
                <span id="posLayoutAreaName" class="text-muted small"></span>
                <span class="flex-grow-1"></span>
                <div class="btn-group" role="group" aria-label="{{ __('Zoom') }}">
                    <button type="button" class="btn btn-sm btn-light" id="posLayoutZoomOut" title="{{ __('Zoom Out') }}">&minus;</button>
                    <button type="button" class="btn btn-sm btn-light" id="posLayoutZoomLabel" disabled>100%</button>
                    <button type="button" class="btn btn-sm btn-light" id="posLayoutZoomIn" title="{{ __('Zoom In') }}">+</button>
                    <button type="button" class="btn btn-sm btn-light" id="posLayoutZoomFit">{{ __('Fit') }}</button>
                </div>
                <button type="button" class="btn btn-sm btn-light active" id="posLayoutGridToggle" aria-pressed="true">{{ __('Grid') }}</button>
                <div class="btn-group" role="group" aria-label="{{ __('Undo') }}">
                    <button type="button" class="btn btn-sm btn-light" id="posLayoutUndo" title="{{ __('Undo') }}" disabled>&#8617; {{ __('Undo') }}</button>
                    <button type="button" class="btn btn-sm btn-light" id="posLayoutRedo" title="{{ __('Redo') }}" disabled>&#8618; {{ __('Redo') }}</button>
                </div>
                <span id="posLayoutSaved" class="small" style="display:none"></span>
                <button type="button" class="btn btn-sm btn-light-success" id="posLayoutAuto">{{ __('Auto layout') }}</button>
                <button type="button" class="btn btn-sm btn-light-danger" id="posLayoutClear">{{ __('Clear All') }}</button>
                <button type="button" class="btn btn-sm btn-light-warning" id="posLayoutLoadDemo">{{ __('Load demo') }}</button>
            </div>
            <div class="card-body">
                <div id="posLayoutNoAreas" class="alert alert-info" style="display:none">{{ __('No areas yet. Create one on the POS Areas page.') }} <a href="{{ route('pos-area.index') }}">{{ __('POS Areas') }}</a></div>
                <div class="d-flex flex-wrap gap-3 align-items-start">
                    <div class="card mb-0" id="posLayoutPalette" style="flex:0 0 208px;max-width:100%">
                        <div class="card-header py-2">
                            <h6 class="mb-0">{{ __('Elements') }}</h6>
                        </div>
                        <div class="card-body p-2 pos-layout-palette-body">
                            <div class="d-flex gap-1 mb-1 pos-layout-area-search-row">
                                <input type="search" id="posLayoutAreaSearch" class="form-control form-control-sm" placeholder="{{ __('Search tables...') }}" aria-label="{{ __('Search tables...') }}">
                                <button type="button" class="btn btn-sm btn-outline-primary flex-shrink-0" id="posLayoutNewTable" title="{{ __('New table') }}">+ {{ __('New table') }}</button>
                            </div>
                            <div class="text-muted small fw-bold mt-2 mb-1">{{ __('Tables in this area') }}</div>
                            <div id="posLayoutAreaTables"></div>
                            @foreach ($posLayoutPalette as $group)
                                <div class="text-muted small fw-bold mt-2 mb-1">{{ $group['title'] }}</div>
                                @foreach ($group['items'] as $item)
                                    <button type="button" class="pos-layout-palette-btn" data-type="{{ $item['type'] }}">
                                        <span class="pos-layout-mini {{ $item['mini'] }}"></span>
                                        <span class="pos-layout-palette-label">{{ $item['label'] }}</span>
                                    </button>
                                @endforeach
                            @endforeach
                            <div class="text-muted small mt-2">{{ __('Click or drag an element onto the canvas. Drag empty floor to pan, scroll to zoom.') }}</div>
                        </div>
                    </div>
                    <div class="flex-grow-1" style="min-width:280px">
                        <div id="posLayoutWrap" class="pos-layout-wrap">
                            <div id="posLayoutCanvas" class="pos-layout-canvas"></div>
                        </div>
                        <div class="text-muted small mt-2">{{ __('Drag elements to move them. Click an element to edit it. Drag the blue handle to rotate, or stretch a wall by its edge.') }}</div>
                        <div class="text-muted small mt-1 pos-layout-legend">
                            <span class="pos-layout-legend-item"><span class="pos-layout-dot pos-layout-dot-available"></span>{{ __('Available') }}</span>
                            <span class="pos-layout-legend-item"><span class="pos-layout-dot pos-layout-dot-occupied"></span>{{ __('Occupied') }}</span>
                            <span class="pos-layout-legend-item"><span class="pos-layout-dot pos-layout-dot-reserved"></span>{{ __('Reserved') }}</span>
                        </div>
                    </div>
                    <div class="card mb-0" id="posLayoutProps" style="flex:0 0 248px;max-width:100%">
                        <div class="card-header py-2">
                            <h6 class="mb-0">{{ __('Properties') }}</h6>
                        </div>
                        <div class="card-body p-3">
                            <div id="posLayoutPropsEmpty" class="text-muted small">{{ __('Click an element on the canvas to edit it.') }}</div>
                            <div id="posLayoutPropsForm" style="display:none">
                                <div class="mb-2">
                                    <label class="form-label">{{ __('Type') }}</label>
                                    <input id="posLayoutPropType" class="form-control form-control-sm" readonly>
                                </div>
                                <div class="mb-2" id="posLayoutPropShapeWrap" style="display:none">
                                    <label class="form-label">{{ __('Shape') }}</label>
                                    <select id="posLayoutPropShape" class="form-select form-select-sm">
                                        <option value="round">{{ __('Round') }}</option>
                                        <option value="square">{{ __('Square') }}</option>
                                        <option value="rect">{{ __('Rectangle') }}</option>
                                        <option value="banquet">{{ __('Banquet') }}</option>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label">{{ __('Label') }}</label>
                                    <input id="posLayoutPropLabel" class="form-control form-control-sm">
                                </div>
                                <div class="mb-2" id="posLayoutPropCapacityWrap">
                                    <label class="form-label">{{ __('Capacity') }}</label>
                                    <input id="posLayoutPropCapacity" class="form-control form-control-sm" type="number" min="1" max="99">
                                </div>
                                <div class="mb-2" id="posLayoutPropStatusWrap">
                                    <label class="form-label">{{ __('Status') }}</label>
                                    <select id="posLayoutPropStatus" class="form-select form-select-sm" disabled>
                                        <option value="available">{{ __('Available') }}</option>
                                        <option value="occupied">{{ __('Occupied') }}</option>
                                        <option value="reserved">{{ __('Reserved') }}</option>
                                    </select>
                                </div>
                                <div class="mb-2" id="posLayoutPropRotWrap">
                                    <label class="form-label">{{ __('Rotation') }}: <span id="posLayoutPropRot">0&deg;</span></label>
                                    <div class="btn-group w-100" role="group" aria-label="{{ __('Rotation') }}">
                                        <button type="button" class="btn btn-sm btn-light" data-rot="-15">&minus;15&deg;</button>
                                        <button type="button" class="btn btn-sm btn-light" data-rot="15">+15&deg;</button>
                                        <button type="button" class="btn btn-sm btn-light" data-rot="45">+45&deg;</button>
                                        <button type="button" class="btn btn-sm btn-light" data-rot="90">+90&deg;</button>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="button" id="posLayoutPropDuplicate" class="btn btn-sm btn-outline-primary flex-grow-1">{{ __('Duplicate') }}</button>
                                    <button type="button" id="posLayoutPropDelete" class="btn btn-sm btn-outline-danger flex-grow-1">{{ __('Delete') }}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Inline confirm / prompt dialog (no native window.confirm anywhere). --}}
<div id="posLayoutModal" class="pos-layout-modal" style="display:none" role="dialog" aria-modal="true">
    <div class="pos-layout-modal-card">
        <h6 id="posLayoutModalTitle" class="mb-2"></h6>
        <p id="posLayoutModalMessage" class="text-muted small mb-3" style="display:none"></p>
        <div id="posLayoutModalFields"></div>
        <div class="d-flex justify-content-end gap-2 mt-3">
            <button type="button" class="btn btn-sm btn-light" id="posLayoutModalCancel">{{ __('Cancel') }}</button>
            <button type="button" class="btn btn-sm btn-primary" id="posLayoutModalConfirm">{{ __('Confirm') }}</button>
        </div>
    </div>
</div>
@endsection

@push('css-page')
<style>
.pos-layout-wrap { overflow: hidden; position: relative; background: #e9edf3; border: 1px solid #dee2e6; border-radius: 8px; height: 640px;
    background-image: linear-gradient(#e2e8f2 1px, transparent 1px), linear-gradient(90deg, #e2e8f2 1px, transparent 1px), linear-gradient(#c9d4e8 1px, transparent 1px), linear-gradient(90deg, #c9d4e8 1px, transparent 1px);
    background-size: 20px 20px, 20px 20px, 100px 100px, 100px 100px; }
.pos-layout-wrap.no-grid { background-image: none; }
.pos-layout-canvas { position: absolute; left: 0; top: 0; transform-origin: 0 0; }
.pos-layout-el { position: absolute; cursor: grab; user-select: none; -webkit-user-select: none; touch-action: none; }
.pos-layout-el:active { cursor: grabbing; }
.pos-layout-el.selected { outline: 2px dashed #0d6efd; outline-offset: 4px; z-index: 5; }
.pos-layout-table-body { position: absolute; inset: 0; background: #aab8d4; border: 2px solid #22355e; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #22355e; overflow: hidden; }
.pos-layout-el.shape-round .pos-layout-table-body { border-radius: 50%; }
.pos-layout-table-num { font-weight: 700; font-size: 14px; line-height: 1.15; }
.pos-layout-table-cap { font-size: 11px; line-height: 1.15; }
.pos-layout-dot { width: 9px; height: 9px; border-radius: 50%; margin-top: 3px; border: 1px solid rgba(0,0,0,.25); }
.pos-layout-dot-available { background: #22c55e; }
.pos-layout-dot-occupied { background: #ffc107; }
.pos-layout-dot-reserved { background: #6c757d; }
.pos-layout-chair { position: absolute; width: 14px; height: 9px; background: #8fa1c2; border: 1px solid #22355e; border-radius: 2px; }
.pos-layout-wall-el { background: #22355e; border-radius: 2px; }
.pos-layout-door-el { background: #ffffff; border: 2px dashed #22355e; border-radius: 2px; }
.pos-layout-window-el { background: #bcd6f5; border: 1px solid #22355e; border-radius: 2px; }
.pos-layout-bar-el { background: #e8edf5; border: 2px solid #22355e; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #22355e; font-weight: 700; font-size: 13px; letter-spacing: 1px; }
.pos-layout-reception-el { background: #e8edf5; border: 2px solid #22355e; border-radius: 4px; display: flex; align-items: center; justify-content: center; gap: 4px; color: #22355e; font-weight: 600; font-size: 12px; }
.pos-layout-stool { position: absolute; bottom: -7px; width: 10px; height: 10px; border-radius: 50%; background: #ffffff; border: 2px solid #22355e; }
.pos-layout-plant-el { background: radial-gradient(circle at 35% 35%, #4caf7d, #1e6b41); border-radius: 50%; color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 22px; border: 1px solid #14532d; }
.pos-layout-waiter-el { background: #22355e; border-radius: 6px; color: #ffffff; display: flex; align-items: center; justify-content: center; }
.pos-layout-rotate-handle { position: absolute; top: -30px; left: 50%; margin-left: -13px; width: 26px; height: 26px; border-radius: 50%; background: #0d6efd; color: #ffffff; border: 2px solid #ffffff; display: flex; align-items: center; justify-content: center; font-size: 15px; cursor: grab; box-shadow: 0 1px 4px rgba(0,0,0,.3); z-index: 6; touch-action: none; }
.pos-layout-resize-handle { position: absolute; width: 14px; height: 14px; background: #0d6efd; border: 2px solid #ffffff; border-radius: 3px; z-index: 6; touch-action: none; }
.pos-layout-resize-handle.pos-layout-resize-e { right: -8px; top: 50%; margin-top: -7px; cursor: ew-resize; }
.pos-layout-resize-handle.pos-layout-resize-s { bottom: -8px; left: 50%; margin-left: -7px; cursor: ns-resize; }
.pos-layout-palette-btn { display: flex; align-items: center; gap: 8px; width: 100%; text-align: left; padding: 5px 8px; margin-bottom: 2px; background: #ffffff; border: 1px solid #dee2e6; border-radius: 6px; color: #212529; font-size: 13px; cursor: grab; touch-action: none; }
.pos-layout-palette-btn:hover { border-color: #0d6efd; background: #f0f5ff; }
.pos-layout-palette-btn:disabled { opacity: .55; cursor: default; }
.pos-layout-area-table-btn { justify-content: space-between; }
.pos-layout-onfloor { font-size: 11px; color: #198754; font-weight: 700; }
.pos-layout-mini { width: 28px; height: 28px; flex: 0 0 28px; display: flex; align-items: center; justify-content: center; }
.mini-round { width: 22px; height: 22px; border-radius: 50%; background: #aab8d4; border: 2px solid #22355e; }
.mini-square { width: 20px; height: 20px; background: #aab8d4; border: 2px solid #22355e; }
.mini-rect { width: 26px; height: 16px; background: #aab8d4; border: 2px solid #22355e; }
.mini-banquet { width: 28px; height: 13px; background: #aab8d4; border: 2px solid #22355e; }
.mini-wall-h { width: 26px; height: 6px; background: #22355e; border-radius: 2px; }
.mini-wall-v { width: 6px; height: 26px; background: #22355e; border-radius: 2px; }
.mini-door { width: 26px; height: 8px; background: #ffffff; border: 2px dashed #22355e; border-radius: 2px; }
.mini-window { width: 26px; height: 8px; background: #bcd6f5; border: 1px solid #22355e; border-radius: 2px; }
.mini-bar { width: 26px; height: 12px; background: #e8edf5; border: 2px solid #22355e; border-radius: 3px; }
.mini-reception { width: 22px; height: 16px; background: #e8edf5; border: 2px solid #22355e; border-radius: 3px; }
.mini-plant { width: 20px; height: 20px; border-radius: 50%; background: radial-gradient(circle at 35% 35%, #4caf7d, #1e6b41); }
.mini-waiter { width: 18px; height: 22px; background: #22355e; border-radius: 4px; color: #ffffff; font-size: 12px; }
/* The Elements panel scrolls on its own (a 41-table list must not push
   the canvas and toolbar off the page while you scroll through it). */
.pos-layout-palette-body { max-height: 640px; overflow-y: auto; }
.pos-layout-area-search-row { position: sticky; top: -8px; background: #ffffff; z-index: 2; padding-top: 2px; }
.pos-layout-legend { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
.pos-layout-legend-item { display: inline-flex; align-items: center; gap: 4px; }
.pos-layout-legend .pos-layout-dot { margin-top: 0; }
/* Small inline confirm/prompt dialog (replaces native window.confirm so
   the designer never shows a browser popup). */
.pos-layout-modal { position: fixed; inset: 0; z-index: 2000; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; }
.pos-layout-modal-card { background: #ffffff; border-radius: 8px; padding: 16px; width: 380px; max-width: calc(100vw - 32px); box-shadow: 0 8px 30px rgba(0,0,0,.3); }
</style>
@endpush

@push('script-page')
<script>
(function () {
    'use strict';

    var AREAS = @json($areasJson);
    var BRANCHES = @json($branches);
    var INITIAL_AREA_ID = @json($initialAreaId);
    var AREA_URL_TEMPLATE = @json(route('pos.restaurant.layout.designer.area', ['area' => '__AREA_ID__']));
    var CREATE_TABLE_URL = @json(route('pos.restaurant.layout.designer.tables.store'));
    var UPDATE_TABLE_URL_TEMPLATE = @json(route('pos.restaurant.layout.designer.tables.update', ['table' => '__TABLE_ID__']));
    var CREATE_AREA_URL = @json(route('pos.restaurant.layout.designer.areas.store'));
    var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    var I18N = {
        table: @json(__('Table')),
        floorDesign: @json(__('Floor design')),
        confirmClear: @json(__('Clear all elements from the canvas?')),
        confirmDiscard: @json(__('Discard unsaved changes and switch area?')),
        confirmDemo: @json(__('Load the demo floor? Missing tables are created as real POS tables.')),
        confirmAutoLayout: @json(__('Replace the current floor with an auto layout of all tables in this area?')),
        noTables: @json(__('No tables in this area yet.')),
        confirmRemoveTable: @json(__('Remove this table from the floor? The table stays in POS Tables.')),
        removeFromFloor: @json(__('Remove from floor')),
        deleteLabel: @json(__('Delete')),
        onFloor: @json(__('On floor')),
        unsaved: @json(__('Unsaved changes')),
        saved: @json(__('Saved')),
        saving: @json(__('Saving...')),
        retrySave: @json(__('Click to retry saving')),
        saveFailed: @json(__('Could not save the layout.')),
        loadFailed: @json(__('Could not load the layout.')),
        createFailed: @json(__('Could not create the table.')),
        updateTableFailed: @json(__('Could not update the table.')),
        createAreaFailed: @json(__('Could not create the area.')),
        showOnFloor: @json(__('Show on floor')),
        newTable: @json(__('New table')),
        newArea: @json(__('New area')),
        tableName: @json(__('Table name')),
        seats: @json(__('Seats')),
        nameLabel: @json(__('Name')),
        branchLabel: @json(__('Branch')),
        createLabel: @json(__('Create')),
        confirmLabel: @json(__('Confirm')),
        okLabel: @json(__('OK'))
    };

    var PERSON_SVG = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6z"/></svg>';

    var TYPES = {
        'table-round-2': { kind: 'table', shape: 'round', w: 72, h: 72, seats: 2, chairs: 2 },
        'table-round-4': { kind: 'table', shape: 'round', w: 92, h: 92, seats: 4, chairs: 4 },
        'table-round-6': { kind: 'table', shape: 'round', w: 112, h: 112, seats: 6, chairs: 6 },
        'table-square-2': { kind: 'table', shape: 'square', w: 66, h: 66, seats: 2, chairs: 2 },
        'table-square-4': { kind: 'table', shape: 'square', w: 86, h: 86, seats: 4, chairs: 4 },
        'table-rect-4': { kind: 'table', shape: 'rect', w: 112, h: 72, seats: 4, chairs: 4 },
        'table-rect-6': { kind: 'table', shape: 'rect', w: 142, h: 82, seats: 6, chairs: 6 },
        'table-banquet': { kind: 'table', shape: 'rect', w: 220, h: 82, seats: 12, chairs: 6 },
        'wall-h': { kind: 'wall', w: 200, h: 10, resizable: 'w' },
        'wall-v': { kind: 'wall', w: 10, h: 200, resizable: 'h' },
        'door': { kind: 'door', w: 90, h: 14 },
        'window': { kind: 'window', w: 110, h: 10 },
        'bar': { kind: 'bar', w: 200, h: 56 },
        'reception': { kind: 'reception', w: 150, h: 54 },
        'plant': { kind: 'plant', w: 46, h: 46 },
        'waiter': { kind: 'waiter', w: 56, h: 64 }
    };

    var canvas = document.getElementById('posLayoutCanvas');
    var wrap = document.getElementById('posLayoutWrap');
    var palette = document.getElementById('posLayoutPalette');
    var areaSelect = document.getElementById('posLayoutAreaSelect');
    var areaNameEl = document.getElementById('posLayoutAreaName');
    var areaTablesEl = document.getElementById('posLayoutAreaTables');
    var noAreasEl = document.getElementById('posLayoutNoAreas');
    if (!canvas || !wrap || !palette) { return; }

    // state.elements entries:
    // {id, type, kind, shape, table_id|null, x, y, w, h, rot, label, pending}
    // Table elements show the REAL table's name/seats/live status (looked
    // up in state.tables); only decorations carry their own label.
    var state = { areaId: 0, areaName: '', hasSavedLayout: false, tables: [], elements: [], selectedId: null, zoom: 1, panX: 0, panY: 0, dirty: false, saving: false, pendingCreates: [] };
    var uidSeq = 0;
    var autosaveTimer = null;
    var savedTimer = null;

    // ---- undo / redo: snapshots of the element list, taken BEFORE each
    // mutation (drag, add, delete, duplicate, rotate, shape change,
    // resize, Clear All, Auto layout, Load demo). Undo/redo restore a
    // snapshot and mark the layout dirty, so autosave persists it.
    var undoStack = [];
    var redoStack = [];
    var UNDO_LIMIT = 30;
    function snapshotElements() { return JSON.stringify(state.elements); }
    function pushHistorySnapshot(json) {
        undoStack.push(json);
        if (undoStack.length > UNDO_LIMIT) { undoStack.shift(); }
        redoStack = [];
        refreshUndoButtons();
    }
    function pushHistory() { pushHistorySnapshot(snapshotElements()); }
    function applyHistorySnapshot(json) {
        state.elements = JSON.parse(json);
        if (state.selectedId && !byId(state.selectedId)) { state.selectedId = null; }
        renderAll();
        markSelection();
        refreshAreaTables();
        markDirty();
    }
    function undoLayout() {
        if (!undoStack.length) { return; }
        redoStack.push(snapshotElements());
        applyHistorySnapshot(undoStack.pop());
        refreshUndoButtons();
    }
    function redoLayout() {
        if (!redoStack.length) { return; }
        undoStack.push(snapshotElements());
        applyHistorySnapshot(redoStack.pop());
        refreshUndoButtons();
    }
    function refreshUndoButtons() {
        var u = document.getElementById('posLayoutUndo');
        var r = document.getElementById('posLayoutRedo');
        if (u) { u.disabled = !undoStack.length; }
        if (r) { r.disabled = !redoStack.length; }
    }
    function resetHistory() {
        undoStack = [];
        redoStack = [];
        refreshUndoButtons();
    }

    // ---- inline confirm / prompt dialog (no native window.confirm or
    // window.alert anywhere in the designer). One modal, filled in per
    // use: a plain confirm shows only a title + buttons; a prompt adds
    // labelled fields and returns their values. onConfirm returning
    // false keeps the modal open (used for validation).
    var layoutModal = document.getElementById('posLayoutModal');
    var layoutModalTitle = document.getElementById('posLayoutModalTitle');
    var layoutModalMessage = document.getElementById('posLayoutModalMessage');
    var layoutModalFields = document.getElementById('posLayoutModalFields');
    var layoutModalConfirmBtn = document.getElementById('posLayoutModalConfirm');
    var layoutModalCancelBtn = document.getElementById('posLayoutModalCancel');
    var layoutModalOnConfirm = null;
    var layoutModalOnCancel = null;
    function layoutModalVisible() { return layoutModal && layoutModal.style.display !== 'none'; }
    function showLayoutModal(opts) {
        layoutModalTitle.textContent = opts.title || '';
        if (opts.message) {
            layoutModalMessage.textContent = opts.message;
            layoutModalMessage.style.display = '';
        } else {
            layoutModalMessage.style.display = 'none';
        }
        layoutModalFields.innerHTML = '';
        (opts.fields || []).forEach(function (f) {
            var wrapEl = document.createElement('div');
            wrapEl.className = 'mb-2';
            var lab = document.createElement('label');
            lab.className = 'form-label';
            lab.textContent = f.label;
            wrapEl.appendChild(lab);
            var input;
            if (f.type === 'select') {
                input = document.createElement('select');
                input.className = 'form-select form-select-sm';
                (f.options || []).forEach(function (o) {
                    var opt = document.createElement('option');
                    opt.value = o.value;
                    opt.textContent = o.label;
                    input.appendChild(opt);
                });
            } else {
                input = document.createElement('input');
                input.className = 'form-control form-control-sm';
                input.type = f.type || 'text';
                if (f.min !== undefined) { input.min = f.min; }
                if (f.max !== undefined) { input.max = f.max; }
            }
            input.id = 'posLayoutModalField-' + f.id;
            if (f.value !== undefined && f.value !== null) { input.value = f.value; }
            lab.htmlFor = input.id;
            wrapEl.appendChild(input);
            layoutModalFields.appendChild(wrapEl);
        });
        layoutModalConfirmBtn.textContent = opts.confirmLabel || I18N.confirmLabel;
        layoutModalCancelBtn.style.display = opts.hideCancel ? 'none' : '';
        layoutModalOnConfirm = opts.onConfirm || null;
        layoutModalOnCancel = opts.onCancel || null;
        layoutModal.style.display = '';
        var firstField = layoutModalFields.querySelector('input, select');
        if (firstField) { firstField.focus(); } else { layoutModalConfirmBtn.focus(); }
    }
    function hideLayoutModal(cancelled) {
        layoutModal.style.display = 'none';
        var cb = cancelled ? layoutModalOnCancel : null;
        layoutModalOnConfirm = null;
        layoutModalOnCancel = null;
        if (cb) { cb(); }
    }
    layoutModalConfirmBtn.addEventListener('click', function () {
        if (!layoutModalOnConfirm) { hideLayoutModal(false); return; }
        var values = {};
        layoutModalFields.querySelectorAll('input, select').forEach(function (input) {
            values[input.id.replace('posLayoutModalField-', '')] = input.value;
        });
        if (layoutModalOnConfirm(values) === false) { return; }
        hideLayoutModal(false);
    });
    layoutModalCancelBtn.addEventListener('click', function () { hideLayoutModal(true); });
    layoutModal.addEventListener('click', function (e) {
        if (e.target === layoutModal) { hideLayoutModal(true); }
    });
    function posLayoutConfirm(message, onConfirm, onCancel) {
        showLayoutModal({ title: message, onConfirm: function () { onConfirm(); }, onCancel: onCancel || null });
    }
    function posLayoutAlert(message) {
        showLayoutModal({ title: message, hideCancel: true, confirmLabel: I18N.okLabel, onConfirm: function () {} });
    }

    function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }
    function normDeg(deg) { var d = deg % 360; return d < 0 ? d + 360 : d; }
    function snap(v) { return Math.round(v / 5) * 5; }
    function num(v, fallback) { var n = Number(v); return isNaN(n) ? (fallback || 0) : n; }
    function nextId() { uidSeq += 1; return 'el-' + Date.now().toString(36) + '-' + uidSeq; }
    function byId(id) { for (var i = 0; i < state.elements.length; i++) { if (state.elements[i].id === id) { return state.elements[i]; } } return null; }
    function selected() { return state.selectedId ? byId(state.selectedId) : null; }
    function nodeFor(id) { return canvas.querySelector('[data-id="' + id + '"]'); }
    function tableById(id) { for (var i = 0; i < state.tables.length; i++) { if (state.tables[i].id === id) { return state.tables[i]; } } return null; }
    function isPlaced(tableId) { return state.elements.some(function (e) { return e.kind === 'table' && e.table_id === tableId; }); }

    function paletteLabel(type) {
        var btn = palette.querySelector('[data-type="' + type + '"] .pos-layout-palette-label');
        return btn ? btn.textContent.trim() : type;
    }

    function shapeForType(type) {
        if (type.indexOf('round') >= 0) { return 'round'; }
        if (type.indexOf('square') >= 0) { return 'square'; }
        if (type.indexOf('banquet') >= 0) { return 'banquet'; }
        return 'rect';
    }

    function typeForShape(shape, seats) {
        if (shape === 'banquet') { return 'table-banquet'; }
        if (shape === 'round') { return seats <= 2 ? 'table-round-2' : (seats <= 4 ? 'table-round-4' : 'table-round-6'); }
        if (shape === 'square') { return seats <= 2 ? 'table-square-2' : 'table-square-4'; }
        return seats <= 4 ? 'table-rect-4' : 'table-rect-6';
    }

    function autoTypeForSeats(seats) {
        return seats > 6 ? 'table-banquet' : typeForShape('round', seats);
    }

    function makeDecoration(type, x, y, rot, w, h, label) {
        var cfg = TYPES[type];
        if (!cfg) { return null; }
        return {
            id: nextId(), type: type, kind: cfg.kind, shape: cfg.shape || null, table_id: null,
            x: snap(x), y: snap(y), w: w || cfg.w, h: h || cfg.h, rot: rot || 0,
            label: label !== undefined && label !== null ? String(label) : paletteLabel(type), pending: false
        };
    }

    function makeTableElement(type, table, x, y, rot) {
        var cfg = TYPES[type];
        if (!cfg) { return null; }
        return {
            id: nextId(), type: type, kind: 'table', shape: cfg.shape, table_id: table ? table.id : null,
            x: snap(x), y: snap(y), w: cfg.w, h: cfg.h, rot: rot || 0,
            label: '', pending: !table
        };
    }

    function chairPositions(el, count) {
        var pts = [];
        var rx = el.w / 2 + 9;
        var ry = el.h / 2 + 9;
        for (var i = 0; i < count; i++) {
            var a = (i / count) * Math.PI * 2 - Math.PI / 2;
            pts.push({
                x: el.w / 2 + Math.cos(a) * rx,
                y: el.h / 2 + Math.sin(a) * ry,
                deg: Math.round(a * 180 / Math.PI) + 90
            });
        }
        return pts;
    }

    function renderNode(el) {
        var node = document.createElement('div');
        node.className = 'pos-layout-el kind-' + el.kind + (el.shape ? ' shape-' + el.shape : '');
        node.dataset.id = el.id;
        node.style.left = el.x + 'px';
        node.style.top = el.y + 'px';
        node.style.width = el.w + 'px';
        node.style.height = el.h + 'px';
        node.style.transform = 'rotate(' + el.rot + 'deg)';

        if (el.kind === 'table') {
            var table = tableById(el.table_id);
            var cfg = TYPES[el.type] || {};
            chairPositions(el, cfg.chairs || 4).forEach(function (c) {
                var chair = document.createElement('span');
                chair.className = 'pos-layout-chair';
                chair.style.left = (c.x - 7) + 'px';
                chair.style.top = (c.y - 4.5) + 'px';
                chair.style.transform = 'rotate(' + c.deg + 'deg)';
                node.appendChild(chair);
            });
            var body = document.createElement('div');
            body.className = 'pos-layout-table-body';
            var num = document.createElement('div');
            num.className = 'pos-layout-table-num';
            num.textContent = table ? table.name : '\u2026';
            var cap = document.createElement('div');
            cap.className = 'pos-layout-table-cap';
            cap.textContent = table ? String(table.capacity) : '';
            var dot = document.createElement('span');
            dot.className = 'pos-layout-dot pos-layout-dot-' + (table ? table.status : 'available');
            body.appendChild(num);
            body.appendChild(cap);
            body.appendChild(dot);
            node.appendChild(body);
        } else if (el.kind === 'wall') {
            node.classList.add('pos-layout-wall-el');
        } else if (el.kind === 'door') {
            node.classList.add('pos-layout-door-el');
        } else if (el.kind === 'window') {
            node.classList.add('pos-layout-window-el');
        } else if (el.kind === 'bar') {
            node.classList.add('pos-layout-bar-el');
            node.textContent = el.label;
            [20, 50, 80].forEach(function (pct) {
                var stool = document.createElement('span');
                stool.className = 'pos-layout-stool';
                stool.style.left = pct + '%';
                stool.style.marginLeft = '-5px';
                node.appendChild(stool);
            });
        } else if (el.kind === 'reception') {
            node.classList.add('pos-layout-reception-el');
            node.innerHTML = '';
            var icon = document.createElement('span');
            icon.innerHTML = PERSON_SVG;
            icon.style.display = 'flex';
            node.appendChild(icon);
            var txt = document.createElement('span');
            txt.textContent = el.label;
            node.appendChild(txt);
        } else if (el.kind === 'plant') {
            node.classList.add('pos-layout-plant-el');
            node.textContent = '\u273f';
        } else if (el.kind === 'waiter') {
            node.classList.add('pos-layout-waiter-el');
            node.innerHTML = PERSON_SVG.replace('width="18" height="18"', 'width="26" height="26"');
        }

        if (state.selectedId === el.id) {
            node.classList.add('selected');
            // No rotate handle on round tables: rotating a circle is
            // invisible, so there is nothing to drag.
            if (!(el.kind === 'table' && el.shape === 'round')) {
                var rot = document.createElement('span');
                rot.className = 'pos-layout-rotate-handle';
                rot.textContent = '\u21bb';
                rot.title = 'Rotate';
                node.appendChild(rot);
            }
            var cfg2 = TYPES[el.type] || {};
            if (cfg2.resizable === 'w') {
                var rh = document.createElement('span');
                rh.className = 'pos-layout-resize-handle pos-layout-resize-e';
                node.appendChild(rh);
            } else if (cfg2.resizable === 'h') {
                var rh2 = document.createElement('span');
                rh2.className = 'pos-layout-resize-handle pos-layout-resize-s';
                node.appendChild(rh2);
            }
        }
        return node;
    }

    function renderAll() {
        canvas.innerHTML = '';
        state.elements.forEach(function (el) {
            canvas.appendChild(renderNode(el));
        });
    }

    function markSelection() {
        canvas.querySelectorAll('.pos-layout-el').forEach(function (node) {
            var isSel = node.dataset.id === state.selectedId;
            node.classList.toggle('selected', isSel);
            node.querySelectorAll('.pos-layout-rotate-handle, .pos-layout-resize-handle').forEach(function (h) { h.remove(); });
            if (isSel) {
                var el = byId(node.dataset.id);
                if (!el) { return; }
                if (!(el.kind === 'table' && el.shape === 'round')) {
                    var rot = document.createElement('span');
                    rot.className = 'pos-layout-rotate-handle';
                    rot.textContent = '\u21bb';
                    node.appendChild(rot);
                }
                var cfg = TYPES[el.type] || {};
                if (cfg.resizable === 'w') {
                    var rh = document.createElement('span');
                    rh.className = 'pos-layout-resize-handle pos-layout-resize-e';
                    node.appendChild(rh);
                } else if (cfg.resizable === 'h') {
                    var rh2 = document.createElement('span');
                    rh2.className = 'pos-layout-resize-handle pos-layout-resize-s';
                    node.appendChild(rh2);
                }
            }
        });
        refreshProps();
    }

    function select(id) {
        state.selectedId = id;
        markSelection();
    }

    var propEmpty = document.getElementById('posLayoutPropsEmpty');
    var propForm = document.getElementById('posLayoutPropsForm');
    var propType = document.getElementById('posLayoutPropType');
    var propShapeWrap = document.getElementById('posLayoutPropShapeWrap');
    var propShape = document.getElementById('posLayoutPropShape');
    var propLabel = document.getElementById('posLayoutPropLabel');
    var propCapacity = document.getElementById('posLayoutPropCapacity');
    var propStatus = document.getElementById('posLayoutPropStatus');
    var propRot = document.getElementById('posLayoutPropRot');
    var propCapacityWrap = document.getElementById('posLayoutPropCapacityWrap');
    var propStatusWrap = document.getElementById('posLayoutPropStatusWrap');
    var propDeleteBtn = document.getElementById('posLayoutPropDelete');
    var rotBtns = Array.prototype.slice.call(document.querySelectorAll('[data-rot]'));

    function refreshProps() {
        var el = selected();
        // Rotating a round table is invisible (a rotated circle looks
        // identical), so the rotation buttons stay disabled for them —
        // and for no selection at all.
        var isRoundTable = !!el && el.kind === 'table' && shapeForType(el.type) === 'round';
        rotBtns.forEach(function (b) { b.disabled = !el || isRoundTable; });
        if (!el) {
            propEmpty.style.display = '';
            propForm.style.display = 'none';
            return;
        }
        propEmpty.style.display = 'none';
        propForm.style.display = '';
        var isTable = el.kind === 'table';
        var table = isTable ? tableById(el.table_id) : null;
        propType.value = isTable ? I18N.table : paletteLabel(el.type);
        propShapeWrap.style.display = isTable ? '' : 'none';
        if (isTable) { propShape.value = shapeForType(el.type); }
        propLabel.value = isTable ? (table ? table.name : '\u2026') : el.label;
        propCapacityWrap.style.display = isTable ? '' : 'none';
        propStatusWrap.style.display = isTable ? '' : 'none';
        propCapacity.value = isTable ? (table ? String(table.capacity) : '') : '';
        propStatus.value = isTable ? (table ? table.status : 'available') : 'available';
        propRot.textContent = normDeg(el.rot) + '\u00b0';
        propDeleteBtn.textContent = isTable ? I18N.removeFromFloor : I18N.deleteLabel;
    }

    function updateNodeText(el) {
        var node = nodeFor(el.id);
        if (!node) { return; }
        if (el.kind === 'table') {
            var table = tableById(el.table_id);
            var num = node.querySelector('.pos-layout-table-num');
            if (num) { num.textContent = table ? table.name : '\u2026'; }
            var cap = node.querySelector('.pos-layout-table-cap');
            if (cap) { cap.textContent = table ? String(table.capacity) : ''; }
            var dot = node.querySelector('.pos-layout-dot');
            if (dot) { dot.className = 'pos-layout-dot pos-layout-dot-' + (table ? table.status : 'available'); }
        } else if (el.kind === 'bar') {
            node.childNodes.forEach(function (child) {
                if (child.nodeType === 3) { child.textContent = el.label; }
            });
        } else if (el.kind === 'reception') {
            var spans = node.querySelectorAll('span');
            var last = spans[spans.length - 1];
            if (last) { last.textContent = el.label; }
        }
    }

    // Table name / seats are edited here and saved to the REAL PosTable
    // (PATCH layout-designer/tables/{id}); the floor keeps referencing
    // the same table id, so the layout JSON never changes for a rename.
    function tableUrl(tableId) {
        return UPDATE_TABLE_URL_TEMPLATE.replace('__TABLE_ID__', String(tableId));
    }
    function saveTableDetails(el) {
        var table = tableById(el.table_id);
        if (!table) { return; }
        var origName = table.name;
        var origCapacity = table.capacity;
        var name = propLabel.value.trim();
        var capacity = clamp(parseInt(propCapacity.value, 10) || table.capacity, 1, 99);
        if (!name) {
            propLabel.value = origName;
            propCapacity.value = String(origCapacity);
            table.name = origName;
            updateNodeText(el);
            return;
        }
        fetch(tableUrl(table.id), {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ name: name, capacity: capacity })
        }).then(function (r) {
            if (!r.ok) { throw new Error('update'); }
            return r.json();
        }).then(function (data) {
            var sizeChanged = data.capacity !== table.capacity;
            table.name = data.name;
            table.capacity = data.capacity;
            propLabel.value = data.name;
            propCapacity.value = String(data.capacity);
            // Seats may change the shape/size mapping (a 6-seat round
            // table is bigger than a 4-seat one).
            if (sizeChanged) {
                var type = typeForShape(shapeForType(el.type), data.capacity);
                var cfg = TYPES[type];
                if (cfg) {
                    el.type = type;
                    el.shape = cfg.shape;
                    el.w = cfg.w;
                    el.h = cfg.h;
                }
            }
            var node = nodeFor(el.id);
            if (node) {
                var fresh = renderNode(el);
                node.replaceWith(fresh);
            }
            markSelection();
            refreshAreaTables();
            if (sizeChanged) { markDirty(); }
        }).catch(function () {
            table.name = origName;
            table.capacity = origCapacity;
            propLabel.value = origName;
            propCapacity.value = String(origCapacity);
            updateNodeText(el);
            showLayoutError(I18N.updateTableFailed);
        });
    }
    var labelEditBefore = null;
    propLabel.addEventListener('focus', function () {
        var el = selected();
        labelEditBefore = (el && el.kind !== 'table') ? snapshotElements() : null;
    });
    propLabel.addEventListener('input', function () {
        var el = selected();
        if (!el) { return; }
        if (el.kind === 'table') {
            var table = tableById(el.table_id);
            if (table) { table.name = propLabel.value; updateNodeText(el); }
            return;
        }
        el.label = propLabel.value;
        updateNodeText(el);
    });
    propLabel.addEventListener('change', function () {
        var el = selected();
        if (!el) { return; }
        if (el.kind === 'table') {
            saveTableDetails(el);
            return;
        }
        if (labelEditBefore) { pushHistorySnapshot(labelEditBefore); labelEditBefore = null; }
        markDirty();
    });
    propCapacity.addEventListener('change', function () {
        var el = selected();
        if (!el || el.kind !== 'table') { return; }
        saveTableDetails(el);
    });
    propShape.addEventListener('change', function () {
        var el = selected();
        if (!el || el.kind !== 'table') { return; }
        var table = tableById(el.table_id);
        var seats = table ? table.capacity : 4;
        var type = typeForShape(propShape.value, seats);
        var cfg = TYPES[type];
        if (!cfg) { return; }
        pushHistory();
        el.type = type;
        el.shape = cfg.shape;
        el.w = cfg.w;
        el.h = cfg.h;
        var node = nodeFor(el.id);
        if (node) {
            var fresh = renderNode(el);
            node.replaceWith(fresh);
        }
        markSelection();
        markDirty();
    });
    rotBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var el = selected();
            if (!el) { return; }
            pushHistory();
            el.rot = normDeg(el.rot + parseInt(btn.dataset.rot, 10));
            var node = nodeFor(el.id);
            if (node) { node.style.transform = 'rotate(' + el.rot + 'deg)'; }
            propRot.textContent = normDeg(el.rot) + '\u00b0';
            markDirty();
        });
    });
    document.getElementById('posLayoutPropDuplicate').addEventListener('click', function () {
        var el = selected();
        if (!el) { return; }
        if (el.kind === 'table') {
            var table = tableById(el.table_id);
            createRealTable(table ? table.capacity : 4).then(function (created) {
                if (!created) { return; }
                pushHistory();
                var copy = makeTableElement(el.type, created, el.x + 24, el.y + 24, el.rot);
                state.elements.push(copy);
                canvas.appendChild(renderNode(copy));
                select(copy.id);
                refreshAreaTables();
                markDirty();
            });
            return;
        }
        pushHistory();
        var copy = JSON.parse(JSON.stringify(el));
        copy.id = nextId();
        copy.x = snap(el.x + 24);
        copy.y = snap(el.y + 24);
        state.elements.push(copy);
        canvas.appendChild(renderNode(copy));
        select(copy.id);
        markDirty();
    });
    propDeleteBtn.addEventListener('click', function () {
        deleteSelected();
    });

    function deleteSelected() {
        var el = selected();
        if (!el) { return; }
        var removeIt = function () {
            pushHistory();
            state.elements = state.elements.filter(function (e) { return e.id !== el.id; });
            var node = nodeFor(el.id);
            if (node) { node.remove(); }
            state.selectedId = null;
            markSelection();
            refreshAreaTables();
            markDirty();
        };
        if (el.kind === 'table') {
            posLayoutConfirm(I18N.confirmRemoveTable, removeIt);
            return;
        }
        removeIt();
    }

    // ---- infinite canvas: pan + zoom (scroll to zoom, drag floor to pan) ----
    var zoomLabel = document.getElementById('posLayoutZoomLabel');
    function applyView() {
        canvas.style.transform = 'translate(' + state.panX + 'px,' + state.panY + 'px) scale(' + state.zoom + ')';
        zoomLabel.textContent = Math.round(state.zoom * 100) + '%';
        var minor = 20 * state.zoom;
        var major = 100 * state.zoom;
        wrap.style.backgroundSize = minor + 'px ' + minor + 'px,' + minor + 'px ' + minor + 'px,' + major + 'px ' + major + 'px,' + major + 'px ' + major + 'px';
        wrap.style.backgroundPosition = state.panX + 'px ' + state.panY + 'px,' + state.panX + 'px ' + state.panY + 'px,' + state.panX + 'px ' + state.panY + 'px,' + state.panX + 'px ' + state.panY + 'px';
    }
    function worldPoint(clientX, clientY) {
        var rect = wrap.getBoundingClientRect();
        return { x: (clientX - rect.left - state.panX) / state.zoom, y: (clientY - rect.top - state.panY) / state.zoom };
    }
    function viewCenter() {
        return { x: (wrap.clientWidth / 2 - state.panX) / state.zoom, y: (wrap.clientHeight / 2 - state.panY) / state.zoom };
    }
    function fitZoom() {
        if (!state.elements.length) {
            state.zoom = 1;
            state.panX = 24;
            state.panY = 24;
            applyView();
            return;
        }
        var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        state.elements.forEach(function (el) {
            minX = Math.min(minX, el.x);
            minY = Math.min(minY, el.y);
            maxX = Math.max(maxX, el.x + el.w);
            maxY = Math.max(maxY, el.y + el.h);
        });
        var bw = Math.max(maxX - minX, 50);
        var bh = Math.max(maxY - minY, 50);
        state.zoom = clamp(Math.min((wrap.clientWidth - 48) / bw, (wrap.clientHeight - 48) / bh), 0.2, 1.6);
        state.panX = (wrap.clientWidth - bw * state.zoom) / 2 - minX * state.zoom;
        state.panY = (wrap.clientHeight - bh * state.zoom) / 2 - minY * state.zoom;
        applyView();
    }
    wrap.addEventListener('wheel', function (e) {
        e.preventDefault();
        var rect = wrap.getBoundingClientRect();
        var mx = e.clientX - rect.left;
        var my = e.clientY - rect.top;
        var factor = e.deltaY < 0 ? 1.12 : 1 / 1.12;
        var nz = clamp(state.zoom * factor, 0.2, 2.5);
        state.panX = mx - (mx - state.panX) * (nz / state.zoom);
        state.panY = my - (my - state.panY) * (nz / state.zoom);
        state.zoom = nz;
        applyView();
    }, { passive: false });
    document.getElementById('posLayoutZoomIn').addEventListener('click', function () {
        zoomAroundCenter(clamp(state.zoom * 1.2, 0.2, 2.5));
    });
    document.getElementById('posLayoutZoomOut').addEventListener('click', function () {
        zoomAroundCenter(clamp(state.zoom / 1.2, 0.2, 2.5));
    });
    function zoomAroundCenter(nz) {
        var mx = wrap.clientWidth / 2;
        var my = wrap.clientHeight / 2;
        state.panX = mx - (mx - state.panX) * (nz / state.zoom);
        state.panY = my - (my - state.panY) * (nz / state.zoom);
        state.zoom = nz;
        applyView();
    }
    document.getElementById('posLayoutZoomFit').addEventListener('click', fitZoom);
    var gridBtn = document.getElementById('posLayoutGridToggle');
    gridBtn.addEventListener('click', function () {
        var off = wrap.classList.toggle('no-grid');
        gridBtn.classList.toggle('active', !off);
        gridBtn.setAttribute('aria-pressed', String(!off));
    });

    // ---- adding elements: palette drag/click + area tables list ----
    function addDecoration(type, cx, cy) {
        var cfg = TYPES[type];
        if (!cfg) { return null; }
        var el;
        if (cx === null || cx === undefined || cy === null || cy === undefined) {
            var c = viewCenter();
            el = makeDecoration(type, c.x - cfg.w / 2, c.y - cfg.h / 2, 0);
        } else {
            el = makeDecoration(type, cx - cfg.w / 2, cy - cfg.h / 2, 0);
        }
        if (!el) { return null; }
        pushHistory();
        state.elements.push(el);
        canvas.appendChild(renderNode(el));
        return el;
    }

    function placeExistingTable(table, cx, cy) {
        var type = autoTypeForSeats(table.capacity);
        var cfg = TYPES[type];
        var el;
        if (cx === null || cx === undefined || cy === null || cy === undefined) {
            var c = viewCenter();
            el = makeTableElement(type, table, c.x - cfg.w / 2, c.y - cfg.h / 2, 0);
        } else {
            el = makeTableElement(type, table, cx - cfg.w / 2, cy - cfg.h / 2, 0);
        }
        if (!el) { return null; }
        pushHistory();
        state.elements.push(el);
        canvas.appendChild(renderNode(el));
        refreshAreaTables();
        return el;
    }

    function createRealTable(seats, name) {
        var body = { area_id: state.areaId, capacity: seats };
        if (name) { body.name = name; }
        var p = fetch(CREATE_TABLE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body)
        }).then(function (r) {
            if (!r.ok) { throw new Error('create'); }
            return r.json();
        }).then(function (data) {
            state.tables.push({ id: data.id, name: data.name, capacity: data.capacity, status: data.status });
            return data;
        }).catch(function () {
            posLayoutAlert(I18N.createFailed);
            return null;
        });
        state.pendingCreates.push(p);
        p.finally(function () {
            state.pendingCreates = state.pendingCreates.filter(function (q) { return q !== p; });
        });
        return p;
    }

    function addNewTable(type, cx, cy) {
        var cfg = TYPES[type];
        if (!cfg) { return; }
        var el = makeTableElement(type, null, cx !== null && cx !== undefined ? cx - cfg.w / 2 : 0, cy !== null && cy !== undefined ? cy - cfg.h / 2 : 0, 0);
        if (cx === null || cx === undefined || cy === null || cy === undefined) {
            var c = viewCenter();
            el.x = snap(c.x - cfg.w / 2);
            el.y = snap(c.y - cfg.h / 2);
        }
        pushHistory();
        state.elements.push(el);
        canvas.appendChild(renderNode(el));
        select(el.id);
        createRealTable(cfg.seats).then(function (created) {
            if (!created) {
                state.elements = state.elements.filter(function (e) { return e.id !== el.id; });
                var node = nodeFor(el.id);
                if (node) { node.remove(); }
                if (state.selectedId === el.id) { select(null); }
                return;
            }
            el.table_id = created.id;
            el.pending = false;
            updateNodeText(el);
            refreshAreaTables();
            markDirty();
        });
        return el;
    }

    palette.querySelectorAll('.pos-layout-palette-btn').forEach(function (btn) {
        btn.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            var type = btn.dataset.type;
            var cfg = TYPES[type];
            if (!cfg) { return; }
            var created = null;
            function onMove(ev) {
                if (!created) {
                    var pos = worldPoint(ev.clientX, ev.clientY);
                    var rect = wrap.getBoundingClientRect();
                    if (ev.clientX < rect.left || ev.clientX > rect.right || ev.clientY < rect.top || ev.clientY > rect.bottom) { return; }
                    created = cfg.kind === 'table' ? addNewTable(type, pos.x, pos.y) : addDecoration(type, pos.x, pos.y);
                    if (created) { select(created.id); }
                } else if (created.kind !== 'table' || !created.pending) {
                    var p2 = worldPoint(ev.clientX, ev.clientY);
                    created.x = snap(p2.x - created.w / 2);
                    created.y = snap(p2.y - created.h / 2);
                    var node = nodeFor(created.id);
                    if (node) { node.style.left = created.x + 'px'; node.style.top = created.y + 'px'; }
                }
            }
            function onUp() {
                document.removeEventListener('pointermove', onMove);
                document.removeEventListener('pointerup', onUp);
                if (!created) {
                    created = cfg.kind === 'table' ? addNewTable(type, null, null) : addDecoration(type, null, null);
                    if (created) { select(created.id); }
                }
                if (created && created.kind !== 'table') { markDirty(); }
            }
            document.addEventListener('pointermove', onMove);
            document.addEventListener('pointerup', onUp);
        });
    });

    var areaSearchEl = document.getElementById('posLayoutAreaSearch');
    function refreshAreaTables() {
        if (!areaTablesEl) { return; }
        areaTablesEl.innerHTML = '';
        if (!state.tables.length) {
            var empty = document.createElement('div');
            empty.className = 'text-muted small';
            empty.textContent = @json(__('No tables in this area yet.'));
            areaTablesEl.appendChild(empty);
            return;
        }
        var query = areaSearchEl ? areaSearchEl.value.trim().toLowerCase() : '';
        var allPlaced = true;
        var shown = 0;
        state.tables.forEach(function (t) {
            var placed = isPlaced(t.id);
            if (!placed) { allPlaced = false; }
            if (query && String(t.name).toLowerCase().indexOf(query) === -1) { return; }
            shown += 1;
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'pos-layout-palette-btn pos-layout-area-table-btn';
            btn.dataset.tableId = t.id;
            var name = document.createElement('span');
            name.className = 'pos-layout-palette-label';
            name.textContent = t.name + ' (' + t.capacity + ')';
            btn.appendChild(name);
            if (placed) {
                var badge = document.createElement('span');
                badge.className = 'pos-layout-onfloor';
                badge.textContent = I18N.onFloor;
                btn.appendChild(badge);
                // A placed table is not draggable from the list, but
                // clicking its row selects it on the canvas and centres
                // the view on it — no hunting for it on a big floor.
                btn.title = I18N.showOnFloor;
                btn.addEventListener('click', function () {
                    var el = null;
                    state.elements.forEach(function (e) {
                        if (e.kind === 'table' && e.table_id === t.id) { el = e; }
                    });
                    if (!el) { return; }
                    select(el.id);
                    state.panX = (wrap.clientWidth / 2) - (el.x + el.w / 2) * state.zoom;
                    state.panY = (wrap.clientHeight / 2) - (el.y + el.h / 2) * state.zoom;
                    applyView();
                });
            }
            btn.addEventListener('pointerdown', function (e) {
                if (placed) { return; }
                e.preventDefault();
                var created = null;
                function onMove(ev) {
                    if (!created) {
                        var rect = wrap.getBoundingClientRect();
                        if (ev.clientX < rect.left || ev.clientX > rect.right || ev.clientY < rect.top || ev.clientY > rect.bottom) { return; }
                        var pos = worldPoint(ev.clientX, ev.clientY);
                        created = placeExistingTable(t, pos.x, pos.y);
                        if (created) { select(created.id); }
                    } else {
                        var p2 = worldPoint(ev.clientX, ev.clientY);
                        created.x = snap(p2.x - created.w / 2);
                        created.y = snap(p2.y - created.h / 2);
                        var node = nodeFor(created.id);
                        if (node) { node.style.left = created.x + 'px'; node.style.top = created.y + 'px'; }
                    }
                }
                function onUp() {
                    document.removeEventListener('pointermove', onMove);
                    document.removeEventListener('pointerup', onUp);
                    if (!created) {
                        created = placeExistingTable(t, null, null);
                        if (created) { select(created.id); }
                    }
                    if (created) { markDirty(); }
                }
                document.addEventListener('pointermove', onMove);
                document.addEventListener('pointerup', onUp);
            });
            areaTablesEl.appendChild(btn);
        });
        if (!shown) {
            var none = document.createElement('div');
            none.className = 'text-muted small';
            none.textContent = @json(__('No tables match.'));
            areaTablesEl.appendChild(none);
            return;
        }
        if (!query && allPlaced) {
            var done = document.createElement('div');
            done.className = 'text-muted small';
            done.textContent = @json(__('All tables are on the floor.'));
            areaTablesEl.appendChild(done);
        }
    }
    if (areaSearchEl) {
        areaSearchEl.addEventListener('input', refreshAreaTables);
    }

    // ---- canvas interaction: move / rotate / resize / pan ----
    wrap.addEventListener('pointerdown', function (e) {
        var rotHandle = e.target.closest ? e.target.closest('.pos-layout-rotate-handle') : null;
        if (rotHandle) { startRotate(e, rotHandle); return; }
        var resHandle = e.target.closest ? e.target.closest('.pos-layout-resize-handle') : null;
        if (resHandle) { startResize(e, resHandle); return; }
        var elNode = e.target.closest ? e.target.closest('.pos-layout-el') : null;
        if (elNode) { startMove(e, elNode); return; }
        startPan(e);
    });

    function startPan(e) {
        e.preventDefault();
        var startX = e.clientX;
        var startY = e.clientY;
        var ox = state.panX;
        var oy = state.panY;
        var moved = false;
        function onMove(ev) {
            var dx = ev.clientX - startX;
            var dy = ev.clientY - startY;
            if (Math.abs(dx) + Math.abs(dy) > 2) { moved = true; }
            state.panX = ox + dx;
            state.panY = oy + dy;
            applyView();
        }
        function onUp() {
            document.removeEventListener('pointermove', onMove);
            document.removeEventListener('pointerup', onUp);
            if (!moved) { select(null); }
        }
        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', onUp);
    }

    function startMove(e, node) {
        e.preventDefault();
        var id = node.dataset.id;
        select(id);
        var el = byId(id);
        if (!el) { return; }
        var before = snapshotElements();
        var startX = e.clientX;
        var startY = e.clientY;
        var ox = el.x;
        var oy = el.y;
        var moved = false;
        try { node.setPointerCapture(e.pointerId); } catch (err) {}
        function onMove(ev) {
            var dx = (ev.clientX - startX) / state.zoom;
            var dy = (ev.clientY - startY) / state.zoom;
            if (Math.abs(dx) + Math.abs(dy) > 2) { moved = true; }
            el.x = snap(ox + dx);
            el.y = snap(oy + dy);
            node.style.left = el.x + 'px';
            node.style.top = el.y + 'px';
        }
        function onUp() {
            node.removeEventListener('pointermove', onMove);
            node.removeEventListener('pointerup', onUp);
            if (moved) { pushHistorySnapshot(before); markDirty(); }
        }
        node.addEventListener('pointermove', onMove);
        node.addEventListener('pointerup', onUp);
    }

    function startRotate(e, handle) {
        e.preventDefault();
        e.stopPropagation();
        var node = handle.closest('.pos-layout-el');
        if (!node) { return; }
        var el = byId(node.dataset.id);
        if (!el) { return; }
        var before = snapshotElements();
        var startRot = el.rot;
        var rect = node.getBoundingClientRect();
        var cx = rect.left + rect.width / 2;
        var cy = rect.top + rect.height / 2;
        try { handle.setPointerCapture(e.pointerId); } catch (err) {}
        function onMove(ev) {
            var ang = Math.atan2(ev.clientY - cy, ev.clientX - cx) * 180 / Math.PI + 90;
            el.rot = normDeg(Math.round(ang / 5) * 5);
            node.style.transform = 'rotate(' + el.rot + 'deg)';
            propRot.textContent = normDeg(el.rot) + '\u00b0';
        }
        function onUp() {
            handle.removeEventListener('pointermove', onMove);
            handle.removeEventListener('pointerup', onUp);
            if (el.rot !== startRot) { pushHistorySnapshot(before); }
            markDirty();
        }
        handle.addEventListener('pointermove', onMove);
        handle.addEventListener('pointerup', onUp);
    }

    function startResize(e, handle) {
        e.preventDefault();
        e.stopPropagation();
        var node = handle.closest('.pos-layout-el');
        if (!node) { return; }
        var el = byId(node.dataset.id);
        if (!el) { return; }
        var before = snapshotElements();
        var startX = e.clientX;
        var startY = e.clientY;
        var startW = el.w;
        var startH = el.h;
        var horizontal = handle.classList.contains('pos-layout-resize-e');
        try { handle.setPointerCapture(e.pointerId); } catch (err) {}
        function onMove(ev) {
            var dx = (ev.clientX - startX) / state.zoom;
            var dy = (ev.clientY - startY) / state.zoom;
            if (horizontal) {
                el.w = clamp(Math.round((startW + dx) / 10) * 10, 40, 5000);
                node.style.width = el.w + 'px';
            } else {
                el.h = clamp(Math.round((startH + dy) / 10) * 10, 40, 5000);
                node.style.height = el.h + 'px';
            }
        }
        function onUp() {
            handle.removeEventListener('pointermove', onMove);
            handle.removeEventListener('pointerup', onUp);
            if (el.w !== startW || el.h !== startH) { pushHistorySnapshot(before); }
            markDirty();
        }
        handle.addEventListener('pointermove', onMove);
        handle.addEventListener('pointerup', onUp);
    }

    document.addEventListener('keydown', function (e) {
        // Escape closes the confirm/prompt dialog first, if it is open.
        if (e.key === 'Escape' && layoutModalVisible()) {
            e.preventDefault();
            hideLayoutModal(true);
            return;
        }
        var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
        if (tag === 'input' || tag === 'select' || tag === 'textarea') { return; }
        if ((e.ctrlKey || e.metaKey) && (e.key === 'z' || e.key === 'Z')) {
            e.preventDefault();
            if (e.shiftKey) { redoLayout(); } else { undoLayout(); }
            return;
        }
        if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || e.key === 'Y')) {
            e.preventDefault();
            redoLayout();
            return;
        }
        if (e.key === 'Delete' || e.key === 'Backspace') {
            if (state.selectedId) { e.preventDefault(); deleteSelected(); }
        } else if (e.key === 'Escape') {
            select(null);
        }
    });

    document.getElementById('posLayoutUndo').addEventListener('click', undoLayout);
    document.getElementById('posLayoutRedo').addEventListener('click', redoLayout);

    // ---- save / dirty state (server is the source of truth) ----
    // Autosave is the only save path (there is no Save button): the
    // indicator shows Unsaved changes while edits wait, Saving... while
    // a save is in flight, Saved after success, and the error text on
    // failure — clicking the error retries the save.
    var savedInd = document.getElementById('posLayoutSaved');
    var saveFailed = false;
    var saveFailedRetryable = false;
    var saveErrorText = '';
    function refreshSavedIndicator() {
        if (saveFailed) {
            savedInd.style.display = '';
            savedInd.textContent = saveErrorText || I18N.saveFailed;
            savedInd.className = 'small text-danger';
            savedInd.style.cursor = saveFailedRetryable ? 'pointer' : '';
            savedInd.title = saveFailedRetryable ? I18N.retrySave : '';
            return;
        }
        savedInd.style.cursor = '';
        savedInd.title = '';
        if (state.saving) {
            savedInd.style.display = '';
            savedInd.textContent = I18N.saving;
            savedInd.className = 'small text-muted';
        } else if (state.dirty) {
            savedInd.style.display = '';
            savedInd.textContent = I18N.unsaved;
            savedInd.className = 'small text-warning';
        } else {
            savedInd.style.display = '';
            savedInd.textContent = I18N.saved;
            savedInd.className = 'small text-success';
        }
    }
    function showLayoutError(message, retryable) {
        saveFailed = true;
        saveFailedRetryable = !!retryable;
        saveErrorText = message || I18N.saveFailed;
        refreshSavedIndicator();
    }
    function clearSaveError() {
        if (!saveFailed) { return; }
        saveFailed = false;
        saveFailedRetryable = false;
        saveErrorText = '';
    }
    savedInd.addEventListener('click', function () {
        if (saveFailed && saveFailedRetryable) {
            saveToServer(true);
        }
    });
    function showSaved() {
        refreshSavedIndicator();
        if (savedTimer) { clearTimeout(savedTimer); }
        savedTimer = setTimeout(function () { if (!state.dirty && !saveFailed) { savedInd.style.display = 'none'; } }, 1500);
    }
    function markDirty() {
        if (!state.areaId) { return; }
        state.dirty = true;
        refreshSavedIndicator();
        if (autosaveTimer) { clearTimeout(autosaveTimer); }
        autosaveTimer = setTimeout(function () { saveToServer(false); }, 1200);
    }
    function serialize() {
        return { version: 1, elements: state.elements.filter(function (el) { return !el.pending; }).map(function (el) {
            var o = { type: el.type, x: Math.round(el.x), y: Math.round(el.y), w: el.w, h: el.h, rot: Math.round(el.rot) };
            if (el.kind === 'table') { o.table_id = el.table_id; }
            else { o.label = el.label; }
            return o;
        }) };
    }
    function saveToServer(showIndicator) {
        if (!state.areaId) { return Promise.resolve(false); }
        clearSaveError();
        state.saving = true;
        refreshSavedIndicator();
        return Promise.all(state.pendingCreates.slice()).then(function () {
            return fetch(areaUrl(state.areaId), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ layout: serialize() })
            });
        }).then(function (r) {
            if (!r.ok) { throw new Error('save'); }
            return r.json();
        }).then(function () {
            state.saving = false;
            state.dirty = false;
            state.hasSavedLayout = true;
            refreshSavedIndicator();
            refreshDemoButton();
            if (showIndicator) { showSaved(); }
            return true;
        }).catch(function () {
            state.saving = false;
            showLayoutError(I18N.saveFailed, true);
            return false;
        });
    }
    document.getElementById('posLayoutClear').addEventListener('click', function () {
        if (!state.elements.length) { return; }
        posLayoutConfirm(I18N.confirmClear, function () {
            pushHistory();
            state.elements = [];
            state.selectedId = null;
            renderAll();
            markSelection();
            refreshAreaTables();
            markDirty();
        });
    });

    // ---- auto layout: one simple square floor for every table in the area ----
    document.getElementById('posLayoutAuto').addEventListener('click', function () {
        if (!state.areaId) { return; }
        if (!state.tables.length) { posLayoutAlert(I18N.noTables); return; }
        if (state.hasSavedLayout) {
            posLayoutConfirm(I18N.confirmAutoLayout, buildAutoLayout);
            return;
        }
        buildAutoLayout();
    });
    function buildAutoLayout() {
        // The same square template for every area: all of the area's real
        // tables in a neat grid (columns = ceil(sqrt(n))), one door
        // centred at the bottom edge, a plant at each top corner, and a
        // waiter station by the door. Tables keep their real id, name and
        // capacity; the type follows the capacity (2 round-2, 4 round-4,
        // 6 round-6, 8+ banquet) via autoTypeForSeats.
        var tables = state.tables.slice().sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); });
        var n = tables.length;
        var maxW = 0, maxH = 0;
        tables.forEach(function (t) {
            var cfg = TYPES[autoTypeForSeats(t.capacity)];
            if (cfg) { maxW = Math.max(maxW, cfg.w); maxH = Math.max(maxH, cfg.h); }
        });
        // ~200px between table centres; widened only when the biggest
        // table (a banquet is 220 wide) would otherwise overlap its
        // neighbours.
        var step = Math.max(200, maxW + 40, maxH + 40);
        var cols = Math.ceil(Math.sqrt(n));
        var rows = Math.ceil(n / cols);
        var gridW = cols * step;
        var gridH = rows * step;
        var x0 = -gridW / 2;
        var y0 = -gridH / 2;
        var els = [];
        tables.forEach(function (t, i) {
            var type = autoTypeForSeats(t.capacity);
            var cfg = TYPES[type];
            var cx = x0 + (i % cols) * step + step / 2;
            var cy = y0 + Math.floor(i / cols) * step + step / 2;
            els.push(makeTableElement(type, t, cx - cfg.w / 2, cy - cfg.h / 2, 0));
        });
        // Gate: one door centred at the bottom edge of the grid.
        els.push(makeDecoration('door', -45, gridH / 2 + 36, 0));
        // Plants at the two top corners of the grid.
        els.push(makeDecoration('plant', x0 - 70, y0 - 70, 0));
        els.push(makeDecoration('plant', x0 + gridW + 24, y0 - 70, 0));
        // Waiter station by the door, on its right.
        els.push(makeDecoration('waiter', 90, gridH / 2 + 36, 0));
        pushHistory();
        state.elements = els.filter(function (el) { return el !== null; });
        state.selectedId = null;
        renderAll();
        markSelection();
        refreshAreaTables();
        fitZoom();
        // Save through the existing server path (autosave), so the new
        // floor lands on the server without extra clicks and the Saved
        // indicator shows it saved.
        state.dirty = true;
        refreshSavedIndicator();
        saveToServer(true);
    }

    // ---- demo floor (only for an area with no saved layout) ----
    var demoBtn = document.getElementById('posLayoutLoadDemo');
    function refreshDemoButton() {
        demoBtn.style.display = state.hasSavedLayout ? 'none' : '';
    }
    demoBtn.addEventListener('click', function () {
        posLayoutConfirm(I18N.confirmDemo, loadDemo);
    });
    function demoDefs() {
        // [type, x, y, rot, seats, w, h]
        return [
            ['wall-h', 40, 30, 0, 0, 480, 10],
            ['wall-h', 680, 30, 0, 0, 480, 10],
            ['door', 520, 26, 0, 0, 160, 14],
            ['wall-h', 40, 750, 0, 0, 1120, 10],
            ['wall-v', 40, 30, 0, 0, 10, 720],
            ['wall-v', 1150, 30, 0, 0, 10, 720],
            ['window', 300, 746, 0, 0, 110, 10],
            ['window', 760, 746, 0, 0, 110, 10],
            ['table-round-4', 170, 100, 0, 4],
            ['table-square-2', 320, 100, 0, 2],
            ['table-round-2', 470, 100, 0, 2],
            ['table-rect-4', 620, 95, 0, 4],
            ['table-square-4', 820, 95, 0, 4],
            ['table-round-6', 220, 300, 0, 6],
            ['table-rect-6', 470, 300, 0, 6],
            ['table-round-4', 760, 300, 0, 4],
            ['table-banquet', 280, 520, 0, 12],
            ['table-banquet', 610, 520, 0, 12],
            ['bar', 1000, 170, 90, 0],
            ['reception', 880, 210, 0, 0],
            ['plant', 120, 650, 0, 0],
            ['plant', 1090, 650, 0, 0],
            ['plant', 600, 690, 0, 0],
            ['waiter', 90, 430, 0, 0]
        ];
    }
    function loadDemo() {
        var defs = demoDefs();
        var tableDefs = defs.filter(function (d) { return TYPES[d[0]].kind === 'table'; });
        var existing = state.tables.slice().sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); });
        var chain = Promise.resolve();
        for (var i = existing.length; i < tableDefs.length; i++) {
            (function (seats) {
                chain = chain.then(function () { return createRealTable(seats); });
            })(tableDefs[i][4]);
        }
        chain.then(function () {
            state.tables.sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); });
            var els = [];
            var ti = 0;
            defs.forEach(function (d) {
                var cfg = TYPES[d[0]];
                if (cfg.kind === 'table') {
                    var table = state.tables[ti];
                    ti += 1;
                    if (!table) { return; }
                    els.push(makeTableElement(d[0], table, d[1], d[2], d[3]));
                } else {
                    els.push(makeDecoration(d[0], d[1], d[2], d[3], d[5] || null, d[6] || null));
                }
            });
            pushHistory();
            state.elements = els.filter(function (el) { return el !== null; });
            state.selectedId = null;
            renderAll();
            markSelection();
            refreshAreaTables();
            markDirty();
            fitZoom();
        });
    }

    // ---- area loading / switching ----
    function areaUrl(areaId) {
        return AREA_URL_TEMPLATE.replace('__AREA_ID__', String(areaId));
    }
    function updateAreaHeader() {
        var area = null;
        AREAS.forEach(function (a) { if (a.id === state.areaId) { area = a; } });
        areaNameEl.textContent = area ? (I18N.floorDesign + ' \u2014 ' + area.name + (area.branch_name ? ' (' + area.branch_name + ')' : '')) : '';
    }
    function buildElements(layout) {
        if (!layout || !Array.isArray(layout.elements)) { return []; }
        var els = [];
        layout.elements.forEach(function (e) {
            var cfg = TYPES[e.type];
            if (!cfg) { return; }
            if (cfg.kind === 'table') {
                var table = tableById(e.table_id);
                if (!table) { return; }
                els.push(makeTableElement(e.type, table, num(e.x), num(e.y), num(e.rot)));
            } else {
                els.push(makeDecoration(e.type, num(e.x), num(e.y), num(e.rot), num(e.w, cfg.w), num(e.h, cfg.h), e.label));
            }
        });
        return els.filter(function (el) { return el !== null; });
    }
    function loadArea(areaId) {
        if (!areaId) {
            state.areaId = 0;
            state.elements = [];
            state.tables = [];
            resetHistory();
            renderAll();
            refreshAreaTables();
            updateAreaHeader();
            return Promise.resolve();
        }
        return fetch(areaUrl(areaId), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) { throw new Error('load'); } return r.json(); })
            .then(function (data) {
                state.areaId = data.area.id;
                // Remember the area being designed (UI state only — the
                // layout itself lives on the server) so a reload reopens it.
                try { window.localStorage.setItem('erp-pos-layout-last-area', String(data.area.id)); } catch (e) {}
                state.areaName = data.area.name;
                state.tables = data.tables || [];
                state.hasSavedLayout = !!(data.layout && Array.isArray(data.layout.elements));
                state.elements = buildElements(data.layout);
                state.selectedId = null;
                state.dirty = false;
                resetHistory();
                renderAll();
                markSelection();
                refreshAreaTables();
                updateAreaHeader();
                refreshSavedIndicator();
                refreshDemoButton();
                fitZoom();
            })
            .catch(function () {
                posLayoutAlert(I18N.loadFailed);
            });
    }
    areaSelect.addEventListener('change', function () {
        var nextId = parseInt(areaSelect.value, 10) || 0;
        if (nextId === state.areaId) { return; }
        var switchTo = function () {
            if (autosaveTimer) { clearTimeout(autosaveTimer); }
            loadArea(nextId);
        };
        if (state.dirty) {
            // Show the old area again while the dialog is open; only
            // switch once the user confirms the discard.
            areaSelect.value = String(state.areaId);
            posLayoutConfirm(I18N.confirmDiscard, switchTo, function () {
                areaSelect.value = String(state.areaId);
            });
            return;
        }
        switchTo();
    });

    // ---- create a named table / a new area from inside the designer ----
    document.getElementById('posLayoutNewTable').addEventListener('click', function () {
        if (!state.areaId) { return; }
        showLayoutModal({
            title: I18N.newTable,
            fields: [
                { id: 'name', label: I18N.tableName, type: 'text', value: '' },
                { id: 'capacity', label: I18N.seats, type: 'number', value: 4, min: 1, max: 99 }
            ],
            confirmLabel: I18N.createLabel,
            onConfirm: function (values) {
                var name = String(values.name || '').trim();
                var capacity = clamp(parseInt(values.capacity, 10) || 4, 1, 99);
                if (!name) {
                    var nameInput = document.getElementById('posLayoutModalField-name');
                    if (nameInput) { nameInput.classList.add('is-invalid'); nameInput.focus(); }
                    return false;
                }
                createRealTable(capacity, name).then(function (created) {
                    if (!created) { return; }
                    var el = placeExistingTable(created, null, null);
                    if (el) { select(el.id); markDirty(); }
                });
            }
        });
    });
    var newAreaBtn = document.getElementById('posLayoutNewArea');
    if (newAreaBtn && !BRANCHES.length) { newAreaBtn.disabled = true; }
    if (newAreaBtn) {
        newAreaBtn.addEventListener('click', function () {
            var currentArea = null;
            AREAS.forEach(function (a) { if (a.id === state.areaId) { currentArea = a; } });
            showLayoutModal({
                title: I18N.newArea,
                fields: [
                    { id: 'name', label: I18N.nameLabel, type: 'text', value: '' },
                    { id: 'branch_id', label: I18N.branchLabel, type: 'select', value: currentArea ? currentArea.branch_id : (BRANCHES[0] ? BRANCHES[0].id : ''), options: BRANCHES.map(function (b) { return { value: b.id, label: b.name }; }) }
                ],
                confirmLabel: I18N.createLabel,
                onConfirm: function (values) {
                    var name = String(values.name || '').trim();
                    if (!name) {
                        var nameInput = document.getElementById('posLayoutModalField-name');
                        if (nameInput) { nameInput.classList.add('is-invalid'); nameInput.focus(); }
                        return false;
                    }
                    fetch(CREATE_AREA_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ name: name, branch_id: parseInt(values.branch_id, 10) || 0 })
                    }).then(function (r) {
                        if (!r.ok) { throw new Error('create-area'); }
                        return r.json();
                    }).then(function (data) {
                        AREAS.push({ id: data.id, name: data.name, branch_id: data.branch_id, branch_name: data.branch_name });
                        var opt = document.createElement('option');
                        opt.value = String(data.id);
                        opt.textContent = data.name + (data.branch_name ? ' \u2014 ' + data.branch_name : '');
                        areaSelect.appendChild(opt);
                        areaSelect.disabled = false;
                        noAreasEl.style.display = 'none';
                        areaSelect.value = String(data.id);
                        if (autosaveTimer) { clearTimeout(autosaveTimer); }
                        loadArea(data.id);
                    }).catch(function () {
                        posLayoutAlert(I18N.createAreaFailed);
                    });
                }
            });
        });
    }

    // ---- boot ----
    if (!AREAS.length) {
        noAreasEl.style.display = '';
        areaSelect.disabled = true;
        applyView();
        refreshSavedIndicator();
        savedInd.style.display = 'none';
        refreshDemoButton();
    } else {
        // Reopen the area the user was designing last (UI state only —
        // the layout itself lives on the server). Fall back to the
        // server-provided initial area, then the first area.
        var rememberedAreaId = 0;
        try { rememberedAreaId = parseInt(window.localStorage.getItem('erp-pos-layout-last-area'), 10) || 0; } catch (e) { rememberedAreaId = 0; }
        var bootAreaId = (rememberedAreaId && AREAS.some(function (a) { return a.id === rememberedAreaId; })) ? rememberedAreaId : (INITIAL_AREA_ID || AREAS[0].id);
        areaSelect.value = String(bootAreaId);
        loadArea(bootAreaId);
    }
})();
</script>
@endpush
