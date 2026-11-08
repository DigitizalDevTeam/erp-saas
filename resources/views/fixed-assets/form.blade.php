@extends('layouts.admin')
@section('page-title')
    {{ $asset->exists ? __('Edit Asset') : __('New Asset') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-assets.index') }}">{{ __('Asset Register') }}</a></li>
    <li class="breadcrumb-item">{{ $asset->exists ? __('Edit') : __('New') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('fixed-assets.index') }}" class="btn btn-sm btn-secondary">{{ __('Back to Register') }}</a>
    </div>
@endsection

@php
    $isEdit = $asset->exists;
    $oldComponents = collect(old('components', []))->keyBy('component_type');
    $componentRow = function ($type) use ($oldComponents, $costs) {
        if ($oldComponents->has($type)) {
            $row = $oldComponents->get($type);
            return ['gross_amount' => $row['gross_amount'] ?? '', 'vat_amount' => $row['vat_amount'] ?? '', 'vat_recoverable' => ! empty($row['vat_recoverable']), 'source_document_reference' => $row['source_document_reference'] ?? '', 'notes' => $row['notes'] ?? ''];
        }
        if (isset($costs[$type])) {
            $cost = $costs[$type];
            return ['gross_amount' => $cost->gross_amount, 'vat_amount' => $cost->vat_amount, 'vat_recoverable' => (bool) $cost->vat_recoverable, 'source_document_reference' => $cost->source_document_reference, 'notes' => $cost->notes];
        }
        return ['gross_amount' => '', 'vat_amount' => '', 'vat_recoverable' => false, 'source_document_reference' => '', 'notes' => ''];
    };
    $componentLabels = \App\Models\FixedAssetAcquisitionCost::labels();
@endphp

@section('content')
    <form method="POST" action="{{ $isEdit ? route('fixed-assets.update', $asset->id) : route('fixed-assets.store') }}">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header"><h5>{{ __('Asset Details') }}</h5></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="category_id">{{ __('Category') }} *</label>
                                    <select name="category_id" id="category_id" class="form-control @error('category_id') is-invalid @enderror" required>
                                        <option value="">{{ __('Select category') }}</option>
                                        @foreach ($categories as $category)
                                            <option value="{{ $category->id }}" data-life="{{ $category->default_useful_life }}" data-unit="{{ $category->default_life_unit }}" {{ (string) old('category_id', $asset->category_id) === (string) $category->id ? 'selected' : '' }}>{{ $category->code }} — {{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('category_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="name">{{ __('Asset Name') }} *</label>
                                    <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $asset->name) }}" maxlength="255" required>
                                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="description">{{ __('Description') }}</label>
                            <textarea name="description" id="description" class="form-control" rows="2">{{ old('description', $asset->description) }}</textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="serial_number">{{ __('Serial Number') }}</label>
                                    <input type="text" name="serial_number" id="serial_number" class="form-control" value="{{ old('serial_number', $asset->serial_number) }}" maxlength="100">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="brand">{{ __('Brand') }}</label>
                                    <input type="text" name="brand" id="brand" class="form-control" value="{{ old('brand', $asset->brand) }}" maxlength="100">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="model">{{ __('Model') }}</label>
                                    <input type="text" name="model" id="model" class="form-control" value="{{ old('model', $asset->model) }}" maxlength="100">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="parent_asset_id">{{ __('Parent Asset (component of)') }}</label>
                                    <select name="parent_asset_id" id="parent_asset_id" class="form-control">
                                        <option value="">{{ __('None — standalone asset') }}</option>
                                        @foreach ($parents as $parent)
                                            <option value="{{ $parent->id }}" {{ (string) old('parent_asset_id', $asset->parent_asset_id) === (string) $parent->id ? 'selected' : '' }}>{{ $parent->code }} — {{ $parent->name }}</option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">{{ __('A component is depreciated with its parent asset.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="barcode_value">{{ __('Barcode Value') }}</label>
                                    <input type="text" name="barcode_value" id="barcode_value" class="form-control" value="{{ old('barcode_value', $asset->barcode_value) }}" maxlength="255">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="branch_id">{{ __('Branch') }}</label>
                                    <select name="branch_id" id="branch_id" class="form-control">
                                        <option value="">{{ __('None') }}</option>
                                        @foreach ($branches as $branch)
                                            <option value="{{ $branch->id }}" {{ (string) old('branch_id', $asset->branch_id) === (string) $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="department_id">{{ __('Department') }}</label>
                                    <select name="department_id" id="department_id" class="form-control">
                                        <option value="">{{ __('None') }}</option>
                                        @foreach ($departments as $department)
                                            <option value="{{ $department->id }}" {{ (string) old('department_id', $asset->department_id) === (string) $department->id ? 'selected' : '' }}>{{ $department->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="location_id">{{ __('Location') }}</label>
                                    <select name="location_id" id="location_id" class="form-control">
                                        <option value="">{{ __('None') }}</option>
                                        @foreach ($locations as $location)
                                            <option value="{{ $location->id }}" {{ (string) old('location_id', $asset->location_id) === (string) $location->id ? 'selected' : '' }}>{{ $location->code }} — {{ $location->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="cost_centre_id">{{ __('Cost Centre') }}</label>
                                    <select name="cost_centre_id" id="cost_centre_id" class="form-control">
                                        <option value="">{{ __('None') }}</option>
                                        @foreach ($costCentres as $costCentre)
                                            <option value="{{ $costCentre->id }}" {{ (string) old('cost_centre_id', $asset->cost_centre_id) === (string) $costCentre->id ? 'selected' : '' }}>{{ $costCentre->code }} — {{ $costCentre->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="custodian_id">{{ __('Custodian') }}</label>
                                    <select name="custodian_id" id="custodian_id" class="form-control">
                                        <option value="">{{ __('None') }}</option>
                                        @foreach ($employees as $employee)
                                            <option value="{{ $employee->id }}" {{ (string) old('custodian_id', $asset->custodian_id) === (string) $employee->id ? 'selected' : '' }}>{{ $employee->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h5>{{ __('Acquisition') }}</h5></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="acquisition_method">{{ __('Acquisition Method') }} *</label>
                                    <select name="acquisition_method" id="acquisition_method" class="form-control" required>
                                        @foreach (\App\Models\FixedAsset::ACQUISITION_METHODS as $method)
                                            <option value="{{ $method }}" {{ old('acquisition_method', $asset->acquisition_method) === $method ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $method)) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="source_type">{{ __('Acquisition Source') }} *</label>
                                    <select name="source_type" id="source_type" class="form-control" {{ $isEdit ? 'disabled' : '' }} required>
                                        @foreach (\App\Models\FixedAssetAcquisition::SOURCE_TYPES as $sourceType)
                                            <option value="{{ $sourceType }}" {{ old('source_type', $acquisition->source_type) === $sourceType ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $sourceType)) }}</option>
                                        @endforeach
                                    </select>
                                    @if ($isEdit)
                                        <input type="hidden" name="source_type" value="{{ $acquisition->source_type }}">
                                    @endif
                                    <small class="text-muted">{{ __('Source cannot change after registration.') }}</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="asset_count">{{ __('Quantity (identical assets)') }}</label>
                                    @if ($isEdit)
                                        <input type="text" class="form-control" value="1" disabled>
                                        <input type="hidden" name="asset_count" value="1">
                                    @else
                                        <input type="number" min="1" max="100" name="asset_count" id="asset_count" class="form-control" value="{{ old('asset_count', 1) }}">
                                        <small class="text-muted">{{ __('One PO line can register up to 100 identical assets; the cost is split equally.') }}</small>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="row source-picker" id="source-picker-purchase_product" style="display:none">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label class="form-label" for="source_purchase_product">{{ __('Purchase Order Line') }}</label>
                                    <select name="source_id" id="source_purchase_product" class="form-control source-select">
                                        <option value="">{{ __('Select a purchase order line') }}</option>
                                        @foreach ($purchaseProducts as $line)
                                            @php $po = $purchaseMap[$line->purchase_id] ?? null; @endphp
                                            <option value="{{ $line->id }}" data-total="{{ $line->total }}" data-supplier="{{ $po && $po->vender ? $po->vender->name : '' }}" data-reference="{{ $po->purchase_id ?? '' }}" data-date="{{ $po->purchase_date ?? '' }}" {{ (string) old('source_id', $acquisition->source_id) === (string) $line->id && $acquisition->source_type === 'purchase_product' ? 'selected' : '' }}>{{ ($po->purchase_id ?? 'PO') }} — {{ $line->product->name ?? 'Product' }} × {{ $line->quantity }} ({{ number_format((float) $line->total, 2) }})</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row source-picker" id="source-picker-bill_product" style="display:none">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label class="form-label" for="source_bill_product">{{ __('Supplier Invoice Line') }}</label>
                                    <select name="source_id" id="source_bill_product" class="form-control source-select">
                                        <option value="">{{ __('Select a supplier invoice line') }}</option>
                                        @foreach ($billProducts as $line)
                                            @php $bill = $billMap[$line->bill_id] ?? null; @endphp
                                            <option value="{{ $line->id }}" data-total="{{ $line->total }}" data-supplier="{{ $bill && $bill->vender ? $bill->vender->name : '' }}" data-reference="{{ $bill->bill_id ?? '' }}" data-date="{{ $bill->bill_date ?? '' }}" {{ (string) old('source_id', $acquisition->source_id) === (string) $line->id && $acquisition->source_type === 'bill_product' ? 'selected' : '' }}>{{ ($bill->bill_id ?? 'Bill') }} — {{ $line->product->name ?? 'Product' }} × {{ $line->quantity }} ({{ number_format((float) $line->total, 2) }})</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row source-picker" id="source-picker-delivery_note_item" style="display:none">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label class="form-label" for="source_delivery_note_item">{{ __('Delivery Note Item') }}</label>
                                    <select name="source_id" id="source_delivery_note_item" class="form-control source-select">
                                        <option value="">{{ __('Select a delivery note item') }}</option>
                                        @foreach ($deliveryItems as $line)
                                            @php $note = $line->note; @endphp
                                            <option value="{{ $line->id }}" data-total="{{ bcmul((string) $line->price, (string) $line->delivered_qty, 4) }}" data-reference="{{ $note->note_number ?? '' }}" data-date="{{ $note && $note->delivery_date ? $note->delivery_date->format('Y-m-d') : '' }}" {{ (string) old('source_id', $acquisition->source_id) === (string) $line->id && $acquisition->source_type === 'delivery_note_item' ? 'selected' : '' }}>{{ ($note->note_number ?? 'DN') }} — {{ $line->product->name ?? 'Product' }} × {{ $line->delivered_qty }} ({{ number_format((float) bcmul((string) $line->price, (string) $line->delivered_qty, 4), 2) }})</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="supplier">{{ __('Supplier') }}</label>
                                    <input type="text" name="supplier" id="supplier" class="form-control" value="{{ old('supplier', $asset->supplier ?? $acquisition->supplier) }}" maxlength="255">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="po_reference">{{ __('PO Reference') }}</label>
                                    <input type="text" name="po_reference" id="po_reference" class="form-control" value="{{ old('po_reference', $asset->po_reference) }}" maxlength="100">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label" for="invoice_reference">{{ __('Invoice Reference') }}</label>
                                    <input type="text" name="invoice_reference" id="invoice_reference" class="form-control" value="{{ old('invoice_reference', $asset->invoice_reference) }}" maxlength="100">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label" for="purchase_date">{{ __('Purchase Date') }}</label>
                                    <input type="date" name="purchase_date" id="purchase_date" class="form-control" value="{{ old('purchase_date', $asset->purchase_date ? $asset->purchase_date->format('Y-m-d') : '') }}">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label" for="receipt_date">{{ __('Receipt Date') }}</label>
                                    <input type="date" name="receipt_date" id="receipt_date" class="form-control" value="{{ old('receipt_date', $asset->receipt_date ? $asset->receipt_date->format('Y-m-d') : '') }}">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label" for="currency">{{ __('Currency') }}</label>
                                    <input type="text" name="currency" id="currency" class="form-control" value="{{ old('currency', $asset->currency) }}" maxlength="3" style="text-transform:uppercase">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label" for="exchange_rate">{{ __('Exchange Rate (to base)') }}</label>
                                    <input type="number" step="0.0000000001" min="0.0000000001" name="exchange_rate" id="exchange_rate" class="form-control" value="{{ old('exchange_rate', $asset->exchange_rate) }}">
                                    <small class="text-muted">{{ __('1 when the currency is the base currency.') }}</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label" for="warranty_start">{{ __('Warranty Start') }}</label>
                                    <input type="date" name="warranty_start" id="warranty_start" class="form-control" value="{{ old('warranty_start', $asset->warranty_start ? $asset->warranty_start->format('Y-m-d') : '') }}">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label" for="warranty_end">{{ __('Warranty End') }}</label>
                                    <input type="date" name="warranty_end" id="warranty_end" class="form-control" value="{{ old('warranty_end', $asset->warranty_end ? $asset->warranty_end->format('Y-m-d') : '') }}">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="source_reference">{{ __('Source Document Reference') }}</label>
                                    <input type="text" name="source_reference" id="source_reference" class="form-control" value="{{ old('source_reference', $acquisition->source_reference) }}" maxlength="100">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5>{{ __('Acquisition Cost') }}</h5>
                        <small class="text-muted">{{ __('Recoverable VAT is excluded from the capitalized cost. Tick "VAT recoverable" on a line to exclude its VAT.') }}</small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="cost-components">
                                <thead>
                                    <tr>
                                        <th>{{ __('Cost Component') }}</th>
                                        <th style="width:130px">{{ __('Gross Amount') }}</th>
                                        <th style="width:110px">{{ __('VAT Amount') }}</th>
                                        <th style="width:110px">{{ __('VAT Recoverable') }}</th>
                                        <th style="width:150px">{{ __('Contribution') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (\App\Models\FixedAssetAcquisitionCost::TYPES as $i => $type)
                                        @php $row = $componentRow($type); @endphp
                                        <tr>
                                            <td>
                                                {{ $componentLabels[$type] }}
                                                @if ($type === \App\Models\FixedAssetAcquisitionCost::TYPE_PURCHASE_PRICE) <span class="text-danger">*</span> @endif
                                                <input type="hidden" name="components[{{ $i }}][component_type]" value="{{ $type }}">
                                            </td>
                                            <td><input type="number" step="0.0001" min="0" name="components[{{ $i }}][gross_amount]" class="form-control cost-gross" value="{{ $row['gross_amount'] }}"></td>
                                            <td><input type="number" step="0.0001" min="0" name="components[{{ $i }}][vat_amount]" class="form-control cost-vat" value="{{ $row['vat_amount'] }}"></td>
                                            <td class="text-center"><input type="checkbox" name="components[{{ $i }}][vat_recoverable]" value="1" class="form-check-input cost-recoverable" {{ $row['vat_recoverable'] ? 'checked' : '' }}></td>
                                            <td class="cost-contribution text-end">0.00</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="4" class="text-end">{{ __('Capitalized Cost (excl. recoverable VAT)') }}</th>
                                        <th class="text-end"><span id="capitalized-total">0.00</span> <span id="capitalized-currency">{{ old('currency', $asset->currency) }}</span></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="notes">{{ __('Acquisition Notes') }}</label>
                            <textarea name="notes" id="notes" class="form-control" rows="2">{{ old('notes', $acquisition->notes) }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><h5>{{ __('Depreciation') }}</h5></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="useful_life">{{ __('Useful Life') }}</label>
                                    <input type="number" min="1" name="useful_life" id="useful_life" class="form-control" value="{{ old('useful_life', $asset->useful_life) }}">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label" for="life_unit">{{ __('Life Unit') }}</label>
                                    <select name="life_unit" id="life_unit" class="form-control">
                                        @foreach (['week', 'month', 'year'] as $unit)
                                            <option value="{{ $unit }}" {{ old('life_unit', $asset->life_unit) === $unit ? 'selected' : '' }}>{{ ucfirst($unit) }}s</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="residual_value">{{ __('Residual Value') }}</label>
                            <input type="number" step="0.0001" min="0" name="residual_value" id="residual_value" class="form-control" value="{{ old('residual_value', $asset->residual_value) }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="opening_accumulated_depreciation">{{ __('Opening Accumulated Depreciation') }}</label>
                            <input type="number" step="0.0001" min="0" name="opening_accumulated_depreciation" id="opening_accumulated_depreciation" class="form-control" value="{{ old('opening_accumulated_depreciation', $asset->opening_accumulated_depreciation) }}">
                            <small class="text-muted">{{ __('Old assets only: depreciation already taken before the asset enters the register.') }}</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="depreciation_start_date">{{ __('Depreciation Start Date') }}</label>
                            <input type="date" name="depreciation_start_date" id="depreciation_start_date" class="form-control" value="{{ old('depreciation_start_date', $asset->depreciation_start_date ? $asset->depreciation_start_date->format('Y-m-d') : '') }}">
                            <small class="text-muted">{{ __('Defaults to the capitalization date when the asset is capitalized.') }}</small>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h5>{{ __('Accounts (optional overrides)') }}</h5></div>
                    <div class="card-body">
                        <div class="form-group">
                            <label class="form-label" for="asset_account_id">{{ __('Asset Account') }}</label>
                            <select name="asset_account_id" id="asset_account_id" class="form-control">
                                <option value="">{{ __('Category default') }}</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}" {{ (string) old('asset_account_id', $asset->asset_account_id) === (string) $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="accum_depreciation_account_id">{{ __('Accumulated Depreciation Account') }}</label>
                            <select name="accum_depreciation_account_id" id="accum_depreciation_account_id" class="form-control">
                                <option value="">{{ __('Category default') }}</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}" {{ (string) old('accum_depreciation_account_id', $asset->accum_depreciation_account_id) === (string) $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="depreciation_expense_account_id">{{ __('Depreciation Expense Account') }}</label>
                            <select name="depreciation_expense_account_id" id="depreciation_expense_account_id" class="form-control">
                                <option value="">{{ __('Category default') }}</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}" {{ (string) old('depreciation_expense_account_id', $asset->depreciation_expense_account_id) === (string) $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">{{ $isEdit ? __('Update Asset') : __('Register Asset') }}</button>
                    <a href="{{ route('fixed-assets.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('script-page')
<script>
    (function () {
        var sourceType = document.getElementById('source_type');
        var pickers = {
            purchase_product: document.getElementById('source-picker-purchase_product'),
            bill_product: document.getElementById('source-picker-bill_product'),
            delivery_note_item: document.getElementById('source-picker-delivery_note_item')
        };

        function togglePickers() {
            var value = sourceType ? sourceType.value : 'direct';
            Object.keys(pickers).forEach(function (key) {
                var picker = pickers[key];
                var select = picker.querySelector('select');
                if (key === value) {
                    picker.style.display = '';
                    select.disabled = false;
                } else {
                    picker.style.display = 'none';
                    select.disabled = true;
                }
            });
        }

        if (sourceType) {
            sourceType.addEventListener('change', togglePickers);
            togglePickers();
        }

        // Prefill the purchase price + supplier from the chosen source line.
        document.querySelectorAll('.source-select').forEach(function (select) {
            select.addEventListener('change', function () {
                var option = select.options[select.selectedIndex];
                if (! option || ! option.value) {
                    return;
                }
                var gross = document.querySelector('.cost-gross');
                if (gross && option.dataset.total) {
                    gross.value = option.dataset.total;
                }
                var supplier = document.getElementById('supplier');
                if (supplier && option.dataset.supplier) {
                    supplier.value = option.dataset.supplier;
                }
                var reference = document.getElementById('source_reference');
                if (reference && option.dataset.reference) {
                    reference.value = option.dataset.reference;
                }
                var purchaseDate = document.getElementById('purchase_date');
                if (purchaseDate && option.dataset.date) {
                    purchaseDate.value = option.dataset.date;
                }
                updateCostPreview();
            });
        });

        // Category defaults for useful life.
        var categorySelect = document.getElementById('category_id');
        var lifeInput = document.getElementById('useful_life');
        var unitSelect = document.getElementById('life_unit');
        if (categorySelect) {
            categorySelect.addEventListener('change', function () {
                var option = categorySelect.options[categorySelect.selectedIndex];
                if (! option) {
                    return;
                }
                if (lifeInput && ! lifeInput.value && option.dataset.life) {
                    lifeInput.value = option.dataset.life;
                }
                if (unitSelect && option.dataset.unit) {
                    unitSelect.value = option.dataset.unit;
                }
            });
        }

        // Live capitalized-cost preview (recoverable VAT excluded).
        function updateCostPreview() {
            var total = 0;
            document.querySelectorAll('#cost-components tbody tr').forEach(function (row) {
                var gross = parseFloat(row.querySelector('.cost-gross').value || '0') || 0;
                var vat = parseFloat(row.querySelector('.cost-vat').value || '0') || 0;
                var recoverable = row.querySelector('.cost-recoverable').checked;
                var contribution = gross - (recoverable ? vat : 0);
                row.querySelector('.cost-contribution').textContent = contribution.toFixed(2);
                total += contribution;
            });
            var totalEl = document.getElementById('capitalized-total');
            if (totalEl) {
                totalEl.textContent = total.toFixed(2);
            }
            var currencyInput = document.getElementById('currency');
            var currencyEl = document.getElementById('capitalized-currency');
            if (currencyInput && currencyEl) {
                currencyEl.textContent = currencyInput.value || '';
            }
        }

        document.querySelectorAll('#cost-components input').forEach(function (input) {
            input.addEventListener('input', updateCostPreview);
            input.addEventListener('change', updateCostPreview);
        });
        var currencyInput = document.getElementById('currency');
        if (currencyInput) {
            currencyInput.addEventListener('input', updateCostPreview);
        }
        updateCostPreview();
    })();
</script>
@endpush
