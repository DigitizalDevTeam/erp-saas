@extends('layouts.admin')

@section('page-title')
    {{ __('Road Transport Record') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Road Transport Record') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between flex-wrap gap-3">
                        <div>
                            <h4 class="mb-1">{{ __('Road Transport Record') }}</h4>
                            <div class="text-muted">{{ $record->record_no }}</div>
                            <div class="text-muted">{{ __('Date') }}: {{ \Auth::user()->dateFormat($record->transport_date) }}</div>
                        </div>
                        <div class="text-end">
                            @if($record->roadManifest)
                                <div><b>{{ __('Road Manifest') }}:</b>
                                    <a href="{{ route('road-manifest.show', Crypt::encrypt($record->roadManifest->id)) }}">
                                        {{ $record->roadManifest->manifest_no }}
                                    </a>
                                </div>
                            @endif
                            <div><b>{{ __('Vehicle No') }}:</b> {{ $record->vehicle_no ?? '-' }}</div>
                            <div><b>{{ __('Driver Name') }}:</b> {{ $record->driver_name ?? '-' }}</div>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-md-6">
                            <b>{{ __('From') }}:</b> {{ $record->from_location ?? '-' }}
                        </div>
                        <div class="col-md-6">
                            <b>{{ __('To') }}:</b> {{ $record->to_location ?? '-' }}
                        </div>
                        <div class="col-md-6 mt-2">
                            <b>{{ __('Departure At') }}:</b> {{ $record->departure_at ? $record->departure_at->format('Y-m-d H:i') : '-' }}
                        </div>
                        <div class="col-md-6 mt-2">
                            <b>{{ __('Arrival At') }}:</b> {{ $record->arrival_at ? $record->arrival_at->format('Y-m-d H:i') : '-' }}
                        </div>
                        <div class="col-12 mt-3">
                            <b>{{ __('Notes') }}:</b> {{ $record->notes ?? '-' }}
                        </div>
                    </div>
                </div>
                <div class="card-footer text-end">
                    <a href="{{ route('road-transport-record.create', $record->roadManifest ? Crypt::encrypt($record->roadManifest->id) : null) }}"
                        class="btn btn-sm btn-primary">{{ __('New Record') }}</a>
                </div>
            </div>
        </div>
    </div>
@endsection

