@extends('layouts.admin')
@section('page-title')
    {{ __('P&L (Management)') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('P&L (Management)') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Sales come from issued invoices (automatic). Costs come from Management Documents only. Months marked with * use the imported figures from the Excel file.') }}</p>
                    <form method="GET" action="{{ route('management-reports.pl') }}" class="row g-2 mb-3">
                        <div class="col-md-2">
                            <select name="year" class="form-control">
                                @foreach ($years as $y)
                                    <option value="{{ $y }}" {{ (int) $year === $y ? 'selected' : '' }}>{{ $y }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                            <tr>
                                <th>{{ __('P&L (Management)') }}</th>
                                @foreach (['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'] as $i => $label)
                                    <th class="text-end">{{ $label }}{{ in_array($i + 1, $historyMonths) ? '*' : '' }}</th>
                                @endforeach
                                <th class="text-end">{{ __('TOTAL') }}</th>
                                <th class="text-end">{{ __('AVG') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($lines as $line)
                                <tr class="{{ $line['class'] }}">
                                    <td class="{{ $line['class'] }}">{{ $line['label'] }}</td>
                                    @for ($m = 1; $m <= 12; $m++)
                                        <td class="text-end">{{ \Auth::user()->priceFormat($line['months'][$m] ?? 0) }}</td>
                                    @endfor
                                    <td class="text-end">{{ \Auth::user()->priceFormat($line['total']) }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($line['avg']) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <h6 class="mt-4">{{ __('CONTROLOS') }}</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                            <tr>
                                <th>{{ __('Excluded from sales') }}</th>
                                @foreach (['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'] as $label)
                                    <th class="text-end">{{ $label }}</th>
                                @endforeach
                                <th class="text-end">{{ __('TOTAL') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td>{{ __('Draft invoices') }} ({{ __('count') }})</td>
                                @for ($m = 1; $m <= 12; $m++)
                                    <td class="text-end">{{ $excluded['draftInvoices'][$m]['count'] }}</td>
                                @endfor
                                <td class="text-end">{{ array_sum(array_column($excluded['draftInvoices'], 'count')) }}</td>
                            </tr>
                            <tr>
                                <td>{{ __('Draft invoices') }} ({{ __('amount, ex-VAT') }})</td>
                                @for ($m = 1; $m <= 12; $m++)
                                    <td class="text-end">{{ \Auth::user()->priceFormat($excluded['draftInvoices'][$m]['ex']) }}</td>
                                @endfor
                                <td class="text-end">{{ \Auth::user()->priceFormat(array_sum(array_column($excluded['draftInvoices'], 'ex'))) }}</td>
                            </tr>
                            <tr>
                                <td>{{ __('Open POS orders') }} ({{ __('count') }})</td>
                                @for ($m = 1; $m <= 12; $m++)
                                    <td class="text-end">{{ $excluded['openOrders'][$m]['count'] }}</td>
                                @endfor
                                <td class="text-end">{{ array_sum(array_column($excluded['openOrders'], 'count')) }}</td>
                            </tr>
                            <tr>
                                <td>{{ __('Open POS orders') }} ({{ __('amount') }})</td>
                                @for ($m = 1; $m <= 12; $m++)
                                    <td class="text-end">{{ \Auth::user()->priceFormat($excluded['openOrders'][$m]['total']) }}</td>
                                @endfor
                                <td class="text-end">{{ \Auth::user()->priceFormat(array_sum(array_column($excluded['openOrders'], 'total'))) }}</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-muted">{{ __('AVG = TOTAL / months with revenue. * = month uses imported figures.') }}</p>
                </div>
            </div>
        </div>
    </div>
@endsection
