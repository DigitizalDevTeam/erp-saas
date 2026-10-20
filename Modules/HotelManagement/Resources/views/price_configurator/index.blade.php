@extends('layouts.admin')
@section('page-title'){{ __('Price Configurator') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Price Configurator') }}</li>
@endsection
@section('content')
@php
    $tabs = [
        'occupancy' => __('Occupancy rules'),
        'room-rates' => __('Room rates'),
        'rate-plans' => __('Price per Period'),
        'addons' => __('Extra items'),
        'taxes' => __('Taxes & charges'),
        'calculator' => __('Test calculator'),
    ];
@endphp
<ul class="nav nav-tabs mb-3">
    @foreach($tabs as $key => $label)
        <li class="nav-item">
            <a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('hotel.price-configurator.index', ['tab' => $key]) }}">{{ $label }}</a>
        </li>
    @endforeach
</ul>

@if($tab === 'occupancy')
    @include('hotelmanagement::price_configurator.partials.occupancy')
@elseif($tab === 'calculator')
    @include('hotelmanagement::price_configurator.partials.calculator')
@elseif($tab === 'room-rates')
    @include('hotelmanagement::price_configurator.partials.room_rates')
@elseif($tab === 'rate-plans')
    @include('hotelmanagement::price_configurator.partials.rate_plans')
@elseif($tab === 'addons')
    @include('hotelmanagement::price_configurator.partials.addons')
@elseif($tab === 'taxes')
    @include('hotelmanagement::price_configurator.partials.taxes')
@endif
@endsection
