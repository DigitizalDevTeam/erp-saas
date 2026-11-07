@extends('layouts.admin')
@section('page-title')
    {{ __('Cost Centre Audit Trail') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item">{{ __('Audit Trail') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Every allocation, approval, reversal and budget event for this company, newest first. The trail is append-only: events are written, never edited or deleted.') }}</p>

                    <form method="GET" action="{{ route('cost-centre-audit-trail.index') }}" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Entity') }}</label>
                            <select class="form-control" name="entity_type">
                                <option value="">{{ __('All entities') }}</option>
                                @foreach ($entityTypes as $type)
                                    <option value="{{ $type }}" {{ $selectedEntityType === $type ? 'selected' : '' }}>{{ $type }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Action') }}</label>
                            <select class="form-control" name="action">
                                <option value="">{{ __('All actions') }}</option>
                                @foreach ($actions as $act)
                                    <option value="{{ $act }}" {{ $selectedAction === $act ? 'selected' : '' }}>{{ $act }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('From') }}</label>
                            <input type="date" class="form-control" name="from" value="{{ $from }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('To') }}</label>
                            <input type="date" class="form-control" name="to" value="{{ $to }}">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('When') }}</th>
                                    <th>{{ __('Entity') }}</th>
                                    <th>{{ __('Action') }}</th>
                                    <th>{{ __('By') }}</th>
                                    <th>{{ __('Change') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($events as $event)
                                    <tr>
                                        <td>{{ $event->occurred_at }}</td>
                                        <td>{{ $event->entity_type }} #{{ $event->entity_id }}</td>
                                        <td><span class="badge bg-secondary">{{ $event->action }}</span></td>
                                        <td>{{ $event->actor_id ? ($actors[$event->actor_id] ?? '#'.$event->actor_id) : '—' }}</td>
                                        <td>
                                            @if ($event->before_json)
                                                <small class="text-muted">{{ \Illuminate\Support\Str::limit(json_encode($event->before_json), 80) }} → </small>
                                            @endif
                                            @if ($event->after_json)
                                                <small>{{ \Illuminate\Support\Str::limit(json_encode($event->after_json), 120) }}</small>
                                            @else
                                                <small class="text-muted">—</small>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted">{{ __('No audit events for this company yet.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{ $events->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
