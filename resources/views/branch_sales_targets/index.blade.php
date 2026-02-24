@extends('layouts.admin')
@section('page-title')
    {{ __('Branch Sales Targets') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos.report') }}">{{ __('POS') }}</a></li>
    <li class="breadcrumb-item">{{ __('Branch Sales Targets') }}</li>
@endsection

@section('action-btn')
    <a href="{{ route('branch-sales-targets.create') }}" class="btn btn-sm btn-primary">
        <i class="ti ti-plus"></i> {{ __('Create Target') }}
    </a>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                                <tr>
                                    <th>{{ __('Branch') }}</th>
                                    <th>{{ __('Period') }}</th>
                                    <th class="text-end">{{ __('Target') }}</th>
                                    <th class="text-end">{{ __('Actual') }}</th>
                                    <th style="min-width: 180px;">{{ __('Progress') }}</th>
                                    <th width="120px">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($targets as $t)
                                    @php
                                        $key = $t->branch_id . '-' . $t->period_type . '-' . $t->period_value;
                                        $actual = $actualMap[$key] ?? 0;
                                        $targetVal = (float) $t->target_amount;
                                        $pct = $targetVal > 0 ? round(($actual / $targetVal) * 100, 1) : 0;
                                        $barWidth = min(100, $pct);
                                    @endphp
                                    <tr>
                                        <td>{{ $t->branch ? $t->branch->name : '-' }}</td>
                                        <td>{{ $t->period_label }}</td>
                                        <td class="text-end">{{ Auth::user()->priceFormat($t->target_amount) }}</td>
                                        <td class="text-end">{{ Auth::user()->priceFormat($actual) }}</td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress flex-grow-1" style="height: 20px;">
                                                    <div class="progress-bar {{ $pct >= 100 ? 'bg-success' : ($pct >= 50 ? 'bg-info' : 'bg-warning') }}" role="progressbar" style="width: {{ $barWidth }}%;" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                                <span class="small fw-bold">{{ $pct }}%</span>
                                            </div>
                                        </td>
                                        <td>
                                            <a href="{{ route('branch-sales-targets.edit', $t) }}" class="btn btn-sm btn-info" title="{{ __('Edit') }}"><i class="ti ti-pencil text-white"></i></a>
                                            {{ Form::open(['route' => ['branch-sales-targets.destroy', $t], 'method' => 'delete', 'class' => 'd-inline']) }}
                                            <button type="submit" class="btn btn-sm btn-danger" title="{{ __('Delete') }}" onclick="return confirm('{{ __('Are you sure?') }}');"><i class="ti ti-trash text-white"></i></button>
                                            {{ Form::close() }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-end mt-2">
                        {{ $targets->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
