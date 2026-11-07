@extends('layouts.admin')
@section('page-title')
    {{ __('Kitchen Losses') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Kitchen Production') }}</li>
    <li class="breadcrumb-item">{{ __('Losses') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage kitchen losses')
            <a href="{{ route('kitchen.losses.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('Record Loss') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @if ($pendingCount > 0)
                        <div class="alert alert-warning">
                            {{ __(':count loss(es) waiting for a manager\'s approval.', ['count' => $pendingCount]) }}
                        </div>
                    @endif

                    <form method="GET" action="{{ route('kitchen.losses.index') }}" class="row g-2 mb-3">
                        <div class="col-md-4">
                            <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="{{ __('Search by loss number, product or reason') }}">
                        </div>
                        <div class="col-md-3">
                            <select name="category" class="form-control">
                                <option value="">{{ __('All categories') }}</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category }}" {{ ($filters['category'] ?? '') === $category ? 'selected' : '' }}>{{ ucfirst(str_replace('_', ' ', $category)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach ($statuses as $status)
                                    <option value="{{ $status }}" {{ ($filters['status'] ?? '') === $status ? 'selected' : '' }}>{{ ucfirst($status) }}</option>
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
                                <th>{{ __('Loss No') }}</th>
                                <th>{{ __('Category') }}</th>
                                <th>{{ __('Product') }}</th>
                                <th>{{ __('Quantity') }}</th>
                                <th>{{ __('Cost') }}</th>
                                <th>{{ __('Batch / Order') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($losses as $loss)
                                <tr>
                                    <td>{{ $loss->loss_number }}</td>
                                    <td>{{ ucfirst(str_replace('_', ' ', $loss->category)) }}</td>
                                    <td>{{ $loss->product ? $loss->product->name : ('#'.$loss->product_id) }}</td>
                                    <td>{{ number_format((float) $loss->quantity, 2) }} {{ $loss->unit ? $loss->unit->name : '' }}</td>
                                    <td>{{ \Auth::user()->priceFormat((float) $loss->total_cost) }}</td>
                                    <td>
                                        @if ($loss->batch)
                                            {{ $loss->batch->batch_number }}
                                        @elseif ($loss->order)
                                            {{ $loss->order->order_number ?? ('#'.$loss->order->id) }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        @php
                                            $badge = [
                                                'recorded' => 'bg-warning',
                                                'approved' => 'bg-info',
                                                'posted' => 'bg-success',
                                                'rejected' => 'bg-danger',
                                            ][$loss->status] ?? 'bg-secondary';
                                        @endphp
                                        <span class="badge {{ $badge }}">{{ ucfirst($loss->status) }}</span>
                                        @if ($loss->reuse_flag)
                                            <span class="badge bg-primary">{{ __('Reusable') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('kitchen.losses.show', $loss->id) }}" class="btn btn-sm btn-primary">{{ __('View') }}</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center">{{ __('No losses recorded yet.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{ $losses->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
