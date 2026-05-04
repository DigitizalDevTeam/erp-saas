{{ Form::open(['route' => ['proformas.create', 0], 'method' => 'post', 'class'=>'needs-validation', 'novalidate']) }}
<div class="modal-body">
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('customer_id', __('Customer'),['class'=>'form-label']) }}<x-required></x-required>
                {{ Form::select('customer_id', $customers,'', array('class' => 'form-control select','required'=>'required')) }}
                <div class="text-xs mt-1">
                    {{ __('Create customer here.') }} <a href="{{ route('customer.index') }}"><b>{{ __('Create customer') }}</b></a>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('warehouse_id', __('Warehouse'),['class'=>'form-label']) }}<x-required></x-required>
                {{ Form::select('warehouse_id', $warehouse,null, array('class' => 'form-control select warehouse_id','required'=>'required')) }}
                <div class="text-xs mt-1">
                    {{ __('Create warehouse here.') }} <a href="{{ route('warehouse.index') }}"><b>{{ __('Create warehouse') }}</b></a>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('proforma_date', __('Proforma date'),['class'=>'form-label']) }}<x-required></x-required>
                {{Form::date('proforma_date',null,array('class'=>'form-control','required'=>'required'))}}
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('proforma_number', __('Proforma number'),['class'=>'form-label']) }}
                {{ Form::text('proforma_number', $proforma_number, ['class' => 'form-control', 'readonly' => 'readonly']) }}
            </div>
        </div>
    </div>
</div>

<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn  btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Create')}}" class="btn  btn-primary">
</div>

{{Form::close()}}
