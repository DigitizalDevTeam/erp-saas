@extends('layouts.admin')
@section('page-title')
    {{ __('Kitchen Reports') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Kitchen Production') }}</li>
    <li class="breadcrumb-item">{{ __('Reports') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('kitchen.reports.dashboard') }}" class="btn btn-sm btn-primary">
            <i class="ti ti-dashboard"></i> {{ __('Kitchen Dashboard') }}
        </a>
    </div>
@endsection

@section('content')
    <div class="row">
        @php
            $descriptions = [
                'production' => __('Production orders in the period: planned vs actual quantity and cost, yield, food cost % and band.'),
                'usage' => __('Expected vs actual ingredient consumption across the completed orders in the period.'),
                'losses' => __('Losses by category, dish and cost in the period.'),
                'food-cost' => __('Cost and margin by dish: expected cost from the approved recipe vs the actual cost of the latest completed order.'),
                'stock' => __('Kitchen warehouse stock at cost, as of today: prepared batches and raw ingredients.'),
                'waste' => __('Losses as a % of production, per dish, in the period.'),
                'requisitions' => __('Warehouse requests from plans and orders: required vs issued, and what is still to issue.'),
                'traceability' => __('From ingredient batches to preparations to dishes sold, with the per-batch reconciliation check.'),
            ];
        @endphp
        @foreach ($reports as $key => $title)
            <div class="col-md-6 col-lg-4 mb-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h6>{{ __($title) }}</h6>
                        <p class="text-muted">{{ $descriptions[$key] ?? '' }}</p>
                        <a href="{{ route('kitchen.reports.show', $key) }}" class="btn btn-sm btn-primary">{{ __('Open report') }}</a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection
