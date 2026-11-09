@extends('layouts.admin')
@section('page-title')
    {{ $event->event_number }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Kitchen Production') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen-buffet-events.index') }}">{{ __('Buffet Events') }}</a></li>
    <li class="breadcrumb-item">{{ $event->event_number }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @if ($event->isDraft())
            @can('manage kitchen buffets')
                <a href="{{ route('kitchen-buffet-events.edit', $event->id) }}" class="btn btn-sm btn-secondary">{{ __('Edit Draft') }}</a>
                <form method="POST" action="{{ route('kitchen-buffet-events.prepare', $event->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('Prepare') }}</button>
                </form>
                <form method="POST" action="{{ route('kitchen-buffet-events.cancel', $event->id) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('{{ __('Cancel this buffet event?') }}')">{{ __('Cancel') }}</button>
                </form>
            @endcan
        @endif
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3"><strong>{{ __('Date') }}</strong><br>{{ $event->event_date->format('d M Y') }}</div>
                        <div class="col-md-3"><strong>{{ __('Meal') }}</strong><br>{{ $event->meal_service ? ucfirst($event->meal_service) : '—' }}</div>
                        <div class="col-md-3"><strong>{{ __('Expected covers') }}</strong><br>{{ $event->expected_covers }} ({{ $event->covers_source === 'occupancy' ? __('from occupancy') : __('manual') }})</div>
                        <div class="col-md-3"><strong>{{ __('Status') }}</strong><br><span class="badge bg-{{ $event->status === 'completed' ? 'success' : ($event->status === 'prepared' ? 'info' : ($event->status === 'cancelled' ? 'danger' : 'warning')) }}">{{ ucfirst($event->status) }}</span></div>
                    </div>
                    @if ($event->name)
                        <p class="mt-2"><strong>{{ __('Name') }}:</strong> {{ $event->name }}</p>
                    @endif
                    @if ($event->price_per_person)
                        <p><strong>{{ __('Price per person') }}:</strong> {{ $event->price_per_person }} &nbsp; <strong>{{ __('Expected revenue') }}:</strong> {{ $summary['expected_revenue'] }}</p>
                    @endif
                    @if ($event->notes)
                        <p><strong>{{ __('Notes') }}:</strong> {{ $event->notes }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header"><h5>{{ __('Reconciliation') }}</h5></div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3"><h4>{{ $summary['prepared'] }}</h4><small class="text-muted">{{ __('Prepared') }}</small></div>
                        <div class="col-md-3"><h4>{{ $summary['used'] }}</h4><small class="text-muted">{{ __('Used') }}</small></div>
                        <div class="col-md-3"><h4>{{ $summary['leftover'] }}</h4><small class="text-muted">{{ __('Leftover (back to stock)') }}</small></div>
                        <div class="col-md-3"><h4>{{ $summary['waste'] }}</h4><small class="text-muted">{{ __('Waste') }}</small></div>
                    </div>
                    @if ($event->isCompleted())
                        @if ($reconciles)
                            <div class="alert alert-success mt-3 mb-0">{{ __('Reconciled: prepared = used + leftover + waste.') }}</div>
                        @else
                            <div class="alert alert-danger mt-3 mb-0">{{ __('Reconciliation break: prepared does not equal used + leftover + waste.') }}</div>
                        @endif
                    @elseif ($event->isPrepared())
                        <div class="alert alert-info mt-3 mb-0">{{ __('Prepared. After service, record used, leftover and waste per line below — the three must add up to what was prepared.') }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header"><h5>{{ __('Preparations / items') }}</h5></div>
                <div class="card-body">
                    @if ($event->isPrepared())
                        <form method="POST" action="{{ route('kitchen-buffet-events.close', $event->id) }}">
                            @csrf
                    @endif
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Dish / item') }}</th>
                                <th>{{ __('Per cover') }}</th>
                                <th>{{ __('Planned') }}</th>
                                <th>{{ __('Prepared') }}</th>
                                <th>{{ __('Used') }}</th>
                                <th>{{ __('Leftover') }}</th>
                                <th>{{ __('Waste') }}</th>
                                <th>{{ __('Cost') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($event->lines as $line)
                                <tr>
                                    <td>
                                        {{ $line->product->name ?? '#'.$line->product_id }}
                                        @if ($line->recipe)
                                            <br><small class="text-muted">{{ __('Recipe') }}: {{ $line->recipe->name }}</small>
                                        @endif
                                    </td>
                                    <td>{{ $line->per_cover_quantity ?? '—' }}</td>
                                    <td>{{ $line->planned_quantity }}</td>
                                    <td>{{ $line->prepared_quantity ?? '—' }}</td>
                                    @if ($event->isPrepared())
                                        <td><input type="number" step="0.0001" min="0" name="lines[{{ $line->id }}][used_quantity]" class="form-control" value="{{ $line->used_quantity ?? 0 }}" required></td>
                                        <td><input type="number" step="0.0001" min="0" name="lines[{{ $line->id }}][leftover_quantity]" class="form-control" value="{{ $line->leftover_quantity ?? 0 }}" required></td>
                                        <td><input type="number" step="0.0001" min="0" name="lines[{{ $line->id }}][waste_quantity]" class="form-control" value="{{ $line->waste_quantity ?? 0 }}" required></td>
                                    @else
                                        <td>{{ $line->used_quantity ?? '—' }}</td>
                                        <td>{{ $line->leftover_quantity ?? '—' }}</td>
                                        <td>{{ $line->waste_quantity ?? '—' }}</td>
                                    @endif
                                    <td>{{ $line->total_cost ?? '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($event->isPrepared())
                        <div class="form-group mt-3">
                            <label class="form-label">{{ __('Food-safety note') }} *</label>
                            <textarea name="food_safety_note" class="form-control" rows="2" required placeholder="{{ __('Required when leftovers are kept for the next day — e.g. how they were stored and until when they are safe to use.') }}"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary mt-3">{{ __('Close Event') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
