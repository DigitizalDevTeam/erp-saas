@extends('layouts.admin')

@section('page-title')
    {{ __('Road Manifest') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Road Manifest') }}</li>
@endsection

@section('content')
    @php
        $dn = $roadManifest->deliveryNote;
        $invoice = $dn->invoice;
    @endphp

    <div class="row justify-content-between align-items-center mb-3">
        <div class="d-flex flex-wrap align-items-center justify-content-end gap-2">
            <div class="all-button-box">
                <a href="{{ route('road-manifest.print', Crypt::encrypt($roadManifest->id)) }}" target="_blank"
                    class="btn btn-sm btn-primary">{{ __('Print') }}</a>
            </div>
            <div class="all-button-box">
                <a href="{{ route('road-transport-record.create', Crypt::encrypt($roadManifest->id)) }}"
                    class="btn btn-sm btn-primary">{{ __('Add Transport Record') }}</a>
            </div>
            <div class="all-button-box">
                <a href="{{ route('delivery-note.show', Crypt::encrypt($dn->id)) }}"
                    class="btn btn-sm btn-secondary">{{ __('Back to Delivery Note') }}</a>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <h4 class="mb-1">{{ __('Road Manifest') }}</h4>
                            <div class="text-muted">{{ $roadManifest->manifest_no }}</div>
                            <div class="text-muted">{{ __('Delivery Note') }}: {{ $dn->delivery_note_no }}</div>
                        </div>
                        <div class="text-end">
                            <div><b>{{ __('Invoice') }}:</b> {{ \Auth::user()->invoiceNumberFormat($invoice->invoice_id) }}</div>
                            @if(!empty($invoice->customer))
                                <div><b>{{ __('Customer') }}:</b> {{ $invoice->customer->name }}</div>
                            @endif
                            <div><b>{{ __('Vehicle No') }}:</b> {{ $roadManifest->vehicle_no ?? '-' }}</div>
                            <div><b>{{ __('Driver Name') }}:</b> {{ $roadManifest->driver_name ?? '-' }}</div>
                        </div>
                    </div>

                    @if(!empty($roadManifest->notes))
                        <div class="mt-3">
                            <b>{{ __('Notes') }}:</b> {{ $roadManifest->notes }}
                        </div>
                    @endif

                    <hr>

                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th class="text-dark">#</th>
                                    <th class="text-dark">{{ __('Product') }}</th>
                                    <th class="text-dark">{{ __('Quantity') }}</th>
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
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

