@extends('layouts.admin')
@section('page-title')
    {{ __('Costs by Category') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Costs by Category') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('One row per category, months Jan–Dec + TOTAL. Quotations are excluded. Groups CapEx / Passivo / Financiamento never touch the P&L.') }}</p>
                    <form method="GET" action="{{ route('management-reports.costs') }}" class="row g-2 mb-3">
                        <div class="col-md-2">
                            <select name="year" class="form-control">
                                @foreach ($years as $y)
                                    <option value="{{ $y }}" {{ (int) $year === $y ? 'selected' : '' }}>{{ $y }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="cost_centre" class="form-control">
                                <option value="all" {{ $costCentre === 'all' ? 'selected' : '' }}>{{ __('All cost centres') }}</option>
                                <option value="none" {{ $costCentre === 'none' ? 'selected' : '' }}>{{ __('No cost centre') }}</option>
                                @foreach ($table['cost_centres'] as $cc)
                                    <option value="{{ $cc['id'] }}" {{ (string) $costCentre === (string) $cc['id'] ? 'selected' : '' }}>{{ $cc['label'] }}</option>
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
                                <th>{{ __('Category') }}</th>
                                <th>{{ __('P&L Group') }}</th>
                                @foreach (['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'] as $label)
                                    <th class="text-end">{{ $label }}</th>
                                @endforeach
                                <th class="text-end">{{ __('TOTAL') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @php $currentGroup = null; @endphp
                            @foreach ($table['rows'] as $row)
                                @if ($row['group'] !== $currentGroup)
                                    @php $currentGroup = $row['group']; @endphp
                                    @foreach ($table['subtotals'] as $sub)
                                        @if ($sub['group'] === $currentGroup)
                                            <tr class="fw-bold table-light">
                                                <td>{{ $sub['label'] }}</td>
                                                <td>{{ $sub['group'] }}</td>
                                                @for ($m = 1; $m <= 12; $m++)
                                                    <td class="text-end">{{ \Auth::user()->priceFormat($sub['months'][$m] ?? 0) }}</td>
                                                @endfor
                                                <td class="text-end">{{ \Auth::user()->priceFormat($sub['total']) }}</td>
                                            </tr>
                                        @endif
                                    @endforeach
                                @endif
                                <tr>
                                    <td class="ps-4">{{ $row['label'] }}</td>
                                    <td>{{ $row['group'] }}</td>
                                    @for ($m = 1; $m <= 12; $m++)
                                        <td class="text-end">{{ \Auth::user()->priceFormat($row['months'][$m] ?? 0) }}</td>
                                    @endfor
                                    <td class="text-end">{{ \Auth::user()->priceFormat($row['total']) }}</td>
                                </tr>
                            @endforeach
                            <tr class="fw-bold">
                                <td>{{ __('TOTAL GERAL') }}</td>
                                <td></td>
                                @for ($m = 1; $m <= 12; $m++)
                                    <td class="text-end">{{ \Auth::user()->priceFormat($table['total_geral'][$m] ?? 0) }}</td>
                                @endfor
                                <td class="text-end">{{ \Auth::user()->priceFormat(array_sum($table['total_geral'])) }}</td>
                            </tr>
                            <tr>
                                <td>{{ __('Documents without a cost centre') }}</td>
                                <td></td>
                                @for ($m = 1; $m <= 12; $m++)
                                    <td class="text-end">{{ \Auth::user()->priceFormat($table['no_cost_centre'][$m] ?? 0) }}</td>
                                @endfor
                                <td class="text-end">{{ \Auth::user()->priceFormat(array_sum($table['no_cost_centre'])) }}</td>
                            </tr>
                            <tr>
                                <td>{{ __('Unmapped') }}</td>
                                <td></td>
                                @for ($m = 1; $m <= 12; $m++)
                                    <td class="text-end">{{ \Auth::user()->priceFormat($table['unmapped'][$m] ?? 0) }}</td>
                                @endfor
                                <td class="text-end">{{ \Auth::user()->priceFormat(array_sum($table['unmapped'])) }}</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
