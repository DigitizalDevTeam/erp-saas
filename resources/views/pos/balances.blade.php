@extends('layouts.admin')
@section('page-title')
    {{ __('pos.pos_balances') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{ __('pos.dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('pos.pos_balances') }}</li>
@endsection
@push('css-page')
    <link rel="stylesheet" href="{{ asset('css/datatable/buttons.dataTables.min.css') }}">
@endpush

@section('content')
    <div id="printableArea">
        <div class="row mt-3">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-body table-border-style">
                        <div class="table-responsive">
                            <table class="table datatable">
                                <thead>
                                <tr>
                                    <th>{{__('Date')}}</th>
                                    <th>{{ __('Opening Balance') }}</th>
                                    <th>{{ __('Closing Balance') }}</th>
                                </tr>
                                </thead>
                                
                                <tbody>

                                @forelse ($pos_balances as $balance)
                                    <tr>
                                        <td>{{ $balance->created_at->format('d M Y') }}</td>
                                        <td>${{ number_format($balance->opening_balance ?? 0, 2) }}</td>
                                        <td>${{ number_format($balance->closing_balance ?? 0, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-dark"><p>{{ __('pos.no_data_found_short') }}</p></td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
