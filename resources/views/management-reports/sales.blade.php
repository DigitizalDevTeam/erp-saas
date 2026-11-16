@extends('layouts.admin')
@section('page-title')
    {{ __('Sales (Management)') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Sales (Management)') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Monthly revenue split by channel, from the same automatic invoice data as the P&L. Restaurante = restaurant-branch sales, Bar = sales on a table in a Bar area, Outras = everything else, so the channels always sum to the total. A * marks a value that comes from the imported figures in the Excel file.') }}</p>
                    <form method="GET" action="{{ route('management-reports.sales') }}" class="row g-2 mb-3">
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
                            <a href="{{ route('management-reports.sales.export', ['year' => $year]) }}" class="btn btn-secondary w-100">{{ __('Export Excel') }}</a>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                            <tr>
                                <th>{{ __('Sales') }}</th>
                                @foreach (['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'] as $monthKey)
                                    <th class="text-end">{{ __('month.' . $monthKey) }}</th>
                                @endforeach
                                <th class="text-end">{{ __('TOTAL') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach (['Software Faturação', 'Restaurante', 'Bar', 'Eventos', 'Outras'] as $channel)
                                @php
                                    $isTotal = $channel === 'Software Faturação';
                                @endphp
                                @foreach (['ex' => __('Sem IVA'), 'iva' => __('IVA'), 'total' => __('Total')] as $key => $label)
                                    <tr class="{{ $key === 'total' ? 'fw-bold' : '' }}">
                                        <td class="{{ $key === 'total' ? '' : 'ps-4' }}">{{ $channel }} — {{ $label }}</td>
                                        @for ($m = 1; $m <= 12; $m++)
                                            @php
                                                $histKey = $isTotal
                                                    ? ($key === 'ex' ? 'revenue_ex' : ($key === 'iva' ? 'revenue_iva' : null))
                                                    : "channel:{$channel}:{$key}";
                                                $isHist = $histKey === null
                                                    ? (! empty($historyLines[$m][$isTotal ? 'revenue_ex' : "channel:{$channel}:ex"]) && ! empty($historyLines[$m][$isTotal ? 'revenue_iva' : "channel:{$channel}:iva"]))
                                                    : ! empty($historyLines[$m][$histKey]);
                                            @endphp
                                            <td class="text-end">{{ \App\Services\ManagementMoney::format(\Auth::user(), (float) ($channels[$channel][$m][$key] ?? 0)) }}{{ $isHist ? '*' : '' }}</td>
                                        @endfor
                                        <td class="text-end">{{ \App\Services\ManagementMoney::format(\Auth::user(), (float) array_sum(array_column($channels[$channel], $key))) }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="text-muted">{{ __('* = value comes from the imported figures.') }}</p>
                </div>
            </div>
        </div>
    </div>
@endsection
