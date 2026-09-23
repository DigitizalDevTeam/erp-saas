@extends('layouts.admin')
@section('page-title')
    {{ __('POS Audit Log') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS Shifts') }}</a></li>
    <li class="breadcrumb-item">{{ __('Audit Log') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="get" class="row g-3 mb-4">
                        <div class="col-auto">
                            <label class="form-label">{{ __('Action') }}</label>
                            <select name="action" class="form-select form-select-sm" style="min-width:160px;">
                                <option value="">{{ __('All') }}</option>
                                @foreach([\App\Models\PosAuditLog::ACTION_OPEN_TABLE => __('Open table'), \App\Models\PosAuditLog::ACTION_CLOSE_TABLE => __('Close table'), \App\Models\PosAuditLog::ACTION_SEND_ORDER => __('Send order'), \App\Models\PosAuditLog::ACTION_CANCEL_ORDER => __('Cancel order'), \App\Models\PosAuditLog::ACTION_DISCOUNT => __('Discount'), \App\Models\PosAuditLog::ACTION_CASH_DROP => __('Cash drop'), \App\Models\PosAuditLog::ACTION_CLOSE_SHIFT => __('Close shift'), \App\Models\PosAuditLog::ACTION_REOPEN_TABLE => __('Reopen table'), \App\Models\PosAuditLog::ACTION_REFUND => __('Refund'), \App\Models\PosAuditLog::ACTION_VOID_LINE => __('Void line'), \App\Models\PosAuditLog::ACTION_DELETE_LINE => __('Delete line')] as $k => $v)
                                    <option value="{{ $k }}" {{ request('action') === $k ? 'selected' : '' }}>{{ $v }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-auto">
                            <label class="form-label">{{ __('From') }}</label>
                            <input type="date" name="date_from" class="form-control form-control-sm" value="{{ request('date_from') }}">
                        </div>
                        <div class="col-auto">
                            <label class="form-label">{{ __('To') }}</label>
                            <input type="date" name="date_to" class="form-control form-control-sm" value="{{ request('date_to') }}">
                        </div>
                        <div class="col-auto d-flex align-items-end">
                            <button type="submit" class="btn btn-sm btn-primary">{{ __('Filter') }}</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Action') }}</th>
                                    <th>{{ __('User') }}</th>
                                    <th>{{ __('Description') }}</th>
                                    <th>{{ __('IP') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($logs as $log)
                                    <tr>
                                        <td>{{ $log->created_at ? $log->created_at->format('Y-m-d H:i:s') : '-' }}</td>
                                        <td><span class="badge bg-secondary">{{ $log->action }}</span></td>
                                        <td>{{ $log->user ? $log->user->name : '-' }}</td>
                                        <td>{{ $log->description ?: '—' }}</td>
                                        <td><small>{{ $log->ip ?: '—' }}</small></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted">{{ __('No audit log entries.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-end mt-2">
                        {{ $logs->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
