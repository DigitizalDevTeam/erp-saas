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
        <div class="col-md-10">
            <div class="card d-none" id="monthly_report_filter">
                <div class="card-body">
                    {{ Form::open(['route' => ['monthly.reports.trial-balance'], 'method' => 'GET', 'id' => 'report_trial_balance']) }}
                    <div class="row align-items-end">
                        <div class="col-md-4">
                            <div class="btn-box">
                                {{ Form::label('start_date', __('Start Date'), ['class' => 'form-label']) }}
                                {{ Form::date('start_date', $filter['startDateRange'], ['class' => 'form-control']) }}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="btn-box">
                                {{ Form::label('end_date', __('End Date'), ['class' => 'form-label']) }}
                                {{ Form::date('end_date', $filter['endDateRange'], ['class' => 'form-control']) }}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-sm btn-primary">
                                    <i class="ti ti-search"></i> {{ __('Apply') }}
                                </button>
                                <a href="{{ route('monthly.reports.trial-balance') }}" class="btn btn-sm btn-danger">
                                    <i class="ti ti-refresh"></i> {{ __('Reset') }}
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
        $totalDebit = 0;
        $totalCredit = 0;
    @endphp

    <div class="row justify-content-center" id="printableArea">
        <div class="col-md-10">
            <div class="card">
                <div class="card-body">
                    {{-- GOVERNMENT FORMAT AREA (EDIT THIS VIEW FREELY) --}}
                    <div class="text-center mb-4">
                        <div class="fw-bold" style="font-size: 16px;">{{ __('BALANCETE (Trial Balance)') }}</div>
                        <div style="font-size: 13px;">{{ $user?->name }}</div>
                        <div style="font-size: 12px;">
                            {{ __('Period') }}: {{ $filter['startDateRange'] }} {{ __('to') }} {{ $filter['endDateRange'] }}
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm" style="font-size: 12px;">
                            <thead>
                                <tr>
                                    <th style="width: 55%;">{{ __('Account') }}</th>
                                    <th style="width: 15%;" class="text-center">{{ __('Code') }}</th>
                                    <th style="width: 15%;" class="text-end">{{ __('Debit') }}</th>
                                    <th style="width: 15%;" class="text-end">{{ __('Credit') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($totalAccounts as $type => $accounts)
                                    <tr>
                                        <td colspan="4" class="fw-bold" style="background: #f6f7f9;">
                                            {{ $type }}
                                        </td>
                                    </tr>

                                    @foreach ($accounts as $record)
                                        @php
                                            if (!in_array($record['account'], ['parent', 'subAccount'])) {
                                                $totalDebit += $record['totalDebit'];
                                                $totalCredit += $record['totalCredit'];
                                            }
                                        @endphp

                                        <tr>
                                            <td>
                                                @if ($record['account'] === 'parentTotal')
                                                    <span class="fw-bold">{{ $record['account_name'] }}</span>
                                                @else
                                                    {{ $record['account_name'] }}
                                                @endif
                                            </td>
                                            <td class="text-center">{{ $record['account_code'] }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($record['totalDebit']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($record['totalCredit']) }}</td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td>{{ __('TOTAL') }}</td>
                                    <td></td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($totalDebit) }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($totalCredit) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    {{-- END GOVERNMENT FORMAT AREA --}}
                </div>
            </div>
        </div>
    </div>
@endsection

