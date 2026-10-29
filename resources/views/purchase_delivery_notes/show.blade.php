@extends('layouts.admin')
@section('page-title')
    {{ __('Delivery note') }} DN-{{ $note->note_number }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Delivery note') }} DN-{{ $note->note_number }}</li>
@endsection
@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p><strong>{{ __('Date') }}:</strong> {{ $note->delivery_date ? \Carbon\Carbon::parse($note->delivery_date)->format('Y-m-d') : '' }}</p>
                    @if ($note->purchase_id)
                        <p><a href="{{ route('purchase.show', \Crypt::encrypt($note->purchase_id)) }}">{{ __('View purchase order') }}</a></p>
                    @endif
                    @if ($note->bill_id)
                        <p><a href="{{ route('bill.show', \Crypt::encrypt($note->bill_id)) }}">{{ __('View bill') }}</a></p>
                    @endif
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Product') }}</th>
                                    <th>{{ __('Ordered') }}</th>
                                    <th>{{ __('Delivered') }}</th>
                                    <th>{{ __('Price') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($note->items as $item)
                                    <tr>
                                        <td>{{ $item->product->name ?? '' }}</td>
                                        <td>{{ $item->ordered_qty }}</td>
                                        <td>{{ $item->delivered_qty }}</td>
                                        <td>{{ \Auth::user()->priceFormat($item->price) }}</td>
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
