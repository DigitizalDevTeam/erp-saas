@extends('layouts.admin')
@section('page-title')
    {{ __('Cash Discrepancies') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('report.restaurant.index') }}">{{ __('Restaurant Reports') }}</a></li>
    <li class="breadcrumb-item">{{ __('Cash Discrepancies') }}</li>
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
                                    <th>{{ __('Shift') }}</th>
                                    <th>{{ __('Branch') }}</th>
                                    <th>{{ __('User') }}</th>
                                    <th>{{ __('Closed at') }}</th>
                                    <th class="text-end">{{ __('Difference') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($shifts as $s)
                                    <tr>
                                        <td>#{{ $s->id }}</td>
                                        <td>{{ $s->branch ? $s->branch->name : '—' }}</td>
                                        <td>{{ $s->user ? $s->user->name : '—' }}</td>
                                        <td>{{ $s->closed_at ? $s->closed_at->format('Y-m-d H:i') : '—' }}</td>
                                        <td class="text-end">{{ Auth::user()->priceFormat($s->difference ?? 0) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted">{{ __('No cash discrepancies in period.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
