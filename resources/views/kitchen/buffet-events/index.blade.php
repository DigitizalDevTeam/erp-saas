@extends('layouts.admin')
@section('page-title')
    {{ __('Buffet Events') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Kitchen Production') }}</li>
    <li class="breadcrumb-item">{{ __('Buffet Events') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage kitchen buffets')
            <a href="{{ route('kitchen-buffet-events.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('New Buffet Event') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                            <tr>
                                <th>{{ __('Event') }}</th>
                                <th>{{ __('Date') }}</th>
                                <th>{{ __('Meal') }}</th>
                                <th>{{ __('Covers') }}</th>
                                <th>{{ __('Lines') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Actions') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($events as $event)
                                <tr>
                                    <td>
                                        <a href="{{ route('kitchen-buffet-events.show', $event->id) }}">{{ $event->event_number }}</a>
                                        @if ($event->name)
                                            <br><small class="text-muted">{{ $event->name }}</small>
                                        @endif
                                    </td>
                                    <td>{{ $event->event_date->format('d M Y') }}</td>
                                    <td>{{ $event->meal_service ? ucfirst($event->meal_service) : '—' }}</td>
                                    <td>{{ $event->expected_covers }} <small class="text-muted">({{ $event->covers_source === 'occupancy' ? __('occupancy') : __('manual') }})</small></td>
                                    <td>{{ $event->lines->count() }}</td>
                                    <td><span class="badge bg-{{ $event->status === 'completed' ? 'success' : ($event->status === 'prepared' ? 'info' : ($event->status === 'cancelled' ? 'danger' : 'warning')) }}">{{ ucfirst($event->status) }}</span></td>
                                    <td>
                                        <a href="{{ route('kitchen-buffet-events.show', $event->id) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                                        @if ($event->isDraft())
                                            <a href="{{ route('kitchen-buffet-events.edit', $event->id) }}" class="btn btn-sm btn-outline-secondary">{{ __('Edit') }}</a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted">{{ __('No buffet events yet.') }}</td>
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
