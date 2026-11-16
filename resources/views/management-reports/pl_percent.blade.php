@extends('layouts.admin')
@section('page-title')
    {{ __('%P&L (Management)') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('%P&L (Management)') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Every line as % of that month\'s sales. Delivery costs are their own line, split out of VARIABLE. A * marks a value that comes from the imported figures in the Excel file.') }}</p>
                    <form method="GET" action="{{ route('management-reports.pl-percent') }}" class="row g-2 mb-3">
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
                            <a href="{{ route('management-reports.pl-percent.export', ['year' => $year]) }}" class="btn btn-secondary w-100">{{ __('Export Excel') }}</a>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                            <tr>
                                <th>{{ __('%P&L (Management)') }}</th>
                                @foreach (['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'] as $monthKey)
                                    <th class="text-end">{{ __('month.' . $monthKey) }}</th>
                                @endforeach
                                <th class="text-end">{{ __('TOTAL') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($lines as $line)
                                <tr class="{{ $line['class'] }}">
                                    <td class="{{ $line['class'] }}">{{ $line['label'] }}</td>
                                    @for ($m = 1; $m <= 12; $m++)
                                        <td class="text-end">{{ $line['kind'] === 'money' ? \App\Services\ManagementMoney::format(\Auth::user(), (float) ($line['months'][$m] ?? 0)) : number_format($line['months'][$m] ?? 0, 1) . '%' }}{{ ! empty($line['history'][$m]) ? '*' : '' }}</td>
                                    @endfor
                                    <td class="text-end">
                                        @if ($line['total'] === null)
                                            -
                                        @elseif ($line['kind'] === 'money')
                                            {{ \App\Services\ManagementMoney::format(\Auth::user(), (float) $line['total']) }}
                                        @else
                                            {{ number_format($line['total'], 1) . '%' }}
                                        @endif
                                    </td>
                                </tr>
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
