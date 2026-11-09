@extends('layouts.admin')
@section('page-title')
    {{ __('Daily Specials') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Kitchen Production') }}</li>
    <li class="breadcrumb-item">{{ __('Daily Specials') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('manage kitchen daily specials')
            <a href="{{ route('kitchen-daily-specials.plan') }}" class="btn btn-sm btn-secondary">
                <i class="ti ti-calendar"></i> {{ __('Plan Week') }}
            </a>
            <a href="{{ route('kitchen-daily-specials.create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i> {{ __('New Special') }}
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('kitchen-daily-specials.index') }}" class="row g-2 mb-3">
                        <div class="col-md-4">
                            <input type="date" name="special_date" value="{{ $date }}" class="form-control">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Show') }}</button>
                        </div>
                    </form>

                    @if ($today->isNotEmpty())
                        <div class="alert alert-info">
                            <strong>{{ __('On the POS today:') }}</strong>
                            {{ $today->map(fn ($s) => $s->product->name.' ('.$s->selling_price.')')->join(', ') }}
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                            <tr>
                                <th>{{ __('Date') }}</th>
                                <th>{{ __('Dish') }}</th>
                                <th>{{ __('Qty planned') }}</th>
                                <th>{{ __('Price') }}</th>
                                <th>{{ __('Type') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Actions') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($specials as $special)
                                <tr>
                                    <td>{{ $special->special_date->format('d M Y') }}</td>
                                    <td>{{ $special->product->name ?? '#'.$special->product_id }}</td>
                                    <td>{{ $special->quantity_planned }}</td>
                                    <td>{{ $special->selling_price }}</td>
                                    <td>{{ $special->is_on_the_spot ? __('On-the-spot') : __('From menu') }}</td>
                                    <td><span class="badge bg-{{ $special->status === 'active' ? 'success' : ($special->status === 'cancelled' ? 'danger' : 'secondary') }}">{{ ucfirst($special->status) }}</span></td>
                                    <td>
                                        @if ($special->isActive())
                                            <form method="POST" action="{{ route('kitchen-daily-specials.cancel', $special->id) }}" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('{{ __('Cancel this special? It will stop being sellable on the POS.') }}')">{{ __('Cancel') }}</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted">{{ __('No specials for this date.') }}</td>
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
