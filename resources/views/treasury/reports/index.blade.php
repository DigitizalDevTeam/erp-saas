@extends('layouts.admin')
@section('page-title')
    {{ __('Treasury Reports') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item">{{ __('Reports') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('treasury-dashboard.index') }}" class="btn btn-sm btn-secondary">
            <i class="ti ti-dashboard"></i> {{ __('Treasury Dashboard') }}
        </a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card"><div class="card-body">
                <p class="text-muted">{{ __('Every report is filterable and exports with the same headings and rows as the screen. CSV downloads directly; XLSX and PDF are queued and appear in Recent exports below, with a download link that expires after 24 hours.') }}</p>
                <div class="table-responsive"><table class="table">
                    <thead><tr><th>{{ __('Report') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($reports as $key => $title)
                        <tr>
                            <td>{{ $title }}</td>
                            <td class="text-end"><a href="{{ route('treasury-reports.show', ['report' => $key]) }}" class="btn btn-sm btn-primary">{{ __('Open') }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            </div></div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card"><div class="card-body">
                <h5>{{ __('Recent exports') }}</h5>
                <div class="table-responsive"><table class="table">
                    <thead><tr><th>{{ __('Report') }}</th><th>{{ __('Format') }}</th><th>{{ __('Status') }}</th><th>{{ __('Requested') }}</th><th>{{ __('Expires') }}</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($exports as $export)
                        <tr>
                            <td>{{ $reports[$export->report] ?? $export->report }}</td>
                            <td>{{ strtoupper($export->format) }}</td>
                            <td>
                                @if ($export->status === 'ready')
                                    <span class="badge bg-success">{{ __('Ready') }}</span>
                                @elseif ($export->status === 'failed')
                                    <span class="badge bg-danger">{{ __('Failed') }}</span>
                                @else
                                    <span class="badge bg-warning">{{ __('Queued') }}</span>
                                @endif
                                @if ($export->error_message) <small class="text-danger">{{ $export->error_message }}</small> @endif
                            </td>
                            <td>{{ $export->created_at }}</td>
                            <td>{{ $export->expires_at }}</td>
                            <td>
                                @if (isset($downloadUrls[$export->id]))
                                    <a href="{{ $downloadUrls[$export->id] }}" class="btn btn-sm btn-primary">{{ __('Download') }}</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted">{{ __('No exports requested yet.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
            </div></div>
        </div>
    </div>
@endsection
