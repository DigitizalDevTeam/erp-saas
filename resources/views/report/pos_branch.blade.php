@extends('layouts.admin')
@section('page-title')
    {{ __('POS Report by Branch') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('POS Report by Branch') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    {{ Form::open(['route' => 'report.pos.branch', 'method' => 'get', 'id' => 'pos-branch-filter']) }}
                    <div class="row gy-2">
                        <div class="col-auto">
                            {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }}
                            {{ Form::select('branch_id', $branches, $filter['branch_id'] ?? 0, ['class' => 'form-control select']) }}
                        </div>
                        <div class="col-auto">
                            {{ Form::label('start_date', __('Start Date'), ['class' => 'form-label']) }}
                            {{ Form::text('start_date', $filter['start_date'] ?? '', ['class' => 'form-control flatpickr-to-input', 'placeholder' => 'Y-m-d']) }}
                        </div>
                        <div class="col-auto">
                            {{ Form::label('end_date', __('End Date'), ['class' => 'form-label']) }}
                            {{ Form::text('end_date', $filter['end_date'] ?? '', ['class' => 'form-control flatpickr-to-input', 'placeholder' => 'Y-m-d']) }}
                        </div>
                        <div class="col-auto d-flex align-items-end">
                            <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                        </div>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
            <div class="card">
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Branch') }}</th>
                                    <th class="text-center">{{ __('Sales Count') }}</th>
                                    <th class="text-end">{{ __('Sales Total') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($byBranch as $branchId => $row)
                                    <tr>
                                        <td>{{ $row['name'] }}</td>
                                        <td class="text-center">{{ $row['count'] }}</td>
                                        <td class="text-end">{{ Auth::user()->priceFormat($row['total']) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" class="text-center">{{ __('No data in this period') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if (!empty($byBranch))
                                <tfoot>
                                    <tr class="fw-bold">
                                        <td>{{ __('Total (all branches)') }}</td>
                                        <td class="text-center">{{ $totalCount }}</td>
                                        <td class="text-end">{{ Auth::user()->priceFormat($totalSales) }}</td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
