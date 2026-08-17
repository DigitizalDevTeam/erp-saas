@extends('layouts.admin')
@section('page-title')
    {{ __('Trial Balance') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Trial Balance') }}</li>
@endsection

@push('script-page')
    <script type="text/javascript" src="{{ asset('js/html2pdf.bundle.min.js') }}"></script>
    <script>
        var filename = $('#filename').val();

        function saveAsPDF() {
            var printContents = document.getElementById('printableArea').innerHTML;
            var originalContents = document.body.innerHTML;
            document.body.innerHTML = printContents;
            window.print();
            document.body.innerHTML = originalContents;
        }
    </script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            $("#filter").click(function() {
                $("#show_filter").toggle();
            });
        });
    </script>
@endpush

@section('action-btn')
    <div class="float-end">
        <a href="#" onclick="saveAsPDF()" class="btn btn-sm btn-primary-subtle me-1" data-bs-toggle="tooltip"
            title="{{ __('Print') }}" data-original-title="{{ __('Print') }}"><i class="ti ti-printer"></i></a>
    </div>

    <div class="float-end me-2">
        {{ Form::open(['route' => ['trial.balance.export']]) }}
        <input type="hidden" name="start_date" class="start_date" value="{{ $filter['startDateRange'] }}">
        <input type="hidden" name="end_date" class="end_date" value="{{ $filter['endDateRange'] }}">
        <input type="hidden" name="fiscal_year" value="{{ $filter['fiscalYear'] ?? '' }}">
        <input type="hidden" name="accounting_period" value="{{ $filter['accountingPeriod'] ?? '' }}">
        <button type="submit" class="btn btn-sm btn-secondary" data-bs-toggle="tooltip" title="{{ __('Export') }}"
            data-original-title="{{ __('Export') }}"><i class="ti ti-file-export"></i></button>
        {{ Form::close() }}
    </div>
    <div class="float-end me-2" id="filter">
        <button id="filter" class="btn btn-sm btn-primary" data-bs-toggle="tooltip" title="{{ __('Filter') }}" data-original-title="{{ __('Filter') }}"><i class="ti ti-filter"></i></button>
    </div>
