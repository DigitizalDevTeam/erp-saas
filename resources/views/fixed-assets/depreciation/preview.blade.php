@extends('layouts.admin')
@section('page-title')
    {{ __('Depreciation Preview — :period', ['period' => $period]) }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('fixed-asset-depreciation.index') }}">{{ __('Depreciation') }}</a></li>
    <li class="breadcrumb-item">{{ __('Preview') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5>{{ __('Preview for :period — nothing is posted yet', ['period' => $period]) }}</h5>
                </div>
                <div class="card-body">
                    @if (empty($lines))
                        <p class="text-muted">{{ __('No assets have depreciation due for this period.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>{{ __('Asset') }}</th>
                                        <th>{{ __('Planned (schedule)') }}</th>
                                        <th>{{ __('To Post') }}</th>
                                        <th>{{ __('Final Period') }}</th>
                                        <th>{{ __('Cost Centre Split') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($lines as $line)
                                        <tr>
                                            <td>
                                                <strong>{{ $line['asset']->code }}</strong><br>
                                                {{ $line['asset']->name }}
                                            </td>
                                            <td>{{ number_format((float) $line['planned'], 4) }}</td>
                                            <td><strong>{{ number_format((float) $line['amount'], 2) }}</strong></td>
                                            <td>{{ $line['is_last'] ? __('Yes') : __('No') }}</td>
                                            <td>
                                                @forelse ($line['split'] as $segment)
                                                    {{ $segment['cost_centre_code'] ?? $segment['cost_centre_id'] }} ({{ number_format((float) $segment['percentage'], 2) }}%)<br>
                                                @empty
                                                    —
                                                @endforelse
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th>{{ __('Total') }}</th>
                                        <th></th>
                                        <th>{{ number_format((float) $total, 2) }}</th>
                                        <th></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <form method="POST" action="{{ route('fixed-asset-depreciation.store') }}" class="mt-3">
                            @csrf
                            <input type="hidden" name="period" value="{{ $period }}">
                            <button type="submit" class="btn btn-primary">{{ __('Create Run') }}</button>
                            <a href="{{ route('fixed-asset-depreciation.create') }}" class="btn btn-light">{{ __('Back') }}</a>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
