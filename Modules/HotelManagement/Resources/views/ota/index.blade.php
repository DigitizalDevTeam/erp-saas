@extends('layouts.admin')
@section('page-title'){{ __('OTA & channel settings') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('OTA') }}</li>
@endsection
@section('content')
<div class="card shadow-sm mb-3"><div class="card-body">
    <h6>{{ __('Vendor integrations (stubs)') }}</h6>
    <p class="text-muted small mb-0">{{ __('IPTV and door-lock connectors are vendor-specific. Enable when hardware is chosen — placeholders only.') }}</p>
    <ul class="mt-2 mb-0">
        <li><strong>IPTV:</strong> {{ __('Not configured') }}</li>
        <li><strong>{{ __('Door lock / keys') }}:</strong> {{ __('Not configured') }}</li>
    </ul>
</div></div>

{!! Form::open(['route' => 'hotel.ota-settings.update', 'method' => 'POST']) !!}
@foreach($channels as $ch)
    @php $s = $settings[$ch]; @endphp
    <div class="card shadow-sm mb-2"><div class="card-header py-2"><strong>{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $ch))) }}</strong></div><div class="card-body">
        <div class="form-check mb-2">
            <input type="hidden" name="channels[{{ $ch }}][enabled]" value="0">
            <input class="form-check-input" type="checkbox" name="channels[{{ $ch }}][enabled]" value="1" id="en_{{ $ch }}" {{ $s->enabled ? 'checked' : '' }}>
            <label class="form-check-label" for="en_{{ $ch }}">{{ __('Enabled') }}</label>
        </div>
        <div class="row g-2">
            <div class="col-md-6">{!! Form::label("api_key_$ch", __('API key')) !!}{!! Form::text("channels[$ch][api_key]", $s->api_key, ['class' => 'form-control', 'id' => "api_key_$ch"]) !!}</div>
            <div class="col-md-6">{!! Form::label("api_secret_$ch", __('API secret')) !!}{!! Form::text("channels[$ch][api_secret]", $s->api_secret, ['class' => 'form-control', 'id' => "api_secret_$ch"]) !!}</div>
            <div class="col-md-6">{!! Form::label("property_id_$ch", __('Property / hotel id')) !!}{!! Form::text("channels[$ch][property_id]", $s->property_id, ['class' => 'form-control', 'id' => "property_id_$ch"]) !!}</div>
            <div class="col-md-6">{!! Form::label("webhook_url_$ch", __('Webhook URL')) !!}{!! Form::text("channels[$ch][webhook_url]", $s->webhook_url, ['class' => 'form-control', 'id' => "webhook_url_$ch"]) !!}</div>
        </div>
    </div></div>
@endforeach
<button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
{!! Form::close() !!}
@endsection
