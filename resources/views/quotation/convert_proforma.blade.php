@extends('layouts.admin')
@section('page-title')
    {{ __('Proforma from quotation') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('quotation.index') }}">{{ __('Quotation') }}</a></li>
    <li class="breadcrumb-item">{{ __('Convert to proforma') }}</li>
@endsection

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted mb-4">
                        {{ __('Create a proforma invoice from quotation') }}
                        <strong>{{ Auth::user()->quotationNumberFormat($quotation->quotation_id) }}</strong>.
                        {{ __('Select the items to include. You can still convert this quotation to an invoice or purchase order separately.') }}
                    </p>
                    @include('partials.convert_source_items', [
                        'sourceLabel' => __('From Quotation'),
                        'sourceValue' => Auth::user()->quotationNumberFormat($quotation->quotation_id),
                        'sourceLines' => [],
                    ])
                    {{ Form::open(['route' => ['proforma.store-from-quotation', \Crypt::encrypt($quotation->id)], 'method' => 'POST', 'class' => 'needs-validation', 'id' => 'convert-pi-form']) }}
                    <div class="alert alert-light border mb-3">
                        <strong>{{ __('From Quotation') }}:</strong>
                        {{ Auth::user()->quotationNumberFormat($quotation->quotation_id) }}
                    </div>
                    <div class="table-responsive mb-4">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th style="width:40px">
                                        <input type="checkbox" id="select-all-items" checked>
                                    </th>
                                    <th>{{ __('Item') }}</th>
                                    <th>{{ __('Qty') }}</th>
                                    <th>{{ __('Price') }}</th>
                                    <th>{{ __('Description') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($quotation->items as $row)
                                    @php
                                        $product = \App\Models\ProductService::find($row->product_id);
                                        $name = $product?->name ?? ('#'.$row->product_id);
                                    @endphp
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="item-check" name="items[{{ $row->id }}][selected]" value="1" checked>
                                        </td>
                                        <td>{{ $name }}</td>
                                        <td style="max-width:120px">
                                            <input type="number" step="0.01" min="0.01" class="form-control"
                                                   name="items[{{ $row->id }}][quantity]"
                                                   value="{{ $row->quantity }}" required>
                                        </td>
                                        <td>{{ Auth::user()->priceFormat($row->price) }}</td>
                                        <td>{{ $row->description }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-3">
                        {{ Form::submit(__('Create proforma invoice'), ['class' => 'btn btn-primary']) }}
                        <a href="{{ route('quotation.index') }}" class="btn btn-secondary">{{ __('Back') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
<script>
    document.getElementById('select-all-items')?.addEventListener('change', function () {
        document.querySelectorAll('.item-check').forEach(function (el) {
            el.checked = this.checked;
        }.bind(this));
    });
</script>
@endpush
