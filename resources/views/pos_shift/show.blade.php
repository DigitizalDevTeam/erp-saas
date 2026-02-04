@extends('layouts.admin')
@section('page-title')
    {{ __('Shift') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS Shifts') }}</a></li>
    <li class="breadcrumb-item">{{ __('View') }}</li>
@endsection

@section('action-btn')
    @can('manage pos')
        @if($pos_shift->status === 'open')
            <a href="{{ route('pos-shift.assign-table', $pos_shift) }}" class="btn btn-sm btn-warning">{{ __('Assign Tables') }}</a>
            <a href="{{ route('pos-shift.close-form', $pos_shift) }}" class="btn btn-sm btn-danger">{{ __('Close Shift') }}</a>
        @endif
        <a href="{{ route('pos-shift.index') }}" class="btn btn-sm btn-secondary">{{ __('Back') }}</a>
    @endcan
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>{{ __('Branch') }}:</strong> {{ $pos_shift->branch ? $pos_shift->branch->name : '-' }}</p>
                            <p><strong>{{ __('User') }}:</strong> {{ $pos_shift->user ? $pos_shift->user->name : '-' }}</p>
                            <p><strong>{{ __('Opened At') }}:</strong> {{ $pos_shift->opened_at ? $pos_shift->opened_at->format('Y-m-d H:i') : '-' }}</p>
                            <p><strong>{{ __('Opening Balance') }}:</strong> {{ Auth::user()->priceFormat($pos_shift->opening_balance) }}</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>{{ __('Status') }}:</strong> {{ $pos_shift->status === 'open' ? __('Open') : __('Closed') }}</p>
                            @if($pos_shift->closed_at)
                                <p><strong>{{ __('Closed At') }}:</strong> {{ $pos_shift->closed_at->format('Y-m-d H:i') }}</p>
                                <p><strong>{{ __('Closing Balance') }}:</strong> {{ Auth::user()->priceFormat($pos_shift->closing_balance) }}</p>
                            @endif
                        </div>
                    </div>
                    <hr>
                    <h6>{{ __('Table Assignments') }}</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{ __('Table') }}</th>
                                    <th>{{ __('Area') }}</th>
                                    <th>{{ __('Assigned At') }}</th>
                                    <th>{{ __('Unassigned At') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($pos_shift->tableAssignments as $a)
                                    <tr>
                                        <td>{{ $a->table ? $a->table->name : '-' }}</td>
                                        <td>{{ $a->table && $a->table->area ? $a->table->area->name : '-' }}</td>
                                        <td>{{ $a->assigned_at ? $a->assigned_at->format('Y-m-d H:i') : '-' }}</td>
                                        <td>{{ $a->unassigned_at ? $a->unassigned_at->format('Y-m-d H:i') : '-' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4">{{ __('No table assignments.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
