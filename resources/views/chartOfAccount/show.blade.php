@extends('layouts.admin')
@section('page-title')
    {{ __('Account Drilldown Report') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('chart-of-account.index') }}">{{ __('Chart of Account') }}</a></li>
    <li class="breadcrumb-item">{{ __('Account Drilldown Report') }}</li>
    <li class="breadcrumb-item">{{ ucwords($account->code . ' - ' . $account->name) }}</li>
@endsection


@section('content')
    <div class="row">
        <div class="col-sm-12">
            <div class="mt-2 " id="multiCollapseExample1">
                <div class="card">
                    <div class="card-body">
                        {{ Form::open(['route' => ['chart-of-account.show', $account->id], 'method' => 'GET', 'id' => 'report_drilldown']) }}
                        <div class="row align-items-center justify-content-end">
                            <div class="col-xl-10">
                                <div class="row">
                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="btn-box">
                                        </div>
                                    </div>
                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="btn-box">
                                            {{ Form::label('start_date', __('Start Date'), ['class' => 'form-label']) }}
                                            {{ Form::date('start_date', $filter['startDateRange'], ['class' => 'month-btn form-control']) }}
                                        </div>
                                    </div>
                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="btn-box">
                                            {{ Form::label('end_date', __('End Date'), ['class' => 'form-label']) }}
                                            {{ Form::date('end_date', $filter['endDateRange'], ['class' => 'month-btn form-control']) }}
                                        </div>
                                    </div>
                                    <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                        <div class="btn-box">
                                            {{ Form::label('account', __('Account'), ['class' => 'form-label']) }}
                                            {{ Form::select('account', $accounts, isset($_GET['account']) ? $_GET['account'] : '', ['class' => 'form-control select']) }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-auto">
                                <div class="row">
                                    <div class="col-auto mt-4">
                                        <a href="#" class="btn btn-sm btn-primary"
                                            onclick="document.getElementById('report_drilldown').submit(); return false;"
                                            data-bs-toggle="tooltip" title="{{ __('Apply') }}"
                                            data-original-title="{{ __('apply') }}">
                                            <span class="btn-inner--icon"><i class="ti ti-search"></i></span>
                                        </a>
                                        <a href="{{ route('chart-of-account.show', $account->id) }}"
                                            class="btn btn-sm btn-danger " data-bs-toggle="tooltip"
                                            title="{{ __('Reset') }}" data-original-title="{{ __('Reset') }}">
                                            <span class="btn-inner--icon"><i
                                                    class="ti ti-refresh text-white-off "></i></span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>



    <div id="printableArea">
        <div class="row mt-2">
            <div class="col-3">
                <div class="card p-4 mb-4">
                    <h6 class="mb-0">{{ __('Report') }} :</h6>
                    <h7 class="text-sm mb-0">{{ __('Account Drilldown') }}</h7>
                </div>
            </div>

            @if (!empty($account))
                <div class="col-3">
                    <div class="card p-4 mb-4">
                        <h6 class="mb-0">{{ __('Account Name') }} :</h6>
                        <h7 class="text-sm mb-0">{{ $account->name }}</h7>
                    </div>
                </div>
                <div class="col-3">
                    <div class="card p-4 mb-4">
                        <h6 class="mb-0">{{ __('Account Code') }} :</h6>
                        <h7 class="text-sm mb-0">{{ $account->code }}</h7>
                    </div>
                </div>
            @endif

            <div class="col-3">
                <div class="card p-4 mb-4">
                    <h6 class="mb-0">{{ __('Duration') }} :</h6>
                    <h7 class="text-sm mb-0">{{ $filter['startDateRange'] . ' to ' . $filter['endDateRange'] }}</h7>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12 mb-4">
                <div class="card">
                    <div class="card-body table-border-style">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th> {{ __('Account Name') }}</th>
                                        <th> {{ __('Name') }}</th>
                                        <th> {{ __('Transaction Type') }}</th>
                                        <th> {{ __('Transaction Date') }}</th>
                                        <th> {{ __('Debit') }}</th>
                                        <th> {{ __('Credit') }}</th>
                                        <th> {{ __('Balance') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php
                                        $balance = 0;
                                        $totalDebit = 0;
                                        $totalCredit = 0;
                                        $chartDatas = App\Models\Utility::getAccountData($account->id, $filter['startDateRange'], $filter['endDateRange']);
                                        $accountName = \App\Models\ChartOfAccount::find($account->id);
                                    @endphp

                                    @foreach ($chartDatas as $account)
                                        @if ($account->reference == 'Invoice')
                                            <tr>
                                                <td>{{ $accountName->name }}</td>
                                                <td>{{ $account->user_name }}</td>
                                                <td>{{ \Auth::user()->invoiceNumberFormat($account->ids) }}</td>
                                                <td>{{ $account->date }}</td>
                                                <td>{{ \Auth::user()->priceFormat($account->debit) }}</td>
                                                @php
                                                    $total = $account->debit + $account->credit;
                                                    $balance += $total;
                                                    $totalCredit += $total;
                                                @endphp
                                                <td>{{ \Auth::user()->priceFormat($account->credit) }}</td>
                                                <td>{{ \Auth::user()->priceFormat($balance) }}</td>
                                            </tr>
                                        @endif

                                        @if ($account->reference == 'Invoice Payment')
                                            <tr>
                                                <td>{{ $accountName->name }}</td>
                                                <td>{{ $account->user_name }}</td>
                                                <td>{{ \Auth::user()->invoiceNumberFormat($account->ids) }}{{ __(' Manually Payment') }}</td>
                                                <td>{{ $account->date }}</td>
                                                <td>{{ \Auth::user()->priceFormat($account->debit) }}</td>
                                                @php
                                                    $total = $account->debit + $account->credit;
                                                    $balance -= $total;
                                                @endphp
                                                <td>{{ \Auth::user()->priceFormat($account->credit) }}</td>
                                                <td>{{ \Auth::user()->priceFormat($balance) }}</td>
                                            </tr>
                                        @endif

                                        @if ($account->reference == 'Revenue')
                                            <tr>
                                                <td>{{ $accountName->name }}</td>
                                                <td>{{ $account->user_name }}</td>
                                                <td>{{ __(' Revenue') }}</td>
                                                <td>{{ $account->date }}</td>
                                                <td>{{ \Auth::user()->priceFormat($account->debit) }}</td>
                                                @php
                                                    $total = $account->debit + $account->credit;
                                                    $balance += $total;
                                                @endphp
                                                <td>{{ \Auth::user()->priceFormat($account->credit) }}</td>
                                                <td>{{ \Auth::user()->priceFormat($balance) }}</td>
                                            </tr>
                                        @endif

                                        @if ($account->reference == 'Bill' || $account->reference == 'Bill Account' || $account->reference == 'Expense' || $account->reference == 'Expense Account')
                                            <tr>
                                                <td>{{ $accountName->name }}</td>
                                                <td>{{ $account->user_name }}</td>
                                                @if ($account->reference == 'Bill' || $account->reference == 'Bill Account')
                                                    <td>{{ \Auth::user()->billNumberFormat($account->ids) }}
                                                @else
                                                    <td>{{ \Auth::user()->expenseNumberFormat($account->ids) }}
                                                @endif
                                                <td>{{ $account->date }}</td>
                                                <td>{{ \Auth::user()->priceFormat($account->debit) }}</td>
                                                @php
                                                    $total = $account->debit + $account->credit;
                                                    $balance -= $total;
                                                @endphp
                                                <td>{{ \Auth::user()->priceFormat($account->credit) }}</td>
                                                <td>{{ \Auth::user()->priceFormat($balance) }}</td>
                                            </tr>
                                        @endif

                                        @if ($account->reference == 'Bill Payment' || $account->reference == 'Expense Payment')
                                            <tr>
                                                <td>{{ $accountName->name }}</td>
                                                <td>{{ $account->user_name }}</td>
                                                @if ($account->reference == 'Bill Payment')
                                                    <td>{{ \Auth::user()->billNumberFormat($account->ids) }}{{ __(' Manually Payment') }}
                                                @else
                                                    <td>{{ \Auth::user()->expenseNumberFormat($account->ids) }}{{ __(' Manually Payment') }}
                                                @endif
                                                <td>{{ $account->date }}</td>
                                                <td>{{ \Auth::user()->priceFormat($account->debit) }}</td>
                                                @php
                                                    $total = $account->debit + $account->credit;
                                                    $balance -= $total;
                                                @endphp
                                                <td>{{ \Auth::user()->priceFormat($account->credit) }}</td>
                                                <td>{{ \Auth::user()->priceFormat($balance) }}</td>
                                            </tr>
                                        @endif

                                        @if ($account->reference == 'Payment')
                                            <tr>
                                                <td>{{ $accountName->name }}</td>
                                                <td>{{ $account->user_name }}</td>
                                                <td>{{ __('Payment') }}</td>
                                                <td>{{ $account->date }}</td>
                                                <td>{{ \Auth::user()->priceFormat($account->debit) }}</td>
                                                @php
                                                    $total = $account->debit + $account->credit;
                                                    $balance -= $total;
                                                @endphp
                                                <td>{{ \Auth::user()->priceFormat($account->credit) }}</td>
                                                <td>{{ \Auth::user()->priceFormat($balance) }}</td>
                                            </tr>
                                        @endif

                                        @if ($account->reference == 'Journal')
                                            <tr>
                                                <td>{{ $accountName->name }}</td>
                                                <td>{{ '-' }}</td>
                                                <td>{{ AUth::user()->journalNumberFormat($account->journal_number ?? $account->reference_id) }}</td>
                                                <td>{{ $account->date }}</td>
                                                <td>{{ \Auth::user()->priceFormat($account->debit) }}</td>
                                                @php
                                                    $total = $account->credit - $account->debit;
                                                    $balance += $total;
                                                @endphp
                                                <td>{{ \Auth::user()->priceFormat($account->credit) }}</td>
                                                <td>{{ \Auth::user()->priceFormat($balance) }}</td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
