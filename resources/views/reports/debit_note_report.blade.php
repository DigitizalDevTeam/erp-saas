@extends('layouts.admin')

@section('page-title')
    {{ __('Debit Note Report') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('reports.sections.management') }}">{{ __('Reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Debit Note Report') }}</li>
@endsection

@section('content')
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5>{{ __('Debit Note Report') }}</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('reports.debit.note') }}">
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
                    </div>
                    <div class="row mt-3">
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">{{ __('Filter') }}</button>
                            <a href="{{ route('reports.debit.note') }}" class="btn btn-secondary">{{ __('Reset') }}</a>
                        </div>
                    </div>
                </form>

                <div class="table-responsive mt-4">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>{{ __('Debit Note No') }}</th>
                                <th>{{ __('Date') }}</th>
                                <th>{{ __('Customer') }}</th>
                                <th>{{ __('Linked Invoice') }}</th>
                                <th>{{ __('Description') }}</th>
                                <th>{{ __('Amount') }}</th>
                                <th>{{ __('VAT') }}</th>
                                <th>{{ __('Total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($debitNotes as $note)
                                @php
                                    $vat = 0; // Assuming VAT is not stored separately, or calculate if needed
                                    $total = $note->amount + $vat;
                                @endphp
                                <tr>
                                    <td>{{ \Auth::user()->debitNoteNumberFormat($note->uid) }}</td>
                                    <td>{{ \Auth::user()->dateFormat($note->date) }}</td>
                                    <td>{{ $note->customerRelation ? $note->customerRelation->name : '-' }}</td>
                                    <td>{{ $note->invoice_data ? \Auth::user()->invoiceNumberFormat($note->invoice_data->invoice_id) : '-' }}</td>
                                    <td>{{ $note->description ?? '-' }}</td>
                                    <td>{{ \Auth::user()->priceFormat($note->amount) }}</td>
                                    <td>{{ \Auth::user()->priceFormat($vat) }}</td>
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