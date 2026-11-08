@extends('layouts.admin')
@section('page-title')
    {{ __('Fixed Asset Audit Trail') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Fixed Assets') }}</li>
    <li class="breadcrumb-item">{{ __('Audit Trail') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('fixed-asset-reports.show', ['report' => 'audit-trail']) }}" class="btn btn-sm btn-secondary">{{ __('Audit Trail Report') }}</a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted"><small>{{ __('Who did what, when, on which asset — every recorded Fixed Assets event, newest first. Audit events are never edited or deleted.') }}</small></p>

                    <form method="GET" action="{{ route('fixed-asset-audit-trail.index') }}" class="row g-2 mb-3">
                        <div class="col-md-2">
                            <input type="date" name="from" class="form-control" value="{{ $filters['from'] ?? '' }}" placeholder="{{ __('From') }}">
                        </div>
                        <div class="col-md-2">
                            <input type="date" name="to" class="form-control" value="{{ $filters['to'] ?? '' }}" placeholder="{{ __('To') }}">
                        </div>
                        <div class="col-md-4">
                            <input type="text" name="search" class="form-control" placeholder="{{ __('Search asset, entity, action or reason') }}" value="{{ $filters['search'] ?? '' }}">
                        </div>
                        <div class="col-md-2">
                            <button class="btn btn-sm btn-primary" type="submit">{{ __('Filter') }}</button>
                            <a href="{{ route('fixed-asset-audit-trail.index') }}" class="btn btn-sm btn-light">{{ __('Clear') }}</a>
                        </div>
                    </form>

                    @if ($events->count() > 0)
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>{{ __('Time') }}</th>
                                        <th>{{ __('Actor') }}</th>
                                        <th>{{ __('Asset') }}</th>
                                        <th>{{ __('Entity') }}</th>
                                        <th>{{ __('Action') }}</th>
                                        <th>{{ __('Reason') }}</th>
                                        <th>{{ __('Before') }}</th>
                                        <th>{{ __('After') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($events as $event)
                                        <tr>
                                            <td>{{ $event->created_at ? $event->created_at->format('Y-m-d H:i') : '—' }}</td>
                                            <td>{{ $event->actor ? $event->actor->name : 'User #'.$event->actor_id }}</td>
                                            <td>{{ $event->asset ? $event->asset->code : '—' }}</td>
                                            <td>{{ $event->entity }}</td>
                                            <td>{{ $event->action }}</td>
                                            <td>{{ $event->reason ?? '—' }}</td>
                                            <td><small>{{ $event->before_values ? json_encode($event->before_values) : '—' }}</small></td>
                                            <td><small>{{ $event->after_values ? json_encode($event->after_values) : '—' }}</small></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $events->links() }}
                    @else
                        <p class="text-muted">{{ __('No audit events for these filters.') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
