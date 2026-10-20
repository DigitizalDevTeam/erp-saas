{!! Form::open(['route' => 'hotel.price-configurator.settings', 'method' => 'POST']) !!}
<div class="card shadow-sm mb-3">
    <div class="card-header py-2"><strong>{{ __('Included adults') }}</strong></div>
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                {!! Form::label('included_adults', __('Adults included in the room price (all room types)')) !!}
                {!! Form::number('included_adults', old('included_adults', $setting->included_adults), ['class' => 'form-control', 'min' => 0, 'max' => 50, 'id' => 'included_adults']) !!}
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
            </div>
        </div>
        @if($roomTypes->isNotEmpty())
        <hr>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>{{ __('Room type') }}</th>
                    <th>{{ __('Max occupancy') }}</th>
                    <th style="width:220px">{{ __('Included adults override') }}</th>
                </tr></thead>
                <tbody>
                @foreach($roomTypes as $roomType)
                    <tr>
                        <td>{{ $roomType->name }}</td>
                        <td>{{ $roomType->max_occupancy ?? '—' }}</td>
                        <td>
                            <input type="number" class="form-control form-control-sm" min="0" max="50"
                                   name="room_type_included_adults[{{ $roomType->id }}]"
                                   value="{{ old('room_type_included_adults.'.$roomType->id, $roomType->included_adults) }}"
                                   placeholder="{{ __('Global (:n)', ['n' => $setting->included_adults]) }}">
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
{!! Form::close() !!}

@php
    $sections = [
        ['type' => \Modules\HotelManagement\Entities\HotelPriceRule::TYPE_CHILD, 'title' => __('Kids rules'), 'qtyLabel' => __('Children')],
        ['type' => \Modules\HotelManagement\Entities\HotelPriceRule::TYPE_EXTRA_PERSON, 'title' => __('Additional person rules'), 'qtyLabel' => __('Persons')],
        ['type' => \Modules\HotelManagement\Entities\HotelPriceRule::TYPE_ADDON, 'title' => __('Extra item (addon) rules'), 'qtyLabel' => __('Quantity')],
    ];
@endphp
@foreach($sections as $section)
    @php $sectionRules = $rules->get($section['type'], collect()); @endphp
    <div class="card shadow-sm mb-3">
        <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <strong>{{ $section['title'] }}</strong>
            <a class="btn btn-primary btn-sm" href="{{ route('hotel.price-rules.create', ['type' => $section['type']]) }}">{{ __('Add rule') }}</a>
        </div>
        <div class="card-body p-0">
            @if($sectionRules->isEmpty())
                <p class="text-muted small p-3 mb-0">{{ __('No rules yet — quantities of this kind are free.') }}</p>
            @else
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr>
                        <th>{{ __('Rule') }}</th>
                        <th>{{ __('Room type') }}</th>
                        @if($section['type'] === 'addon')<th>{{ __('Extra item') }}</th>@endif
                        @if($section['type'] === 'child')<th>{{ __('Adults') }}</th>@endif
                        <th>{{ $section['qtyLabel'] }}</th>
                        <th>{{ __('Price') }}</th>
                        <th>{{ __('Valid') }}</th>
                        <th>{{ __('Priority') }}</th>
                        <th>{{ __('Active') }}</th>
                        <th class="text-end">{{ __('Actions') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($sectionRules as $rule)
                        <tr>
                            <td>{{ $rule->name }}</td>
                            <td>{{ $rule->roomType?->name ?? __('All') }}</td>
                            @if($section['type'] === 'addon')<td>{{ $rule->addon?->name ?? '—' }}</td>@endif
                            @if($section['type'] === 'child')
                                <td>{{ ($rule->adults_min ?? '0').' – '.($rule->adults_max ?? '∞') }}</td>
                            @endif
                            <td>{{ $rule->qty_from.' – '.($rule->qty_to ?? __('and above')) }}</td>
                            <td>{{ number_format((float) $rule->price, 2) }} / {{ $rule->price_basis === 'per_stay' ? __('stay') : __('night') }}</td>
                            <td>{{ $rule->valid_from?->format('Y-m-d') ?? '…' }} → {{ $rule->valid_to?->format('Y-m-d') ?? '…' }}</td>
                            <td>{{ $rule->priority }}</td>
                            <td>{{ $rule->is_active ? __('Yes') : __('No') }}</td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-outline-primary btn-sm" href="{{ route('hotel.price-rules.edit', $rule) }}">{{ __('Edit') }}</a>
                                {!! Form::open(['route' => ['hotel.price-rules.destroy', $rule], 'method' => 'DELETE', 'class' => 'd-inline', 'onsubmit' => 'return confirm("'.__('Delete this rule?').'")']) !!}
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
@endforeach
