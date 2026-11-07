@extends('layouts.admin')
@section('page-title')
    {{ $batch->batch_number }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.stock.index') }}">{{ __('Kitchen Stock') }}</a></li>
    <li class="breadcrumb-item">{{ $batch->batch_number }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <h5>{{ $batch->product ? $batch->product->name : '-' }}</h5>
                    <p class="mb-1"><strong>{{ __('Batch No') }}:</strong> {{ $batch->batch_number }}</p>
                    <p class="mb-1"><strong>{{ __('Production Order') }}:</strong> @if ($batch->order) <a href="{{ route('kitchen.production-orders.show', $batch->order->id) }}">{{ $batch->order->order_number }}</a> @else - @endif</p>
                    <p class="mb-1"><strong>{{ __('Recipe') }}:</strong> {{ $batch->order && $batch->order->recipe ? $batch->order->recipe->name.' ('.$batch->order->recipe->code.' v'.$batch->order->recipe_version.')' : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('Produced') }}:</strong> {{ number_format((float) $batch->quantity_produced, 2) }} {{ $batch->unit ? $batch->unit->name : '' }}</p>
                    <p class="mb-1"><strong>{{ __('Remaining') }}:</strong> {{ number_format((float) $batch->quantity_remaining, 2) }} {{ $batch->unit ? $batch->unit->name : '' }}</p>
                    <p class="mb-1"><strong>{{ __('Warehouse') }}:</strong> {{ $batch->warehouse ? $batch->warehouse->name : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('Expiry Date') }}:</strong> {{ $batch->expiry_date ? $batch->expiry_date->format('Y-m-d') : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('Unit Cost') }}:</strong> {{ $batch->unit_cost !== null ? number_format((float) $batch->unit_cost, 2) : '-' }}</p>
                    <p class="mb-0"><strong>{{ __('Total Cost') }}:</strong> {{ $batch->total_cost !== null ? number_format((float) $batch->total_cost, 2) : '-' }}</p>
                </div>
            </div>

            @if ($batch->order)
                <div class="card">
                    <div class="card-body">
                        <h5>{{ __('Traceability — What Went Into This Batch') }}</h5>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                <tr>
                                    <th>{{ __('Ingredient') }}</th>
                                    <th>{{ __('Planned') }}</th>
                                    <th>{{ __('Actually Used') }}</th>
                                    <th>{{ __('Lots Used (FEFO)') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($batch->order->lines as $line)
                                    <tr>
                                        <td>{{ $line->ingredient ? $line->ingredient->name : '-' }}</td>
                                        <td>{{ number_format((float) $line->planned_quantity, 2) }} {{ $line->plannedUnit ? $line->plannedUnit->name : '' }}</td>
                                        <td>{{ $line->actual_quantity !== null ? number_format((float) $line->actual_quantity, 2).' '.($line->plannedUnit ? $line->plannedUnit->name : '') : '-' }}</td>
                                        <td>
                                            @forelse ($line->lotUsages as $usage)
                                                {{ $usage->lot ? $usage->lot->batch_no : __('Lot') }} ({{ number_format((float) $usage->quantity, 2) }})<br>
                                            @empty
                                                {{ __('No lots — aggregate stock') }}
                                            @endforelse
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5>{{ __('Reconciliation') }}</h5>
                    <p class="mb-1"><strong>{{ __('Produced') }}:</strong> {{ number_format($reconciliation['produced'], 2) }} {{ $batch->unit ? $batch->unit->name : '' }}</p>
                    <p class="mb-1"><strong>{{ __('Sold') }}:</strong> {{ number_format($reconciliation['sold'], 2) }} {{ $batch->unit ? $batch->unit->name : '' }}</p>
                    <p class="mb-1"><strong>{{ __('Lost') }}:</strong> {{ number_format($reconciliation['lost'], 2) }} {{ $batch->unit ? $batch->unit->name : '' }}</p>
                    <p class="mb-1"><strong>{{ __('Remaining') }}:</strong> {{ number_format($reconciliation['remaining'], 2) }} {{ $batch->unit ? $batch->unit->name : '' }}</p>
                    <p class="mb-0">
                        @if ($reconciliation['balanced'])
                            <span class="badge bg-success">{{ __('Balanced') }}</span>
                        @else
                            <span class="badge bg-danger">{{ __('Out of balance — difference :diff', ['diff' => $reconciliation['difference']]) }}</span>
                        @endif
                    </p>
                    <p class="text-muted small mb-0">{{ __('Produced − sold − lost = remaining.') }}</p>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h5>{{ __('Losses on This Batch') }}</h5>
                    @forelse ($losses as $loss)
                        <p class="mb-1">
                            <a href="{{ route('kitchen.losses.show', $loss->id) }}">{{ $loss->loss_number }}</a>
                            — {{ ucfirst(str_replace('_', ' ', $loss->category)) }},
                            {{ number_format((float) $loss->quantity, 2) }} {{ $batch->unit ? $batch->unit->name : '' }},
                            {{ ucfirst($loss->status) }}
                        </p>
                    @empty
                        <p class="mb-0 text-muted">{{ __('No losses recorded on this batch.') }}</p>
                    @endforelse
                    @can('manage kitchen losses')
                        <a href="{{ route('kitchen.losses.create', ['batch_id' => $batch->id]) }}" class="btn btn-sm btn-primary mt-2">{{ __('Record Loss') }}</a>
                    @endcan
                </div>
            </div>
        </div>
    </div>
@endsection
