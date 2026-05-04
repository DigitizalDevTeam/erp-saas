@extends('layouts.admin')

@section('page-title')
    {{ __('Comparative income statement (DRE)') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Reports') }}</li>
    <li class="breadcrumb-item">{{ __('Comparative income statement (DRE)') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card mb-3">
                <div class="card-body">
                    {{ Form::open(['route' => 'report.dre.comparativa', 'method' => 'get', 'class' => 'row g-2 align-items-end']) }}
                    <div class="col-auto">
                        {{ Form::label('year1', __('Year') . ' 1', ['class' => 'form-label']) }}
                        {{ Form::number('year1', request('year1', (int) date('Y')), ['class' => 'form-control', 'min' => 2000, 'max' => 2100]) }}
                    </div>
                    <div class="col-auto">
                        {{ Form::label('year2', __('Year') . ' 2', ['class' => 'form-label']) }}
                        {{ Form::number('year2', request('year2', (int) date('Y') - 1), ['class' => 'form-control', 'min' => 2000, 'max' => 2100]) }}
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                        <a href="{{ route('report.dre.comparativa') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                    </div>
                    {{ Form::close() }}
                    <p class="small text-muted mb-0 mt-2">
                        {{ __('Amounts are taken from accounting transaction lines by chart account code (PCGAP-style 6xx / 7xx mapping). Costs use debit − credit; revenues use credit − debit.') }}
                    </p>
                </div>
            </div>

            <div class="card mb-3" id="printableArea">
                <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center">
                    <div>
                        <strong>{{ __('Annual comparative income statement') }}</strong>
                    </div>
                    <div class="text-muted small">
                        {{ __('Fiscal years') }}: <strong>{{ $report['year1'] }}</strong> / <strong>{{ $report['year2'] }}</strong>
                        · {{ __('Currency') }}: MT
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive mb-4">
                        <table class="table table-sm table-bordered align-middle" style="font-size: 12px;">
                            <thead class="table-light">
                                <tr>
                                    <th style="min-width: 42%;"></th>
                                    <th class="text-end">{{ $report['year1'] }}</th>
                                    <th class="text-end">{{ $report['year2'] }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($filled['cost'] as $row)
                                    @if ($row['style'] === 'heading')
                                        <tr class="table-secondary">
                                            <td colspan="3" class="fw-semibold">{{ $row['label'] }}</td>
                                        </tr>
                                    @else
                                        <tr @class(['fw-semibold' => $row['style'] === 'total', 'table-light' => $row['style'] === 'subtotal'])>
                                            <td>{{ $row['label'] }}</td>
                                            <td class="text-end">
                                                @if ($row['y1'] !== null)
                                                    {{ \Auth::user()->priceFormat($row['y1']) }}
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if ($row['y2'] !== null)
                                                    {{ \Auth::user()->priceFormat($row['y2']) }}
                                                @endif
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="table-responsive mb-4">
                        <table class="table table-sm table-bordered align-middle" style="font-size: 12px;">
                            <thead class="table-light">
                                <tr>
                                    <th style="min-width: 42%;"></th>
                                    <th class="text-end" style="width: 18%;">{{ $report['year1'] }}</th>
                                    <th class="text-end" style="width: 18%;">{{ $report['year2'] }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($filled['revenue'] as $row)
                                    @if ($row['style'] === 'heading')
                                        <tr class="table-secondary">
                                            <td colspan="3" class="fw-semibold">{{ $row['label'] }}</td>
                                        </tr>
                                    @else
                                        <tr @class(['fw-semibold' => $row['style'] === 'total', 'table-light' => $row['style'] === 'subtotal'])>
                                            <td style="min-width: 42%;">{{ $row['label'] }}</td>
                                            <td class="text-end" style="width: 18%;">
                                                @if ($row['y1'] !== null)
                                                    {{ \Auth::user()->priceFormat($row['y1']) }}
                                                @endif
                                            </td>
                                            <td class="text-end" style="width: 18%;">
                                                @if ($row['y2'] !== null)
                                                    {{ \Auth::user()->priceFormat($row['y2']) }}
                                                @endif
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <h6 class="mt-3 mb-2">{{ __('Summary') }}</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered" style="font-size: 12px;">
                            <thead class="table-light">
                                <tr>
                                    <th></th>
                                    <th class="text-end">{{ $report['year1'] }}</th>
                                    <th class="text-end">{{ $report['year2'] }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($filled['summary'] as $s)
                                    <tr class="fw-semibold">
                                        <td>{{ $s['label'] }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($s['y1']) }}</td>
                                        <td class="text-end">{{ \Auth::user()->priceFormat($s['y2']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
