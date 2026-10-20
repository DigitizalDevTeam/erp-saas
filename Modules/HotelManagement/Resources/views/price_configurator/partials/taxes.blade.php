{!! Form::open(['route' => 'hotel.price-configurator.taxes', 'method' => 'POST']) !!}
<div class="card shadow-sm mb-3">
    <div class="card-header py-2"><strong>{{ __('Taxes & charges') }}</strong></div>
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-3">
                {!! Form::label('default_vat_rate_percent', __('Default VAT %')) !!}
                {!! Form::number('default_vat_rate_percent', old('default_vat_rate_percent', $accountingSetting->default_vat_rate_percent ?? 16), ['class' => 'form-control', 'step' => '0.01', 'min' => 0, 'max' => 100, 'id' => 'default_vat_rate_percent']) !!}
            </div>
            <div class="col-md-3">
                {!! Form::label('discount_cap_manager_percent', __('Discount cap — hotel manager %')) !!}
                {!! Form::number('discount_cap_manager_percent', old('discount_cap_manager_percent', $setting->discount_cap_manager_percent), ['class' => 'form-control', 'step' => '0.01', 'min' => 0, 'max' => 100, 'id' => 'discount_cap_manager_percent']) !!}
            </div>
            <div class="col-md-3">
                {!! Form::label('discount_cap_frontdesk_percent', __('Discount cap — front desk / reservations %')) !!}
                {!! Form::number('discount_cap_frontdesk_percent', old('discount_cap_frontdesk_percent', $setting->discount_cap_frontdesk_percent), ['class' => 'form-control', 'step' => '0.01', 'min' => 0, 'max' => 100, 'id' => 'discount_cap_frontdesk_percent']) !!}
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header py-2"><strong>{{ __('Booking site prepayment') }}</strong></div>
    <div class="card-body p-0">
        @if($bookingSites->isEmpty())
            <p class="text-muted small p-3 mb-0">{{ __('No booking sites yet.') }}</p>
        @else
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>{{ __('Booking site') }}</th>
                    <th>{{ __('Payment mode') }}</th>
                    <th style="width:200px">{{ __('Prepayment %') }}</th>
                </tr></thead>
                <tbody>
                @foreach($bookingSites as $site)
                    <tr>
                        <td>{{ $site->display_name ?: ($site->slug ?: '#'.$site->id) }}</td>
                        <td>{{ $site->payment_mode === \Modules\HotelManagement\Entities\HotelBookingSite::PAY_PREPAY ? __('Stripe online payment (prepay)') : __('Pay at hotel') }}</td>
                        <td>
                            @if($site->payment_mode === \Modules\HotelManagement\Entities\HotelBookingSite::PAY_PREPAY)
                                <input type="number" class="form-control form-control-sm" min="0" max="100" name="booking_sites[{{ $site->id }}][prepayment_percent]" value="{{ old('booking_sites.'.$site->id.'.prepayment_percent', $site->prepayment_percent ?: 100) }}">
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
<button type="submit" class="btn btn-primary">{{ __('Save taxes & charges') }}</button>
{!! Form::close() !!}
