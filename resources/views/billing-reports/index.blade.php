@extends('layouts.admin')
@section('page-title')
    {{ __('Billing Reports') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Sales') }}</li>
    <li class="breadcrumb-item">{{ __('Billing Reports') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('billing-reports.dashboard') }}" class="btn btn-sm btn-secondary">
            {{ __('Billing Dashboard') }}
        </a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted"><small>{{ __('Every report is filterable and exports to CSV, Excel and PDF. The file carries the same headings and rows as the screen. Forecasts are estimates — planned and issued amounts are never mixed.') }}</small></p>
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>{{ __('Report') }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($reports as $key => $label)
                                <tr>
                                    <td>{{ __($label) }}</td>
                                    <td><a href="{{ route('billing-reports.show', $key) }}" class="btn btn-sm btn-primary">{{ __('Open') }}</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <h5 class="mt-4">{{ __('Recent exports') }}</h5>
                    @if ($exports->count() > 0)
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>{{ __('Report') }}</th>
                                    <th>{{ __('Format') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Requested') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($exports as $export)
                                    <tr>
                                        <td>{{ $reports[$export->report] ?? $export->report }}</td>
                                        <td>{{ strtoupper($export->format) }}</td>
                                        <td>{{ ucfirst($export->status) }}</td>
                                        <td>{{ $export->created_at ? $export->created_at->format('Y-m-d H:i') : '—' }}</td>
                                        <td>
                                            @if (isset($downloadUrls[$export->id]))
                                                <a href="{{ $downloadUrls[$export->id] }}" class="btn btn-sm btn-primary">{{ __('Download') }}</a>
                                            @elseif ($export->status === 'failed')
                                                <span class="text-danger">{{ $export->error_message }}</span>
                                            @else
                                                <span class="text-muted">{{ __('Queued…') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="text-muted">{{ __('No exports yet. Open a report and choose CSV, Excel or PDF.') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
