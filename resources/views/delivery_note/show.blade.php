@extends('layouts.admin')

@section('page-title')
    {{ __('Delivery Note') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('invoice.index') }}">{{ __('Invoice') }}</a></li>
    <li class="breadcrumb-item">{{ __('Delivery Note') }}</li>
@endsection

@section('content')
    @php
        $invoice = $deliveryNote->invoice;
        $settings = Utility::settings();
    @endphp

    <div class="row justify-content-between align-items-center mb-3">
        <div class="d-flex flex-wrap align-items-center justify-content-end gap-2">
            <div class="all-button-box">
                <a href="{{ route('delivery-note.print', Crypt::encrypt($deliveryNote->id)) }}" target="_blank"
                    class="btn btn-sm btn-primary">{{ __('Print') }}</a>
            </div>
            <div class="all-button-box">
                <a href="{{ route('invoice.show', Crypt::encrypt($invoice->id)) }}"
                    class="btn btn-sm btn-secondary">{{ __('Back to Invoice') }}</a>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <h4 class="mb-1">{{ __('Delivery Note') }}</h4>
                            <div class="text-muted">{{ $deliveryNote->delivery_note_no }}</div>
                            <div class="text-muted">{{ __('Issue Date') }}: {{ \Auth::user()->dateFormat($deliveryNote->issue_date) }}</div>
                        </div>
                        <div class="text-end">
                            <div><b>{{ __('Invoice') }}:</b> {{ \Auth::user()->invoiceNumberFormat($invoice->invoice_id) }}</div>
                            @if(!empty($invoice->customer))
                                <div><b>{{ __('Customer') }}:</b> {{ $invoice->customer->name }}</div>
                            @endif
                            @if(!empty($invoice->stock_deducted_at))
                                <div class="mt-1">
                                    <span class="badge bg-info p-2 px-3 rounded">
                                        {{ __('Stock deducted via') }}: {{ $invoice->stock_deducted_via ?? '-' }}
                                    </span>
                                </div>
                            @endif
                        </div>
                    </div>

                    <hr>

                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th class="text-dark">#</th>
                                    <th class="text-dark">{{ __('Product') }}</th>
                                    <th class="text-dark">{{ __('Quantity') }}</th>
                                    <th class="text-dark">{{ __('Description') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($invoice->items as $k => $line)
                                    <tr>
                                        <td>{{ $k + 1 }}</td>
                                        <td>{{ $line->product?->name ?? '-' }}</td>
                                        <td>
                                            {{ $line->quantity }}
                                            @if($line->product?->unit)
                                                ({{ $line->product->unit->name }})
                                            @endif
                                        </td>
                                        <td>{{ $line->description ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 d-flex justify-content-end">
                        @if($deliveryNote->roadManifest)
                            <a href="{{ route('road-manifest.show', Crypt::encrypt($deliveryNote->roadManifest->id)) }}"
                                class="btn btn-sm btn-primary">{{ __('View Road Manifest') }}</a>
                        @else
                            {!! Form::open([
                                'method' => 'post',
                                'route' => ['delivery-note.convert-to-manifest', Crypt::encrypt($deliveryNote->id)],
                            ]) !!}
                            <div class="row g-2 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label">{{ __('Vehicle No') }}</label>
                                    <input type="text" name="vehicle_no" class="form-control" />
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">{{ __('Driver Name') }}</label>
                                    <input type="text" name="driver_name" class="form-control" />
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">{{ __('Notes') }}</label>
                                    <input type="text" name="notes" class="form-control" />
                                </div>
                                <div class="col-md-2 text-end">
                                    <button type="submit" class="btn btn-sm btn-primary w-100">
                                        {{ __('Convert to Road Manifest') }}
                                    </button>
                                </div>
                            </div>
                            {!! Form::close() !!}
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

