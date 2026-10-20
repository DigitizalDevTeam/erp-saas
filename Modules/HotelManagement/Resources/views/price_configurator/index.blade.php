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
    @include('hotelmanagement::price_configurator.partials.moved', [
        'movedTitle' => __('Room rates'),
        'movedText' => __('Room-type rates and per-room overrides are edited on the Room Types and Rooms pages for now — they move into this tab in the next phase.'),
        'movedRoute' => route('hotel.room-types.index'),
        'movedLabel' => __('Open Room Types'),
    ])
@elseif($tab === 'rate-plans')
    @include('hotelmanagement::price_configurator.partials.moved', [
        'movedTitle' => __('Price per Period'),
        'movedText' => __('Rate plans are edited on the Price per Period page for now — they move into this tab in the next phase.'),
        'movedRoute' => route('hotel.rate-plans.index'),
        'movedLabel' => __('Open Price per Period'),
    ])
@elseif($tab === 'addons')
    @include('hotelmanagement::price_configurator.partials.moved', [
        'movedTitle' => __('Extra items'),
        'movedText' => __('Addon catalog prices are edited on the Addons & Extras page for now — they move into this tab in the next phase. Quantity-range prices for an item are already managed as addon rules in the Occupancy rules tab.'),
        'movedRoute' => route('hotel.addons.index'),
        'movedLabel' => __('Open Addons & Extras'),
    ])
@elseif($tab === 'taxes')
    @include('hotelmanagement::price_configurator.partials.moved', [
        'movedTitle' => __('Taxes & charges'),
        'movedText' => __('The VAT rate is edited on the Accounting Settings page, booking prepayment on the Booking Site page, and discount caps are fixed in code for now — they move into this tab in the next phase.'),
        'movedRoute' => route('hotel.accounting-settings.index'),
        'movedLabel' => __('Open Accounting Settings'),
    ])
@endif
@endsection
