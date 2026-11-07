@extends('layouts.admin')
@section('page-title')
    {{ __('Cost History') }} — {{ $dish->name }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Kitchen Production') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.cost-control.index') }}">{{ __('Cost Control') }}</a></li>
    <li class="breadcrumb-item">{{ $dish->name }}</li>
@endsection

@section('content')
    @php
        $bandBadge = [
            'excellent' => 'bg-success',
            'good' => 'bg-primary',
            'good-' => 'bg-info',
            'bad' => 'bg-warning',
            'very_bad' => 'bg-danger',
        ];
    @endphp

    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <h6>{{ $dish->name }} — {{ $row['recipe']->code }} v{{ $row['recipe']->version }}</h6>
                    <p class="text-muted">{{ __('Selling price excluding VAT: :price', ['price' => \Auth::user()->priceFormat((float) ($row['expected']['selling_price_ex_vat'] ?? 0))]) }}</p>

                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="border rounded p-2">
                                <small class="text-muted">{{ __('Expected cost / portion') }}</small>
                                <div><strong>{{ $row['expected']['cost_per_portion'] !== null ? \Auth::user()->priceFormat((float) $row['expected']['cost_per_portion']) : '-' }}</strong></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-2">
                                <small class="text-muted">{{ __('Expected food cost %') }}</small>
                                <div>
                                    @if ($row['expected']['food_cost_percent'] !== null)
                                        <strong>{{ number_format((float) $row['expected']['food_cost_percent'], 2) }}%</strong>
                                        <span class="badge {{ $bandBadge[$row['expected']['band']] ?? 'bg-secondary' }}">{{ $bandLabel($row['expected']['band']) }}</span>
                                    @else
                                        -
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-2">
                                <small class="text-muted">{{ __('Actual cost / portion (latest order)') }}</small>
                                <div><strong>{{ $row['actual_cost_per_portion'] !== null ? \Auth::user()->priceFormat((float) $row['actual_cost_per_portion']) : '-' }}</strong></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="border rounded p-2">
                                <small class="text-muted">{{ __('Actual food cost % (latest order)') }}</small>
                                <div>
                                    @if ($row['actual_food_cost_percent'] !== null)
                                        <strong>{{ number_format((float) $row['actual_food_cost_percent'], 2) }}%</strong>
                                        <span class="badge {{ $bandBadge[$row['actual_band']] ?? 'bg-secondary' }}">{{ $bandLabel($row['actual_band']) }}</span>
                                    @else
                                        -
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    <h6>{{ __('Cost history') }}</h6>
                    <p class="text-muted">{{ __('Snapshots are written when a recipe version is approved and when an order completes, so a later ingredient price change never rewrites the past. When a dish moves bands, the next snapshot shows why.') }}</p>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Date') }}</th>
                                <th>{{ __('What happened') }}</th>
                                <th>{{ __('Cost / portion') }}</th>
                                <th>{{ __('Food cost %') }}</th>
                                <th>{{ __('Band') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($history as $entry)
                                <tr>
                                    <td>{{ $entry['date'] }}</td>
                                    <td>{{ $entry['label'] }}</td>
                                    <td>{{ $entry['cost_per_portion'] !== null ? \Auth::user()->priceFormat((float) $entry['cost_per_portion']) : '-' }}</td>
                                    <td>{{ $entry['food_cost_percent'] !== null ? number_format((float) $entry['food_cost_percent'], 2).'%' : '-' }}</td>
                                    <td>
                                        @if ($entry['band'])
                                            <span class="badge {{ $bandBadge[$entry['band']] ?? 'bg-secondary' }}">{{ $bandLabel($entry['band']) }}</span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center">{{ __('No cost snapshots yet — approve a recipe version or complete an order to start the history.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h6>{{ __('Completed orders for this dish') }}</h6>
                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                            <tr>
                                <th>{{ __('Order') }}</th>
                                <th>{{ __('Completed') }}</th>
                                <th>{{ __('Planned cost') }}</th>
                                <th>{{ __('Actual cost') }}</th>
                                <th>{{ __('Variance') }}</th>
                                <th>{{ __('Planned band') }}</th>
                                <th>{{ __('Actual band') }}</th>
                                <th>{{ __('Flag') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($orders as $order)
                                @php $variance = $variances[(int) $order->id]; @endphp
                                <tr>
                                    <td>{{ $order->order_number ?? ('#'.$order->id) }}</td>
                                    <td>{{ $order->completed_at ? $order->completed_at->format('d M Y') : '-' }}</td>
                                    <td>{{ \Auth::user()->priceFormat((float) $variance['planned_cost']) }}</td>
                                    <td>{{ $variance['actual_cost'] !== null ? \Auth::user()->priceFormat((float) $variance['actual_cost']) : '-' }}</td>
                                    <td class="{{ ($variance['variance'] ?? 0) > 0 ? 'text-danger' : 'text-success' }}">
                                        {{ $variance['variance'] !== null ? \Auth::user()->priceFormat((float) $variance['variance']) : '-' }}
                                    </td>
                                    <td>
                                        @if ($variance['planned_band'])
                                            <span class="badge {{ $bandBadge[$variance['planned_band']] ?? 'bg-secondary' }}">{{ $bandLabel($variance['planned_band']) }}</span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        @if ($variance['actual_band'])
                                            <span class="badge {{ $bandBadge[$variance['actual_band']] ?? 'bg-secondary' }}">{{ $bandLabel($variance['actual_band']) }}</span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        @if ($variance['worse_than_planned'])
                                            <span class="badge bg-danger">{{ __('Worse than planned') }}</span>
                                        @elseif ($variance['alert'])
                                            <span class="badge bg-warning">{{ __('Over target') }}</span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="text-center">{{ __('No completed orders for this dish yet.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{ $orders->links() }}
                </div>
            </div>

            @if ($alerts->count() > 0)
                <div class="card">
                    <div class="card-body">
                        <h6>{{ __('Alerts for this dish') }}</h6>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                <tr>
                                    <th>{{ __('Type') }}</th>
                                    <th>{{ __('Food cost %') }}</th>
                                    <th>{{ __('Band') }}</th>
                                    <th>{{ __('Status') }}</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($alerts as $alert)
                                    <tr>
                                        <td>{{ ucfirst(str_replace('_', ' ', $alert->alert_type)) }}</td>
                                        <td>{{ $alert->food_cost_percent !== null ? number_format((float) $alert->food_cost_percent, 2).'%' : '-' }}</td>
                                        <td>
                                            @if ($alert->band)
                                                <span class="badge {{ $bandBadge[$alert->band] ?? 'bg-secondary' }}">{{ $bandLabel($alert->band) }}</span>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td>{{ ucfirst($alert->status) }}</td>
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
