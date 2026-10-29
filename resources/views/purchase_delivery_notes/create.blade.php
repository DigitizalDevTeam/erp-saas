@extends('layouts.admin')
@section('page-title')
    {{ __('New delivery note') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('New delivery note') }}</li>
@endsection
@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">
                        {{ __('Stock is added only for the quantities entered here. An invoice is for money, not stock — if the supplier invoiced 100 but delivered 40, enter 40.') }}
                    </p>
                    <form method="POST" action="{{ $sourceType === 'purchase' ? route('purchase.delivery-notes.store', \Crypt::encrypt($source->id)) : route('bill.delivery-notes.store', \Crypt::encrypt($source->id)) }}">
                        @csrf
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">{{ __('Delivery date') }}</label>
                                <input type="date" name="delivery_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                            </div>
                            @if ($sourceType === 'bill')
                                <div class="col-md-4">
                                    <label class="form-label">{{ __('Warehouse') }}</label>
                                    <select name="warehouse_id" class="form-control" required>
                                        <option value="">{{ __('Select Warehouse') }}</option>
                                        @foreach ($warehouses as $wh)
                                            <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @else
                                <div class="col-md-4">
                                    <label class="form-label">{{ __('Warehouse') }}</label>
                                    <input type="text" class="form-control" value="{{ $source->warehouse->name ?? '' }}" disabled>
                                </div>
                            @endif
                        </div>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>{{ __('Product') }}</th>
                                        <th>{{ __('Ordered') }}</th>
                                        <th>{{ __('Already delivered') }}</th>
                                        <th>{{ __('Remaining') }}</th>
                                        <th>{{ __('Deliver now') }}</th>
                                        <th>{{ __('Batch / Serial') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($lines as $idx => $row)
                                        @php $product = $row['product']; @endphp
                                        <tr>
                                            <td>{{ $product->name ?? '' }}</td>
                                            <td>{{ $row['ordered'] }}</td>
                                            <td>{{ $row['delivered'] }}</td>
                                            <td>{{ $row['remaining'] }}</td>
                                            <td>
                                                <input type="hidden" name="items[{{ $idx }}][source_type]" value="{{ $row['source_type'] }}">
                                                <input type="hidden" name="items[{{ $idx }}][source_line_id]" value="{{ $row['line']->id }}">
                                                <input type="number" name="items[{{ $idx }}][delivered_qty]" class="form-control" step="0.0001" min="0" max="{{ $row['remaining'] }}" value="0">
                                            </td>
                                            <td>
                                                @if ($product && ($product->batch_tracking ?? false))
                                                    <input type="text" name="items[{{ $idx }}][batch_no]" class="form-control mb-1" placeholder="{{ __('Batch no.') }}" value="{{ $row['line']->batch_no ?? '' }}">
                                                    <input type="date" name="items[{{ $idx }}][expiry_date]" class="form-control mb-1" value="{{ $row['line']->expiry_date ? \Carbon\Carbon::parse($row['line']->expiry_date)->format('Y-m-d') : '' }}">
                                                    <input type="date" name="items[{{ $idx }}][mfg_date]" class="form-control" value="{{ $row['line']->mfg_date ? \Carbon\Carbon::parse($row['line']->mfg_date)->format('Y-m-d') : '' }}">
                                                @elseif ($product && ($product->identifier_tracking ?? false))
                                                    <textarea name="items[{{ $idx }}][identifiers]" class="form-control" rows="2" placeholder="{{ __('One Serial / MAC per line, matching the delivered quantity') }}"></textarea>
                                                @else
                                                    -
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <button type="submit" class="btn btn-primary">{{ __('Create delivery note') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
