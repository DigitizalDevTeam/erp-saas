@extends('layouts.admin')
@section('page-title')
    {{ __('Fixed Asset Reports') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item">{{ __('Reports') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('fixed-asset-reports.dashboard') }}" class="btn btn-sm btn-secondary">
            {{ __('Dashboard') }}
        </a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted"><small>{{ __('One service builds every report, so the screen, the CSV, the Excel file and the PDF can never disagree. CSV downloads directly; Excel and PDF are queued and appear in Recent exports below.') }}</small></p>

                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>{{ __('Report') }}</th>
                                    <th>{{ __('Open') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($reports as $key => $label)
                                    <tr>
                                        <td>{{ __($label) }}</td>
                                        <td><a href="{{ route('fixed-asset-reports.show', $key) }}" class="btn btn-sm btn-primary">{{ __('Open') }}</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($exports->count() > 0)
                        <h5 class="mt-4">{{ __('Recent exports') }}</h5>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>{{ __('Report') }}</th>
                                        <th>{{ __('Format') }}</th>
                                        <th>{{ __('Status') }}</th>
                                        <th>{{ __('Requested') }}</th>
                                        <th>{{ __('Download') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($exports as $export)
                                        <tr>
                                            <td>{{ __($reports[$export->report] ?? $export->report) }}</td>
                                            <td>{{ strtoupper($export->format) }}</td>
                                            <td>{{ ucfirst($export->status) }}</td>
                                            <td>{{ $export->created_at ? $export->created_at->format('Y-m-d H:i') : '—' }}</td>
                                            <td>
                                                @if (isset($downloadUrls[$export->id]))
                                                    <a href="{{ $downloadUrls[$export->id] }}">{{ __('Download') }}</a>
                                                @elseif ($export->status === 'failed')
                                                    <span class="text-danger">{{ $export->error_message ?? __('Failed') }}</span>
                                                @else
                                                    —
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted"><small>{{ __('Download links are signed and expire 24 hours after the export was requested.') }}</small></p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
