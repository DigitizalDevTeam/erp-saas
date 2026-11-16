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
                    <p class="text-muted">{{ __('Sales come from issued invoices (automatic). Costs come from Management Documents and mapped manual journals. A * marks a value that comes from the imported figures in the Excel file.') }}</p>
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
                        <div class="col-md-2">
                            <a href="{{ route('management-reports.pl.export', ['year' => $year]) }}" class="btn btn-secondary w-100">{{ __('Export Excel') }}</a>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                            <tr>
                                <th>{{ __('P&L (Management)') }}</th>
                                @foreach (['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'] as $monthKey)
                                    <th class="text-end">{{ __('month.' . $monthKey) }}</th>
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
                                        <td class="text-end">@if (! empty($line['link']) && ($line['months'][$m] ?? 0) != 0)<a href="{{ route('management-documents.index', array_filter(['year' => $year, 'month' => $m, 'management_cost_centre_id' => $line['centreId'] ?? null])) }}">{{ \App\Services\ManagementMoney::format(\Auth::user(), (float) ($line['months'][$m] ?? 0)) }}</a>{{ ! empty($line['history'][$m]) ? '*' : '' }}@else{{ \App\Services\ManagementMoney::format(\Auth::user(), (float) ($line['months'][$m] ?? 0)) }}{{ ! empty($line['history'][$m]) ? '*' : '' }}@endif</td>
                                    @endfor
                                    <td class="text-end">{{ \App\Services\ManagementMoney::format(\Auth::user(), (float) $line['total']) }}</td>
                                    <td class="text-end">{{ \App\Services\ManagementMoney::format(\Auth::user(), (float) $line['avg']) }}</td>
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
                                @foreach (['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'] as $monthKey)
                                    <th class="text-end">{{ __('month.' . $monthKey) }}</th>
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
                                    <td class="text-end">{{ \App\Services\ManagementMoney::format(\Auth::user(), (float) $excluded['draftInvoices'][$m]['ex']) }}</td>
                                @endfor
                                <td class="text-end">{{ \App\Services\ManagementMoney::format(\Auth::user(), (float) array_sum(array_column($excluded['draftInvoices'], 'ex'))) }}</td>
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
                                    <td class="text-end">{{ \App\Services\ManagementMoney::format(\Auth::user(), (float) $excluded['openOrders'][$m]['total']) }}</td>
                                @endfor
                                <td class="text-end">{{ \App\Services\ManagementMoney::format(\Auth::user(), (float) array_sum(array_column($excluded['openOrders'], 'total'))) }}</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-muted">{{ __('AVG = TOTAL / months with revenue. * = value comes from the imported figures.') }}</p>
                </div>
            </div>
        </div>
    </div>
@endsection
