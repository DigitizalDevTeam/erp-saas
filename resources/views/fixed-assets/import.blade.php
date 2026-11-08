@extends('layouts.admin')
@section('page-title')
    {{ __('Import Assets') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-assets.index') }}">{{ __('Asset Register') }}</a></li>
    <li class="breadcrumb-item">{{ __('Import Assets') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header"><h5>{{ __('Import Old Assets from Excel') }}</h5></div>
                <div class="card-body">
                    <p>{{ __('Upload an Excel file with one asset per row. Valid rows are registered as draft opening-balance assets; each row needs a name, a category code and a purchase cost. Opening accumulated depreciation and remaining life are optional per-asset columns. Invalid rows are skipped and reported — one bad row never blocks the rest.') }}</p>
                    <p>
                        <a href="{{ route('fixed-assets.import.template') }}" class="btn btn-secondary">{{ __('Download Template') }}</a>
                    </p>

                    @if (session('import_errors'))
                        <div class="alert alert-warning">
                            <strong>{{ __('Skipped rows:') }}</strong>
                            <ul class="mb-0">
                                @foreach (session('import_errors') as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('fixed-assets.import.store') }}" enctype="multipart/form-data">
                        @csrf
                        <div class="form-group">
                            <label class="form-label" for="file">{{ __('Excel File (.xlsx, .xls, .csv)') }} *</label>
                            <input type="file" name="file" id="file" class="form-control @error('file') is-invalid @enderror" accept=".xlsx,.xls,.csv" required>
                            @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">{{ __('Import Assets') }}</button>
                            <a href="{{ route('fixed-assets.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-header"><h5>{{ __('Template Columns') }}</h5></div>
                <div class="card-body">
                    <p class="mb-1"><strong>{{ __('Required:') }}</strong> name, category_code, purchase_cost</p>
                    <p class="mb-1"><strong>{{ __('Optional:') }}</strong> location_code, cost_centre_code, acquisition_method, purchase_date, currency, exchange_rate, transport, installation, import_duties, non_recoverable_taxes, professional_fees, directly_attributable, discounts, vat_amount, vat_recoverable, opening_accumulated_depreciation, remaining_life, remaining_life_unit, residual_value, serial_number, brand, model, supplier, warranty_start, warranty_end, source_document_reference, description</p>
                    <p class="mb-0 text-muted">{{ __('Category codes must already exist in the register. Use the exact category code (e.g. IT, FUR), not the name.') }}</p>
                    <hr>
                    <p class="mb-0"><strong>{{ __('Existing categories:') }}</strong></p>
                    <ul class="mb-0">
                        @forelse ($categories as $category)
                            <li><code>{{ $category->code }}</code> — {{ $category->name }}</li>
                        @empty
                            <li class="text-muted">{{ __('No categories yet — create categories first.') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endsection
