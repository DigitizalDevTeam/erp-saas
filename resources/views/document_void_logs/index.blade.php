@extends('layouts.admin')

@section('page-title')
    {{ __('Void Audit Log') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Void Audit Log') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="get" class="row g-3 mb-4">
                        <div class="col-auto">
                            <label class="form-label">{{ __('Document Type') }}</label>
                            <select name="document_type" class="form-select form-select-sm">
                                <option value="">{{ __('All') }}</option>
                                <option value="invoice" {{ request('document_type') === 'invoice' ? 'selected' : '' }}>{{ __('Invoice') }}</option>
                                <option value="bill" {{ request('document_type') === 'bill' ? 'selected' : '' }}>{{ __('Bill') }}</option>
                                <option value="pos_line" {{ request('document_type') === 'pos_line' ? 'selected' : '' }}>{{ __('POS Line') }}</option>
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
                        <div class="col-auto d-flex align-items-end gap-2">
                            <button type="submit" class="btn btn-sm btn-primary">{{ __('Filter') }}</button>
                            <a href="{{ route('document_void_logs.index') }}" class="btn btn-sm btn-danger">{{ __('Reset') }}</a>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Type') }}</th>
                                    <th>{{ __('Document') }}</th>
                                    <th>{{ __('Reason') }}</th>
                                    <th>{{ __('Stock') }}</th>
                                    <th>{{ __('Accounting') }}</th>
                                    <th>{{ __('By') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($logs as $log)
                                    <tr>
                                        <td>{{ $log->created_at ? $log->created_at->format('Y-m-d H:i:s') : '-' }}</td>
                                        <td><span class="badge bg-secondary">{{ $log->document_type }}</span></td>
                                        <td>{{ $log->document_number ?: $log->document_id }}</td>
                                        <td>{{ $log->reason ?: '—' }}</td>
                                        <td>
                                            @if($log->stock_restored)
                                                <span class="badge bg-success">{{ __('Restored') }}</span>
                                            @else
                                                <span class="badge bg-light text-dark">{{ __('No') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if($log->accounting_note_created)
                                                <span class="badge bg-success">{{ $log->accounting_note_type ?: __('Yes') }}</span>
                                            @else
                                                <span class="badge bg-light text-dark">{{ __('No') }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $log->user ? $log->user->name : '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted">{{ __('No void log entries.') }}</td>
                                    </tr>
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
