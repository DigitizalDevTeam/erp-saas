<div class="card shadow-sm">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <strong>{{ __('Extra items (addons catalog)') }}</strong>
        <a class="btn btn-primary btn-sm" href="{{ route('hotel.addons.create') }}">{{ __('Add item') }}</a>
    </div>
    <div class="card-body p-0">
        @if($addons->isEmpty())
            <p class="text-muted small p-3 mb-0">{{ __('No extra items yet.') }}</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>{{ __('Item') }}</th>
                    <th>{{ __('Type') }}</th>
                    <th>{{ __('Price') }}</th>
                    <th>{{ __('Billing') }}</th>
                    <th>{{ __('Taxable') }}</th>
                    <th>{{ __('Active') }}</th>
                    <th class="text-end">{{ __('Actions') }}</th>
                </tr></thead>
                <tbody>
                @foreach($addons as $addon)
                    <tr>
                        <td>{{ $addon->name }}</td>
                        <td>{{ \Modules\HotelManagement\Entities\HotelAddon::typeOptions()[$addon->type] ?? $addon->type }}</td>
                        <td>{{ number_format((float) $addon->price, 2) }}</td>
                        <td>{{ \Modules\HotelManagement\Entities\HotelAddon::billingModeOptions()[$addon->billing_mode] ?? $addon->billing_mode }}</td>
                        <td>{{ $addon->taxable ? __('Yes') : __('No') }}</td>
                        <td>{{ $addon->is_active ? __('Yes') : __('No') }}</td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-outline-primary btn-sm" href="{{ route('hotel.addons.edit', $addon) }}">{{ __('Edit') }}</a>
                            {!! Form::open(['route' => ['hotel.addons.destroy', $addon], 'method' => 'DELETE', 'class' => 'd-inline', 'onsubmit' => 'return confirm("'.__('Delete this item?').'")']) !!}
                                <button type="submit" class="btn btn-outline-danger btn-sm">{{ __('Delete') }}</button>
                            {!! Form::close() !!}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
<p class="text-muted small mt-2">{{ __('Quantity-range prices for an item (e.g. beds 3–4 at a lower rate) are managed as addon rules in the Occupancy rules tab.') }}</p>
