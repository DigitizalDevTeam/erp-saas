@extends('layouts.admin')
@section('page-title')
    {{ __('Purchase order from purchase proforma') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('purchase-proforma.index') }}">{{ __('Purchase Proforma Invoices') }}</a></li>
    <li class="breadcrumb-item">{{ __('Convert to purchase order') }}</li>
@endsection

@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted mb-3">
                        {{ __('Select the approved items to include on the purchase order.') }}
                    </p>
                    {{ Form::open(['route' => ['purchase-proforma.convert-purchase.store', \Crypt::encrypt($proforma->id)], 'method' => 'POST', 'class' => 'needs-validation']) }}
                    @include('partials.convert_source_items', [
                        'sourceLabel' => __('From Purchase Proforma'),
                        'sourceValue' => Auth::user()->purchaseProformaNumberFormat($proforma->purchase_proforma_id),
                        'sourceLines' => $proforma->items,
                    ])
                    <div class="mt-3">
                        {{ Form::submit(__('Convert to purchase order'), ['class' => 'btn btn-primary']) }}
                        <a href="{{ route('purchase-proforma.show', \Crypt::encrypt($proforma->id)) }}" class="btn btn-secondary">{{ __('Back') }}</a>
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
