@extends('layouts.admin')

@section('page-title')
    {{ __('Warehouse Loss') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Warehouse Loss') }}</li>
@endsection

@section('content')
    <div class="row mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    {{ Form::open(['route' => 'warehouse-loss.store', 'method' => 'post']) }}
                    <div class="row">
                        <div class="form-group col-md-3">
                            {{ Form::label('warehouse_id', __('Warehouse'), ['class' => 'form-label']) }}
                            {{ Form::select('warehouse_id', $warehouses, null, ['class' => 'form-control select', 'required' => true, 'placeholder' => __('Select Warehouse')]) }}
                        </div>
                        <div class="form-group col-md-3">
                            {{ Form::label('product_id', __('Product'), ['class' => 'form-label']) }}
                            {{ Form::select('product_id', $products, null, ['class' => 'form-control select', 'required' => true, 'placeholder' => __('Select Product')]) }}
                        </div>
                        <div class="form-group col-md-2">
                            {{ Form::label('quantity', __('Lost Qty'), ['class' => 'form-label']) }}
                            {{ Form::number('quantity', null, ['class' => 'form-control', 'step' => '0.0001', 'min' => '0.0001', 'required' => true]) }}
                        </div>
                        <div class="form-group col-md-2">
                            {{ Form::label('date', __('Date'), ['class' => 'form-label']) }}
                            {{ Form::date('date', date('Y-m-d'), ['class' => 'form-control']) }}
                        </div>
                        <div class="form-group col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Add Loss') }}</button>
                        </div>
                        <div class="form-group col-md-12">
                            {{ Form::label('reason', __('Reason'), ['class' => 'form-label']) }}
                            {{ Form::text('reason', null, ['class' => 'form-control', 'placeholder' => __('Damaged, expired, missing, etc.')]) }}
                        </div>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                                <tr>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Warehouse') }}</th>
                                    <th>{{ __('Product') }}</th>
                                    <th>{{ __('Qty') }}</th>
                                    <th>{{ __('Reason') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($records as $r)
                                    <tr>
                                        <td>{{ \Auth::user()->dateFormat($r->date) }}</td>
                                        <td>{{ $r->warehouse->name ?? '-' }}</td>
                                        <td>{{ $r->product->name ?? '-' }}</td>
                                        <td>{{ $r->quantity }}</td>
                                        <td>{{ $r->reason ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

