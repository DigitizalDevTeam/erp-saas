{!! Form::open(['route' => 'hotel.price-configurator.calculate', 'method' => 'POST']) !!}
<div class="card shadow-sm mb-3">
    <div class="card-header py-2"><strong>{{ __('Test a booking price') }}</strong></div>
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-4">
                {!! Form::label('calc_room_type', __('Room type')) !!}
                <select name="hotel_room_type_id" id="calc_room_type" class="form-control">
                    @foreach($roomTypes as $roomType)
                        <option value="{{ $roomType->id }}" {{ (int) old('hotel_room_type_id') === (int) $roomType->id ? 'selected' : '' }}>{{ $roomType->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                {!! Form::label('calc_check_in', __('Check-in')) !!}
                {!! Form::date('check_in_date', old('check_in_date', now()->toDateString()), ['class' => 'form-control', 'id' => 'calc_check_in']) !!}
            </div>
            <div class="col-md-2">
                {!! Form::label('calc_check_out', __('Check-out')) !!}
                {!! Form::date('check_out_date', old('check_out_date', now()->addDays(2)->toDateString()), ['class' => 'form-control', 'id' => 'calc_check_out']) !!}
            </div>
            <div class="col-md-1">
                {!! Form::label('calc_adults', __('Adults')) !!}
                {!! Form::number('adults', old('adults', 2), ['class' => 'form-control', 'min' => 0, 'max' => 50, 'id' => 'calc_adults']) !!}
            </div>
            <div class="col-md-1">
                {!! Form::label('calc_children', __('Children')) !!}
                {!! Form::number('children', old('children', 0), ['class' => 'form-control', 'min' => 0, 'max' => 50, 'id' => 'calc_children']) !!}
            </div>
            <div class="col-md-2">
                {!! Form::label('calc_extra', __('Additional persons')) !!}
                {!! Form::number('extra_adults', old('extra_adults'), ['class' => 'form-control', 'min' => 0, 'max' => 50, 'id' => 'calc_extra', 'placeholder' => __('Auto')]) !!}
            </div>
        </div>
        @if($addons->isNotEmpty())
        <hr>
        <div class="row g-2">
            @foreach($addons as $addon)
                <div class="col-md-3">
                    <label class="form-label" for="calc_addon_{{ $addon->id }}">{{ $addon->name }} <span class="text-muted">({{ number_format((float) $addon->price, 2) }})</span></label>
                    <input type="number" class="form-control" min="0" max="999" id="calc_addon_{{ $addon->id }}"
                           name="addons[{{ $addon->id }}]" value="{{ old('addons.'.$addon->id) }}" placeholder="0">
                </div>
            @endforeach
        </div>
        @endif
        <div class="mt-3">
            <button type="submit" class="btn btn-primary">{{ __('Calculate') }}</button>
        </div>
    </div>
</div>
{!! Form::close() !!}

@if(! empty($calculation))
    @php $charges = $calculation['charges']; @endphp
    <div class="card shadow-sm">
        <div class="card-header py-2">
            <strong>{{ __('Result') }}</strong>
            <span class="text-muted">— {{ $calculation['room_type_name'] }}, {{ $calculation['check_in'] }} → {{ $calculation['check_out'] }} ({{ $calculation['units'] }} {{ $calculation['unit_label'] }})</span>
        </div>
        <div class="card-body p-0">
            <table class="table align-middle mb-0">
                <tbody>
                    <tr>
                        <td>{{ __('Room') }} — {{ number_format($calculation['room_rate'], 2) }} × {{ $calculation['units'] }} {{ $calculation['unit_label'] }}</td>
                        <td class="text-end">{{ number_format($calculation['room_subtotal'], 2) }}</td>
                    </tr>
                    @foreach($charges['occupancy_lines'] as $line)
                        <tr>
                            <td>
                                {{ $line['kind'] === 'child' ? __('Children') : __('Additional persons') }} ×{{ $line['qty'] }}
                                @if($line['no_rule'])
                                    <span class="text-muted">— {{ __('no rule — free') }}</span>
                                @else
                                    <span class="text-muted">— {{ $line['rule_name'] }}: {{ number_format($line['unit_price'], 2) }} × {{ $line['qty'] }} × {{ $line['units'] }}</span>
                                @endif
                            </td>
                            <td class="text-end">{{ number_format($line['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                    @foreach($charges['addon_lines'] as $line)
                        <tr>
                            <td>
                                {{ $line['name'] }} ×{{ $line['qty'] }}
                                <span class="text-muted">— {{ number_format($line['unit_price'], 2) }}{{ $line['rule_name'] ? ' ('.$line['rule_name'].')' : '' }}{{ $line['multiplier'] > 1 ? ' × '.$line['multiplier'] : '' }}</span>
                            </td>
                            <td class="text-end">{{ number_format($line['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td class="text-muted">{{ __('Included adults for this room type: :n — additional persons charged: :x', ['n' => $calculation['included_adults'], 'x' => $calculation['extra_adults']]) }}</td>
                        <td></td>
                    </tr>
                    <tr class="table-light fw-bold">
                        <td>{{ __('Estimated total') }}</td>
                        <td class="text-end">{{ number_format($calculation['grand_total'], 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endif
