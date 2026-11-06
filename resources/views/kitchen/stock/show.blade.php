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
    </div>
@endsection
