@extends('layouts.admin')
@section('page-title')
    {{ __('Cancellations') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('report.restaurant.index') }}">{{ __('Restaurant Reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Cancellations') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <form method="get" class="row g-3 mb-4">
                        <div class="col-auto">
                            <label class="form-label">{{ __('Start date') }}</label>
                            <input type="date" name="start_date" class="form-control" value="{{ $start }}">
                        </div>
                        <div class="col-auto">
                            <label class="form-label">{{ __('End date') }}</label>
                            <input type="date" name="end_date" class="form-control" value="{{ $end }}">
                        </div>
                        <div class="col-auto d-flex align-items-end">
                            <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{ __('POS #') }}</th>
                                    <th>{{ __('Branch') }}</th>
                                    <th>{{ __('Table') }}</th>
                                    <th>{{ __('Cancelled at') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($cancelled as $pos)
                                    <tr>
                                        <td>{{ $pos->pos_id ?? $pos->id }}</td>
                                        <td>{{ $pos->branch ? $pos->branch->name : '-' }}</td>
                                        <td>{{ $pos->table ? $pos->table->name : '-' }}</td>
                                        <td>{{ $pos->cancelled_at ? $pos->cancelled_at->format('Y-m-d H:i') : '-' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center text-muted">{{ __('No cancelled orders in period.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
