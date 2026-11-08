@extends('layouts.admin')
@section('page-title')
    {{ __('Depreciation Report — :period', ['period' => $period]) }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-depreciation.index') }}">{{ __('Depreciation') }}</a></li>
    <li class="breadcrumb-item">{{ __('Monthly Report') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('fixed-asset-depreciation.report') }}" class="row g-2 mb-3">
                        <div class="col-md-4">
                            <input type="month" name="period" value="{{ $period }}" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Show') }}</button>
                        </div>
                    </form>

                    <dl class="row">
                        <dt class="col-sm-4">{{ __('This Period (:period)', ['period' => $period]) }} {{ $totals['period_posted'] ? __('(posted)') : __('(planned)') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $totals['period_total'], 2) }}</dd>
                        <dt class="col-sm-4">{{ __('Year-to-Date Posted') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $totals['ytd_posted'], 2) }}</dd>
                        <dt class="col-sm-4">{{ __('Remaining Planned') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $totals['remaining_planned'], 2) }}</dd>
                    </dl>

                    <h6 class="mt-4">{{ __('Assets — :period', ['period' => $period]) }}</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>{{ __('Asset') }}</th>
                                    <th>{{ __('Planned') }}</th>
                                    <th>{{ __('Amount') }}</th>
                                    <th>{{ __('Cost Centre') }}</th>
                                    <th>{{ __('Posted') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($totals['lines'] as $line)
                                    <tr>
                                        <td>
                                            <strong>{{ $line['asset']->code }}</strong><br>
                                            {{ $line['asset']->name }}
                                        </td>
                                        <td>{{ number_format((float) $line['planned'], 4) }}</td>
                                        <td>{{ number_format((float) $line['amount'], 2) }}</td>
                                        <td>{{ $line['cost_centre_code'] ?? '—' }}</td>
                                        <td>{{ $line['posted'] ? __('Yes') : __('No') }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center">{{ __('No depreciation for this period.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
