@extends('layouts.admin')
@section('page-title')
    {{ __('POS Shifts') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos.report') }}">{{ __('POS') }}</a></li>
    <li class="breadcrumb-item">{{ __('Shifts') }}</li>
@endsection

@section('action-btn')
    @can('manage pos')
        <a href="{{ route('pos-shift.create') }}" class="btn btn-sm btn-primary">
            <i class="ti ti-plus"></i> {{ __('Open Shift') }}
        </a>
    @endcan
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
                                    <th>{{ __('User') }}</th>
                                    <th>{{ __('Opened At') }}</th>
                                    <th>{{ __('Closed At') }}</th>
                                    <th>{{ __('Opening Balance') }}</th>
                                    <th>{{ __('Closing Balance') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th width="220px">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($shifts as $shift)
                                    <tr>
                                        <td>{{ $shift->branch ? $shift->branch->name : '-' }}</td>
                                        <td>{{ $shift->user ? $shift->user->name : '-' }}</td>
                                        <td>{{ $shift->opened_at ? $shift->opened_at->format('Y-m-d H:i') : '-' }}</td>
                                        <td>{{ $shift->closed_at ? $shift->closed_at->format('Y-m-d H:i') : '-' }}</td>
                                        <td>{{ Auth::user()->priceFormat($shift->opening_balance) }}</td>
                                        <td>{{ $shift->closing_balance !== null ? Auth::user()->priceFormat($shift->closing_balance) : '-' }}</td>
                                        <td>
                                            @if($shift->status === 'open')
                                                <span class="badge bg-success">{{ __('Open') }}</span>
                                            @else
                                                <span class="badge bg-secondary">{{ __('Closed') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @can('manage pos')
                                                @if($shift->created_by == Auth::user()->creatorId())
                                                    <a href="{{ route('pos-shift.show', $shift) }}" class="btn btn-sm btn-info" title="{{ __('View') }}"><i class="ti ti-eye text-white"></i></a>
                                                    <a href="{{ route('pos-shift.report', $shift) }}" class="btn btn-sm btn-primary" title="{{ __('Report') }}" target="_blank"><i class="ti ti-file-text text-white"></i></a>
                                                    @if($shift->status === 'open')
                                                        <a href="{{ route('pos-shift.assign-table', $shift) }}" class="btn btn-sm btn-warning" title="{{ __('Assign Tables') }}"><i class="ti ti-table text-white"></i></a>
                                                        <a href="{{ route('pos-shift.close-form', $shift) }}" class="btn btn-sm btn-danger" title="{{ __('Close Shift') }}"><i class="ti ti-lock text-white"></i></a>
                                                    @endif
                                                @endif
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-end mt-2">
                        {{ $shifts->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
