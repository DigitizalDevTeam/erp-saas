@extends('layouts.admin')
@section('page-title')
    {{ __('Issue Stock') }} — {{ $requisition->requisition_number }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.requisitions.index') }}">{{ __('Requisitions') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('kitchen.requisitions.show', $requisition->id) }}">{{ $requisition->requisition_number }}</a></li>
    <li class="breadcrumb-item">{{ __('Issue') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p>
                        {{ __('Moving stock from') }} <strong>{{ $requisition->sourceWarehouse ? $requisition->sourceWarehouse->name : '-' }}</strong>
                        {{ __('to') }} <strong>{{ $requisition->kitchenWarehouse ? $requisition->kitchenWarehouse->name : '-' }}</strong>.
                        {{ __('Issue up to the remaining shortage per line. A line may be issued in parts; the requisition is complete when every line is fully issued.') }}
                    </p>

                    {{ Form::open(['route' => ['kitchen.requisitions.issue.store', $requisition->id], 'method' => 'POST', 'class' => 'needs-validation', 'novalidate']) }}

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Ingredient') }}</th>
                                <th>{{ __('Shortage') }}</th>
                                <th>{{ __('Already Issued') }}</th>
                                <th>{{ __('Remaining') }}</th>
                                <th>{{ __('Issue Now') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($requisition->lines as $line)
                                <tr>
                                    <td>{{ $line->ingredient ? $line->ingredient->name : ('#'.$line->ingredient_product_id) }}</td>
                                    <td>{{ number_format((float) $line->shortage_quantity, 2) }} {{ $line->unit ? $line->unit->name : '' }}</td>
                                    <td>{{ number_format((float) $line->issued_quantity, 2) }} {{ $line->unit ? $line->unit->name : '' }}</td>
                                    <td>{{ number_format($line->remainingQuantity(), 2) }} {{ $line->unit ? $line->unit->name : '' }}</td>
                                    <td>
                                        <input type="number" name="quantities[{{ $line->id }}]" value="{{ old('quantities.'.$line->id, $line->remainingQuantity()) }}" class="form-control" step="0.0001" min="0" max="{{ $line->remainingQuantity() }}">
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 text-end">
                        <a href="{{ route('kitchen.requisitions.show', $requisition->id) }}" class="btn btn-light">{{ __('Cancel') }}</a>
                        <button type="submit" class="btn btn-success">{{ __('Confirm Issue') }}</button>
                    </div>

                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
