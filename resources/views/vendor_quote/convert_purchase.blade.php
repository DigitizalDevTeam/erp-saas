@extends('layouts.admin')
@section('page-title')
    {{ __('Purchase order from vendor quote') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('vendor-quote.index') }}">{{ __('Vendor Quote') }}</a></li>
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
                    {{ Form::open(['route' => ['vendor-quote.convert-purchase.store', \Crypt::encrypt($quote->id)], 'method' => 'POST', 'class' => 'needs-validation', 'id' => 'convert-vq-po-form']) }}
                    @include('partials.convert_source_items', [
                        'sourceLabel' => __('From Vendor Quote'),
                        'sourceValue' => Auth::user()->vendorQuoteNumberFormat($quote->purchase_quotation_id),
                        'sourceLines' => $quote->items,
                    ])
                    <div class="mt-3">
                        {{ Form::submit(__('Convert to purchase order'), ['class' => 'btn btn-primary']) }}
                        <a href="{{ route('vendor-quote.index') }}" class="btn btn-secondary">{{ __('Back') }}</a>
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
