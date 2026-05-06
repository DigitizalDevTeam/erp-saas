@extends('layouts.admin')
@section('page-title')
    {{ __('Balance Sheet') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Balance Sheet') }}</li>
@endsection
@push('script-page')
    <script type="text/javascript" src="{{ asset('js/html2pdf.bundle.min.js') }}"></script>
    <script>
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

@section('action-btn')
    <div class="float-end">
        <a href="#" onclick="saveAsPDF()" class="btn btn-sm btn-primary-subtle me-1" data-bs-toggle="tooltip"
            title="{{ __('Print') }}" data-original-title="{{ __('Print') }}"><i class="ti ti-printer"></i></a>
    </div>
    <div class="float-end me-2">
        {{ Form::open(['route' => ['balance.sheet.export']]) }}
        <input type="hidden" name="start_date" class="start_date">
        <input type="hidden" name="end_date" class="end_date">
        <button type="submit" class="btn btn-sm btn-secondary" data-bs-toggle="tooltip" title="{{ __('Export') }}"
            data-original-title="{{ __('Export') }}"><i class="ti ti-file-export"></i></button>
        {{ Form::close() }}
    </div>

    <div class="float-end me-2" id="filter">
        <button id="filter" class="btn btn-sm btn-primary"  data-bs-toggle="tooltip" title="{{ __('Filter') }}" data-original-title="{{ __('Filter') }}"><i class="ti ti-filter"></i></button>
    </div>
@endsection

@section('content')
    <div class="mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="mt-2" id="multiCollapseExample1">
                    <div class="card" id="show_filter" style="display:none;">
                        <div class="card-body">
                            {{ Form::open(['route' => ['report.balance.sheet'], 'method' => 'GET', 'id' => 'report_balancesheet']) }}
                            <div class="col-xl-12">
                                <div class="row justify-content-between">
                                    <div class="col-xl-12">
                                        <div class="row justify-content-end align-items-center">
                                            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                                <div class="btn-box">
                                                    {{ Form::label('start_date', __('Start Date'), ['class' => 'form-label']) }}
                                                    {{ Form::date('start_date', $filter['startDateRange'], ['class' => 'startDate form-control']) }}
                                                </div>
                                            </div>

                                            <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                                                <div class="btn-box">
                                                    {{ Form::label('end_date', __('End Date'), ['class' => 'form-label']) }}
                                                    {{ Form::date('end_date', $filter['endDateRange'], ['class' => 'endDate form-control']) }}
                                                </div>
                                            </div>
                                            <input type="hidden" name="view" value="horizontal">
                                            <div class="col-auto mt-4">
                                                <a href="#" class="btn btn-sm btn-primary"
                                                    onclick="document.getElementById('report_balancesheet').submit(); return false;"
                                                    data-bs-toggle="tooltip" title="{{ __('Apply') }}"
                                                    data-original-title="{{ __('apply') }}">
                                                    <span class="btn-inner--icon"><i class="ti ti-search"></i></span>
                                                </a>

                                                <a href="{{ route('report.balance.sheet', 'horizontal') }}"
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
                        </div>
                        {{ Form::close() }}
                    </div>
                </div>
            </div>
        </div>

        <div class="row justify-content-center" id="printableArea">
            <div class="col-md-10">
                <div class="card">
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <h5 class="mb-1">{{ $header['company_name'] }}</h5>
                            <p class="mb-0">{{ __('NUIT') }}: {{ $header['nuit'] != '' ? $header['nuit'] : '-' }}</p>
                            <p class="mb-0">{{ __('Currency') }}: {{ $header['currency'] }}</p>
                            <h6 class="mt-2 mb-0">
                                {{ __('Balance Sheet') }} ({{ $filter['currentYear'] }} / {{ $filter['previousYear'] }})
                            </h6>
                            <small class="text-muted">
                                {{ $filter['startDateRange'] }} - {{ $filter['endDateRange'] }}
                                |
                                {{ $filter['previousStartDateRange'] }} - {{ $filter['previousEndDateRange'] }}
                            </small>
                        </div>
                        <table class="table table-bordered">
                            <thead>
                                <tr class="table-light">
                                    <th>{{ __('Rubrics') }}</th>
                                    <th class="text-center">{{ __('Code Accounts') }}</th>
                                    <th class="text-end">{{ $filter['currentYear'] }}</th>
                                    <th class="text-end">{{ $filter['previousYear'] }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="table-secondary fw-bold">
                                    <td colspan="4">{{ __('Assets') }}</td>
                                </tr>
                                @foreach ($rows as $row)
                                    @if ($row['section'] === 'Assets')
                                        <tr>
                                            <td>{{ __($row['label']) }}</td>
                                            <td class="text-center">{{ $row['code_label'] }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($row['current_year']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($row['previous_year']) }}</td>
                                        </tr>
                                                            @endif
                                                        @endforeach
                                <tr class="fw-bold">
                                    <td>{{ __('Total Assets') }}</td>
                                    <td></td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($totals['assets']['current']) }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($totals['assets']['previous']) }}</td>
                                </tr>

                                <tr class="table-secondary fw-bold">
                                    <td colspan="4">{{ __('Equity') }}</td>
                                </tr>
                                @foreach ($rows as $row)
                                    @if ($row['section'] === 'Equity')
                                        <tr>
                                            <td>{{ __($row['label']) }}</td>
                                            <td class="text-center">{{ $row['code_label'] }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($row['current_year']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($row['previous_year']) }}</td>
                                        </tr>
                                                                    @endif
                                                                @endforeach
                                <tr class="fw-bold">
                                    <td>{{ __('Total Equity') }}</td>
                                    <td></td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($totals['equity']['current']) }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($totals['equity']['previous']) }}</td>
                                </tr>

                                <tr class="table-secondary fw-bold">
                                    <td colspan="4">{{ __('Liabilities') }}</td>
                                </tr>
                                @foreach ($rows as $row)
                                    @if ($row['section'] === 'Liabilities')
                                        <tr>
                                            <td>{{ __($row['label']) }}</td>
                                            <td class="text-center">{{ $row['code_label'] }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($row['current_year']) }}</td>
                                            <td class="text-end">{{ \Auth::user()->priceFormat($row['previous_year']) }}</td>
                                        </tr>
                                    @endif
                                @endforeach
                                <tr class="fw-bold">
                                    <td>{{ __('Total Liabilities') }}</td>
                                    <td></td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($totals['liabilities']['current']) }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($totals['liabilities']['previous']) }}</td>
                                </tr>

                                <tr class="table-dark fw-bold">
                                    <td>{{ __('Total Equity + Liabilities') }}</td>
                                    <td></td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($totals['equity_liabilities']['current']) }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($totals['equity_liabilities']['previous']) }}</td>
                                </tr>

                                @if (round($totals['difference']['current'], 2) != 0 || round($totals['difference']['previous'], 2) != 0)
                                    <tr class="table-warning fw-bold">
                                        <td>{{ __('Balance Difference (Assets - Equity - Liabilities)') }}</td>
                                        <td></td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($totals['difference']['current']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($totals['difference']['previous']) }}</td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
