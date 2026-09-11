@extends('layouts.admin')

@section('page-title')
    {{ __('Credit Note Report') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.sections.management') }}">{{ __('Reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Credit Note Report') }}</li>
@endsection

@push('script-page')
<script>
    function printReportArea() {
        const printContents = document.getElementById('printableArea').innerHTML;
        const originalContents = document.body.innerHTML;
        document.body.innerHTML = printContents;
        window.print();
        document.body.innerHTML = originalContents;
        window.location.reload();
    }
</script>
@endpush

@section('action-btn')
    <div class="float-end">
        <a href="#" onclick="printReportArea(); return false;" class="btn btn-sm btn-primary-subtle" data-bs-toggle="tooltip" title="{{ __('Print') }}">
            <i class="ti ti-printer"></i>
        </a>
    </div>
@endsection

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5>{{ __('Credit Note Report') }}</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('reports.credit.note') }}" class="no-print">
                    <div class="row">
                        <div class="col-md-3">
                            <label for="start_date">{{ __('Start Date') }}</label>
                            <input type="date" name="start_date" class="form-control" value="{{ $request->start_date }}">
                        </div>
                        <div class="col-md-3">
                            <label for="end_date">{{ __('End Date') }}</label>
                            <input type="date" name="end_date" class="form-control" value="{{ $request->end_date }}">
                        </div>
                        <div class="col-md-3">
                            <label for="customer">{{ __('Customer') }}</label>
                            <select name="customer" class="form-control select">
                                <option value="">{{ __('All Customers') }}</option>
                                @foreach($customers as $id => $name)
                                    <option value="{{ $id }}" {{ $request->customer == $id ? 'selected' : '' }}>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="product">{{ __('Product') }}</label>
                            <select name="product" class="form-control select">
                                <option value="">{{ __('All Products') }}</option>
                                @foreach($products as $id => $name)
                                    <option value="{{ $id }}" {{ $request->product == $id ? 'selected' : '' }}>{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">{{ __('Filter') }}</button>
                            <a href="{{ route('reports.credit.note') }}" class="btn btn-secondary">{{ __('Reset') }}</a>
                        </div>
                    </div>
                </form>

                <div class="table-responsive mt-4" id="printableArea">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>{{ __('Credit Note No') }}</th>
                                <th>{{ __('Date') }}</th>
                                <th>{{ __('Customer') }}</th>
                                <th>{{ __('Linked Invoice') }}</th>
                                <th>{{ __('Products') }}</th>
                                <th>{{ __('Qty') }}</th>
                                <th>{{ __('Amount') }}</th>
                                <th>{{ __('VAT') }}</th>
                                <th>{{ __('Total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($creditNotes as $note)
                                @php
                                    $totalQty = 0;
                                    $totalAmount = 0;
                                    $totalVat = 0;
                                    $productsList = [];
                                    if($note->invoice_data && $note->data) {
                                        $data = json_decode($note->data, true);
                                        foreach($data as $pid => $qty) {
                                            $item = $note->invoice_data->items->where('product_id', $pid)->first();
                                            if($item) {
                                                $productsList[] = $item->product->name;
                                                $totalQty += $qty;
                                                $totalAmount += $item->price * $qty;
                                                $totalVat += ($item->price * $qty * $item->tax_rate / 100);
                                            }
                                        }
                                    }
                                    $total = $totalAmount + $totalVat;
                                @endphp
                                <tr>
                                    <td>{{ \Auth::user()->creditNoteNumberFormat($note->uid) }}</td>
                                    <td>{{ \Auth::user()->dateFormat($note->date) }}</td>
                                    <td>{{ $note->customerRelation ? $note->customerRelation->name : '-' }}</td>
                                    <td>{{ $note->invoice_data ? \Auth::user()->invoiceNumberFormat($note->invoice_data->invoice_id) : '-' }}</td>
                                    <td>{{ implode(', ', $productsList) }}</td>
                                    <td>{{ $totalQty }}</td>
                                    <td>{{ \Auth::user()->priceFormat($totalAmount) }}</td>
                                    <td>{{ \Auth::user()->priceFormat($totalVat) }}</td>
                                    <td>{{ \Auth::user()->priceFormat($total) }}</td>
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