@extends('layouts.admin')

@section('page-title')
    {{ __('Trial Balance (Monthly Report)') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Monthly Reports') }}</li>
    <li class="breadcrumb-item">{{ __('Trial Balance') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('monthly.reports.trial-balance', array_merge(request()->query(), ['download' => 'pdf'])) }}"
            class="btn btn-sm btn-primary-subtle me-1" data-bs-toggle="tooltip" title="{{ __('Download PDF') }}">
            <i class="ti ti-download"></i>
        </a>
    </div>

    <div class="float-end me-2">
        <a href="#" class="btn btn-sm btn-primary" data-bs-toggle="tooltip" title="{{ __('Filter') }}"
            onclick="document.getElementById('monthly_report_filter').classList.toggle('d-none'); return false;">
            <i class="ti ti-filter"></i>
        </a>
    </div>
@endsection

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card d-none" id="monthly_report_filter">
                <div class="card-body">
                    {{ Form::open(['route' => ['monthly.reports.trial-balance'], 'method' => 'GET', 'id' => 'report_trial_balance']) }}
                    <div class="row align-items-start">
                        @include('reports.partials.trial_balance_filter_fields')
                        <div class="col-md-12 mt-2">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="ti ti-search"></i> {{ __('Apply') }}
                                </button>
                                <a href="{{ route('monthly.reports.trial-balance') }}" class="btn btn-sm btn-danger">
                                    <i class="ti ti-refresh"></i> {{ __('Reset') }}
                                </a>
                                <a href="{{ route('monthly.reports.trial-balance', ['range' => 'ytd']) }}"
                                    class="btn btn-sm btn-outline-secondary">
                                    {{ __('Year to date') }}
                                </a>
                            </div>
                        </div>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>

    @php
        $authUser = \Auth::user()->creatorId();
        $user = \App\Models\User::find($authUser);
        $totalDebit = $grandTotalDebit ?? 0;
        $totalCredit = $grandTotalCredit ?? 0;
        $grandBalance = $totalDebit - $totalCredit;
        $grandDevedor = $grandBalance > 0 ? $grandBalance : 0;
        $grandCredor = $grandBalance < 0 ? abs($grandBalance) : 0;
    @endphp

    <div class="row justify-content-center" id="printableArea">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <div class="text-center mb-4">
                        <div class="fw-bold" style="font-size: 16px;">{{ __('BALANCETE (Trial Balance)') }}</div>
                        <div style="font-size: 13px;">{{ $user?->name }}</div>
                        <div style="font-size: 12px;">
                            {{ __('Period') }}: {{ $filter['startDateRange'] }} {{ __('to') }} {{ $filter['endDateRange'] }}
                        </div>
                        @include('reports.partials.trial_balance_period_banner')
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm" style="font-size: 12px;">
                            <thead>
                                <tr>
                                    <th style="width: 12%;" class="text-center">{{ __('Code') }}</th>
                                    <th style="width: 28%;">{{ __('Account') }}</th>
                                    <th style="width: 15%;" class="text-end">{{ __('Debito') }}</th>
                                    <th style="width: 15%;" class="text-end">{{ __('Credito') }}</th>
                                    <th style="width: 15%;" class="text-end">{{ __('Saldo Devedor') }}</th>
                                    <th style="width: 15%;" class="text-end">{{ __('Saldo Credor') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($totalAccounts as $type => $accounts)
                                    @if ($type !== '' && $type !== null)
                                        <tr>
                                            <td colspan="6" class="fw-bold" style="background: #f6f7f9;">
                                                {{ $type }}
                                            </td>
                                        </tr>
                                    @endif

                                    @foreach ($accounts as $record)
                                        @php
                                            $isParent = ($record['account'] ?? '') === 'parent';
                                            $isSub = ($record['account'] ?? '') === 'subAccount';
                                        @endphp
                                        <tr>
                                            <td class="text-center {{ $isParent ? 'fw-bold' : '' }}">{{ $record['account_code'] }}</td>
                                            <td class="{{ $isParent ? 'fw-bold' : '' }} {{ $isSub ? 'ps-4' : '' }}">
                                                {{ $record['account_name'] }}
                                            </td>
                                            <td class="text-end {{ $isParent ? 'fw-bold' : '' }}">{{ \Auth::user()->priceFormat($record['totalDebit'] ?? 0) }}</td>
                                            <td class="text-end {{ $isParent ? 'fw-bold' : '' }}">{{ \Auth::user()->priceFormat($record['totalCredit'] ?? 0) }}</td>
                                            <td class="text-end {{ $isParent ? 'fw-bold' : '' }}">{{ \Auth::user()->priceFormat($record['balanceDebit'] ?? 0) }}</td>
                                            <td class="text-end {{ $isParent ? 'fw-bold' : '' }}">{{ \Auth::user()->priceFormat($record['balanceCredit'] ?? 0) }}</td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="2">{{ __('TOTAL') }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($totalDebit) }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($totalCredit) }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($grandDevedor) }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($grandCredor) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
