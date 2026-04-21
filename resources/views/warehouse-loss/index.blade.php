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
                        <div class="form-group col-md-4">
                            {{ Form::label('issuer_name', __('Issued By'), ['class' => 'form-label']) }}
                            {{ Form::text('issuer_name', \Auth::user()->name ?? '', ['class' => 'form-control', 'required' => true, 'placeholder' => __('Person who issued this write-off')]) }}
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('authorized_by', __('Authorized By'), ['class' => 'form-label']) }}
                            {{ Form::text('authorized_by', null, ['class' => 'form-control', 'required' => true, 'placeholder' => __('Authorizing person name')]) }}
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('witness_name', __('Witness Name'), ['class' => 'form-label']) }}
                            {{ Form::text('witness_name', null, ['class' => 'form-control', 'required' => true, 'placeholder' => __('Witness person name')]) }}
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('delivery_note_required', __('Delivery Note Required'), ['class' => 'form-label d-block']) }}
                            <input type="hidden" name="delivery_note_required" value="0">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="delivery_note_required" id="delivery_note_required" value="1">
                                <label class="form-check-label" for="delivery_note_required">{{ __('Enable if deteriorated goods will be moved somewhere else') }}</label>
                            </div>
                        </div>
                        <div class="form-group col-md-8">
                            {{ Form::label('delivery_destination', __('Delivery Destination / Note'), ['class' => 'form-label']) }}
                            {{ Form::text('delivery_destination', null, ['class' => 'form-control', 'placeholder' => __('Where goods are being sent / delivery note reference')]) }}
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
                                    <th>{{ __('Write-off #') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Warehouse') }}</th>
                                    <th>{{ __('Product') }}</th>
                                    <th>{{ __('Qty') }}</th>
                                    <th>{{ __('Reason') }}</th>
                                    <th>{{ __('Issued / Authorized / Witness') }}</th>
                                    <th>{{ __('Delivery Option') }}</th>
                                    <th>{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($records as $r)
                                    <tr>
                                        <td>{{ $r->write_off_no ?? ('WO-' . $r->id) }}</td>
                                        <td>{{ \Auth::user()->dateFormat($r->date) }}</td>
                                        <td>{{ $r->warehouse->name ?? '-' }}</td>
                                        <td>{{ $r->product->name ?? '-' }}</td>
                                        <td>{{ $r->quantity }}</td>
                                        <td>{{ $r->reason ?? '-' }}</td>
                                        <td>
                                            {{ $r->issuer_name ?? '-' }} /
                                            {{ $r->authorized_by ?? '-' }} /
                                            {{ $r->witness_name ?? '-' }}
                                        </td>
                                        <td>
                                            @if((int) ($r->delivery_note_required ?? 0) === 1)
                                                <span class="badge bg-warning">{{ __('Required') }}</span>
                                                <div class="small text-muted mt-1">{{ $r->delivery_destination ?? '-' }}</div>
                                            @else
                                                <span class="badge bg-secondary">{{ __('No') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            <a href="{{ route('warehouse-loss.print', $r->id) }}" target="_blank" class="btn btn-sm btn-primary">
                                                <i class="ti ti-printer"></i>
                                            </a>
                                        </td>
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

