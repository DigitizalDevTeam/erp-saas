@extends('layouts.admin')
@section('page-title')
    {{ __('Kitchen Stock') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Kitchen Production') }}</li>
    <li class="breadcrumb-item">{{ __('Kitchen Stock') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('kitchen.stock.index') }}" class="row g-2 mb-3">
                        <div class="col-md-5">
                            <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="{{ __('Search by batch number or preparation') }}">
                        </div>
                        <div class="col-md-3">
                            <select name="warehouse_id" class="form-control">
                                <option value="">{{ __('All warehouses') }}</option>
                                @foreach ($warehouses as $warehouse)
                                    <option value="{{ $warehouse->id }}" {{ (string) request('warehouse_id') === (string) $warehouse->id ? 'selected' : '' }}>{{ $warehouse->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                            <tr>
                                <th>{{ __('Batch No') }}</th>
                                <th>{{ __('Preparation') }}</th>
                                <th>{{ __('Order') }}</th>
                                <th>{{ __('Produced') }}</th>
                                <th>{{ __('Remaining') }}</th>
                                <th>{{ __('Warehouse') }}</th>
                                <th>{{ __('Expiry Date') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($batches as $batch)
                                <tr>
                                    <td>{{ $batch->batch_number }}</td>
                                    <td>{{ $batch->product ? $batch->product->name : '-' }}</td>
                                    <td>{{ $batch->order ? $batch->order->order_number : '-' }}</td>
                                    <td>{{ number_format((float) $batch->quantity_produced, 2) }} {{ $batch->unit ? $batch->unit->name : '' }}</td>
                                    <td>{{ number_format((float) $batch->quantity_remaining, 2) }} {{ $batch->unit ? $batch->unit->name : '' }}</td>
                                    <td>{{ $batch->warehouse ? $batch->warehouse->name : '-' }}</td>
                                    <td>
                                        @if ($batch->expiry_date)
                                            <span class="{{ $batch->isExpired() ? 'text-danger' : '' }}">{{ $batch->expiry_date->format('Y-m-d') }}</span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('kitchen.stock.show', $batch->id) }}" class="btn btn-sm btn-info" title="{{ __('View') }}"><i class="ti ti-eye"></i></a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center">{{ __('No kitchen batches yet. Complete a production order to receive the first batch.') }}</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $batches->appends(request()->query())->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
