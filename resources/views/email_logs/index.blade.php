@extends('layouts.admin')

@section('page-title')
    {{ __('Email Logs') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Email Logs') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="get" class="row g-3 mb-4">
                        <div class="col-auto">
                            <label class="form-label">{{ __('Status') }}</label>
                            <select name="status" class="form-select form-select-sm" style="min-width:140px;">
                                <option value="">{{ __('All') }}</option>
                                <option value="success" {{ request('status') === 'success' ? 'selected' : '' }}>{{ __('Success') }}</option>
                                <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>{{ __('Failed') }}</option>
                            </select>
                        </div>
                        <div class="col-auto">
                            <label class="form-label">{{ __('Source') }}</label>
                            <input type="text" name="source" class="form-control form-control-sm" value="{{ request('source') }}" placeholder="{{ __('Template / type') }}">
                        </div>
                        <div class="col-auto">
                            <label class="form-label">{{ __('Recipient') }}</label>
                            <input type="text" name="recipient" class="form-control form-control-sm" value="{{ request('recipient') }}" placeholder="{{ __('Email') }}">
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
                            <a href="{{ route('email_logs.index') }}" class="btn btn-sm btn-danger">{{ __('Reset') }}</a>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Recipient') }}</th>
                                    <th>{{ __('Subject') }}</th>
                                    <th>{{ __('Source') }}</th>
                                    <th>{{ __('Sent By') }}</th>
                                    <th>{{ __('Error') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($logs as $log)
                                    <tr>
                                        <td>{{ $log->created_at ? $log->created_at->format('Y-m-d H:i:s') : '-' }}</td>
                                        <td>
                                            @if($log->status === 'success')
                                                <span class="badge bg-success">{{ __('Success') }}</span>
                                            @else
                                                <span class="badge bg-danger">{{ __('Failed') }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $log->recipient ?: '—' }}</td>
                                        <td>{{ $log->subject ?: '—' }}</td>
                                        <td><span class="badge bg-secondary">{{ $log->source ?: '—' }}</span></td>
                                        <td>{{ $log->user ? $log->user->name : '—' }}</td>
                                        <td>
                                            @if($log->error_message)
                                                <small class="text-danger">{{ \Illuminate\Support\Str::limit($log->error_message, 120) }}</small>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted">{{ __('No email logs found.') }}</td>
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