@endsection

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="mt-2" id="multiCollapseExample1">
                <div class="card" id="show_filter" style="display:none;">
                    <div class="card-body">
                        {{ Form::open(['route' => ['trial.balance'], 'method' => 'GET', 'id' => 'report_trial_balance']) }}
                        <div class="col-xl-12">

                            <div class="row justify-content-between">
                                <div class="col-xl-3 mt-4">
                                    <div class="btn-group btn-group-toggle" data-toggle="buttons"
                                        aria-label="Basic radio toggle button group">
                                        <label class="btn btn-primary month-label">
                                            <a href="{{ route('trial.balance', ['collapse']) }}"
                                                class="text-white" id="collapse"> {{ __('Collapse') }} </a>
                                        </label>

                                        <label class="btn btn-primary year-label active">
                                            <a href="{{ route('trial.balance', ['expand']) }}"
                                                class="text-white"> {{ __('Expand') }} </a>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-xl-9">
                                    <div class="row justify-content-end align-items-center">
                                        @include('reports.partials.trial_balance_filter_fields')
                                        <div class="col-auto mt-4">
                                            <a href="#" class="btn btn-sm btn-primary"
                                                onclick="document.getElementById('report_trial_balance').submit(); return false;"
                                                data-bs-toggle="tooltip" title="{{ __('Apply') }}"
                                                data-original-title="{{ __('apply') }}">
                                                <span class="btn-inner--icon"><i class="ti ti-search"></i></span>
                                            </a>

                                            <a href="{{ route('trial.balance') }}"
                                                class="btn btn-sm btn-danger " data-bs-toggle="tooltip"
                                                title="{{ __('Reset') }}" data-original-title="{{ __('Reset') }}">
                                                <span class="btn-inner--icon"><i
                                                        class="ti ti-refresh text-white-off "></i></span>
                                            </a>
                                            <a href="{{ route('trial.balance', ['view' => 'expand', 'range' => 'ytd']) }}"
                                                class="btn btn-sm btn-outline-secondary">
                                                {{ __('Year to date') }}
                                            </a>

                                        </div>
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

    @php
        $authUser = \Auth::user()->creatorId();
        $user = App\Models\User::find($authUser);
    @endphp

    <div class="row justify-content-center" id="printableArea">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body {{ $view == 'expand' ? 'collapse-view' : '' }}">
                    <div class="account-main-title">
                        <h5 class="mb-3">{{ 'Trial Balance of ' . $user->name . ' as of ' . $filter['startDateRange'] . ' to ' . $filter['endDateRange'] }}
                            </h5>
                        @include('reports.partials.trial_balance_period_banner')
                        <p class="text-muted small mb-0 mt-1">
                            {{ __('Use Expand view for subaccounts. Parent rows include child totals — do not sum parent and child rows together.') }}
                        </p>
                    </div>
                    <div
                        class="aacount-title d-flex align-items-center justify-content-between border-top border-bottom py-2">
                        <h6 class="mb-0" style="width:28%">{{ __('Account') }}</h6>
                        <h6 class="mb-0 text-center" style="width:12%">{{ __('Account Code') }}</h6>
                        <h6 class="mb-0 text-end" style="width:15%">{{ __('Debito') }}</h6>
                        <h6 class="mb-0 text-end" style="width:15%">{{ __('Credito') }}</h6>
                        <h6 class="mb-0 text-end" style="width:15%">{{ __('Saldo Devedor') }}</h6>
                        <h6 class="mb-0 text-end" style="width:15%">{{ __('Saldo Credor') }}</h6>
                    </div>
                    @php
                        $totalDebit = $grandTotalDebit ?? 0;
                        $totalCredit = $grandTotalCredit ?? 0;
                        $grandBalance = $totalDebit - $totalCredit;
                        $grandDevedor = $grandBalance > 0 ? $grandBalance : 0;
                        $grandCredor = $grandBalance < 0 ? abs($grandBalance) : 0;
                    @endphp
                    @foreach ($totalAccounts as $type => $accounts)
                        <div class="account-main-inner border-bottom py-2">
                            @if ($type !== '' && $type !== null)
                                <p class="fw-bold ps-2 mb-2">{{ $type }}</p>
                            @endif
                            @if ($view == 'collapse')
                                @foreach ($accounts as $record)
                                    @if (in_array($record['account'] ?? '', ['parent', ''], true))
                                        <div class="account-inner d-flex align-items-center justify-content-between ps-3">
                                            <div class="mb-2 account-arrow" style="width:28%">
                                                <a href="{{ route('trial.balance', ['expand']) }}"><i
                                                        class="ti ti-chevron-down account-icon"></i></a>
                                                <a href="{{ route('report.ledger', $record['account_id']) }}?account={{ $record['account_id'] }}"
                                                    class="text-primary fw-bold">{{ $record['account_name'] }}</a>
                                            </div>
                                            <p class="mb-2 text-center" style="width:12%">{{ $record['account_code'] }}</p>
                                            <p class="text-primary fw-bold mb-2 text-end" style="width:15%">
                                                {{ \Auth::user()->priceFormat($record['totalDebit']) }}</p>
                                            <p class="text-primary mb-2 fw-bold text-end" style="width:15%">
                                                {{ \Auth::user()->priceFormat($record['totalCredit']) }}</p>
                                            <p class="text-primary mb-2 fw-bold text-end" style="width:15%">
                                                {{ \Auth::user()->priceFormat($record['balanceDebit'] ?? 0) }}</p>
                                            <p class="text-primary mb-2 fw-bold text-end" style="width:15%">
                                                {{ \Auth::user()->priceFormat($record['balanceCredit'] ?? 0) }}</p>
                                        </div>
                                    @endif
                                @endforeach
                            @else
                                @foreach ($accounts as $record)
                                    @if ($record['account'] === 'parent')
                                        <div class="account-inner d-flex align-items-center justify-content-between ps-3">
                                            <div class="mb-2 account-arrow" style="width:28%">
                                                <a href="{{ route('trial.balance', ['collapse']) }}"><i
                                                        class="ti ti-chevron-down account-icon"></i></a>
                                                <a href="{{ route('report.ledger', $record['account_id']) }}?account={{ $record['account_id'] }}"
                                                    class="text-primary fw-bold">{{ $record['account_name'] }}</a>
                                            </div>
                                            <p class="mb-2 text-center" style="width:12%">{{ $record['account_code'] }}</p>
                                            <p class="text-primary fw-bold mb-2 text-end" style="width:15%">
                                                {{ \Auth::user()->priceFormat($record['totalDebit']) }}</p>
                                            <p class="text-primary mb-2 fw-bold text-end" style="width:15%">
                                                {{ \Auth::user()->priceFormat($record['totalCredit']) }}</p>
                                            <p class="text-primary mb-2 fw-bold text-end" style="width:15%">
                                                {{ \Auth::user()->priceFormat($record['balanceDebit'] ?? 0) }}</p>
                                            <p class="text-primary mb-2 fw-bold text-end" style="width:15%">
                                                {{ \Auth::user()->priceFormat($record['balanceCredit'] ?? 0) }}</p>
                                        </div>
                                    @elseif($record['account'] === 'subAccount')
                                        <div class="account-inner d-flex align-items-center justify-content-between ps-3">
                                            <p class="mb-2 ms-4" style="width:28%"><a
                                                    href="{{ route('report.ledger', $record['account_id']) }}?account={{ $record['account_id'] }}"
                                                    class="text-primary">{{ $record['account_name'] }}</a>
                                            </p>
                                            <p class="mb-2 text-center" style="width:12%">{{ $record['account_code'] }}</p>
                                            <p class="text-primary mb-2 text-end" style="width:15%">
                                                {{ \Auth::user()->priceFormat($record['totalDebit']) }}</p>
                                            <p class="text-primary mb-2 text-end" style="width:15%">
                                                {{ \Auth::user()->priceFormat($record['totalCredit']) }}</p>
                                            <p class="text-primary mb-2 text-end" style="width:15%">
                                                {{ \Auth::user()->priceFormat($record['balanceDebit'] ?? 0) }}</p>
                                            <p class="text-primary mb-2 text-end" style="width:15%">
                                                {{ \Auth::user()->priceFormat($record['balanceCredit'] ?? 0) }}</p>
                                        </div>
                                    @else
                                        <div class="account-inner d-flex align-items-center justify-content-between ps-3">
                                            <p class="mb-2 ms-3" style="width:28%"><a
                                                    href="{{ route('report.ledger', $record['account_id']) }}?account={{ $record['account_id'] }}"
                                                    class="text-primary">{{ $record['account_name'] }}</a>
                                            </p>
                                            <p class="mb-2 text-center" style="width:12%">{{ $record['account_code'] }}</p>
                                            <p class="text-primary mb-2 text-end" style="width:15%">
                                                {{ \Auth::user()->priceFormat($record['totalDebit']) }}</p>
                                            <p class="text-primary mb-2 text-end" style="width:15%">
                                                {{ \Auth::user()->priceFormat($record['totalCredit']) }}</p>
                                            <p class="text-primary mb-2 text-end" style="width:15%">
                                                {{ \Auth::user()->priceFormat($record['balanceDebit'] ?? 0) }}</p>
                                            <p class="text-primary mb-2 text-end" style="width:15%">
                                                {{ \Auth::user()->priceFormat($record['balanceCredit'] ?? 0) }}</p>
                                        </div>
                                    @endif
                                @endforeach
                            @endif
                        </div>
                    @endforeach

                    @if ($totalAccounts != [])
                        <div
                            class="aacount-title d-flex align-items-center justify-content-between border-bottom py-2 px-2 pe-0">
                            <h6 class="fw-bold mb-0" style="width:28%">{{ 'Total' }}</h6>
                            <h6 class="fw-bold mb-0" style="width:12%">{{ '' }}</h6>
                            <h6 class="fw-bold mb-0 text-end" style="width:15%">{{ \Auth::user()->priceFormat($totalDebit) }}</h6>
                            <h6 class="fw-bold mb-0 text-end" style="width:15%">{{ \Auth::user()->priceFormat($totalCredit) }}</h6>
                            <h6 class="fw-bold mb-0 text-end" style="width:15%">{{ \Auth::user()->priceFormat($grandDevedor) }}</h6>
                            <h6 class="fw-bold mb-0 text-end" style="width:15%">{{ \Auth::user()->priceFormat($grandCredor) }}</h6>
                        </div>
                    @endif
                </div>

            </div>
        </div>
    </div>
@endsection


@push('script-page')
    <script>
        $(document).ready(function() {
            callback();

            function callback() {
                var start_date = $(".startDate").val();
                var end_date = $(".endDate").val();

                $('.start_date').val(start_date);
                $('.end_date').val(end_date);

            }
        });
    </script>
@endpush
