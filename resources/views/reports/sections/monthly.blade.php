@extends('layouts.admin')

@section('page-title')
    {{ __('Monthly Reports') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Reports') }}</li>
    <li class="breadcrumb-item">{{ __('Monthly Reports') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-2">{{ __('Government / statutory monthly reports') }}</h5>
                    <p class="text-muted mb-3">{{ __('These reports are designed to match government templates. You can edit the Blade templates to adjust the exact format.') }}</p>

                    <div class="list-group">
                        @can('tax report')
                            <a class="list-group-item list-group-item-action" href="{{ route('monthly.reports.vat-modelo-a') }}">
                                {{ __('VAT (Modelo A IVA)') }}
                            </a>
                        @endcan

                        @can('trial balance report')
                            <a class="list-group-item list-group-item-action" href="{{ route('monthly.reports.trial-balance') }}">
                                {{ __('Trial Balance (Balancete)') }}
                            </a>
                        @endcan

                        @can('manage report')
                            <a class="list-group-item list-group-item-action" href="{{ route('monthly.reports.irps-modelo-19') }}">
                                {{ __('IRPS (Modelo 19)') }}
                            </a>
                            <a class="list-group-item list-group-item-action" href="{{ route('monthly.reports.irps-modelo-22') }}">
                                {{ __('IRPS / Payroll (Model 22)') }}
                            </a>
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

