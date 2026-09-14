{{Form::open(array('url'=>'branch','method'=>'post', 'class'=>'needs-validation', 'novalidate'))}}
<div class="modal-body">

    <div class="row">
        <div class="col-12">
            <div class="form-group">
                {{Form::label('name',__('Name'),['class'=>'form-label'])}}<x-required></x-required>
                {{Form::text('name',null,array('class'=>'form-control','placeholder'=>__('Enter Branch Name'),'required'=> 'required'))}}
                @error('name')
                <span class="invalid-name" role="alert">
                    <strong class="text-danger">{{ $message }}</strong>
                </span>
                @enderror
            </div>
        </div>
        <div class="col-12 mt-3">
            <div class="form-group">
                {{Form::label('address',__('Address'),['class'=>'form-label'])}}
                {{Form::textarea('address',null,array('class'=>'form-control','placeholder'=>__('Enter Branch Address'),'rows'=>2))}}
                @error('address')
                <span class="invalid-name" role="alert">
                    <strong class="text-danger">{{ $message }}</strong>
                </span>
                @enderror
            </div>
        </div>
        <div class="col-12 mt-3">
            <div class="form-group">
                {{Form::label('timezone',__('Timezone'),['class'=>'form-label'])}}
                {{Form::text('timezone','UTC',array('class'=>'form-control','placeholder'=>'UTC'))}}
            </div>
        </div>
        <div class="col-12 mt-3">
            <div class="form-group">
                {{Form::label('pos_type',__('POS Type'),['class'=>'form-label'])}}
                {{Form::select('pos_type', ['retail' => __('Retail'), 'restaurant' => __('Restaurant')], 'retail', ['class'=>'form-control select'])}}
            </div>
        </div>
        <div class="col-12 mt-3">
            <div class="form-group">
                {{Form::label('default_warehouse_id',__('Default POS Warehouse'),['class'=>'form-label'])}}
                {{Form::select('default_warehouse_id', $warehouses ?? [], null, ['class'=>'form-control select'])}}
                <small class="text-muted">{{ __('Preselected warehouse when this POS/branch is opened (e.g. POS01 → BAR1).') }}</small>
            </div>
        </div>
        <div class="col-12 mt-3">
            <div class="form-group">
                <div class="form-check form-switch">
                    {{Form::checkbox('is_active', 1, true, array('class'=>'form-check-input','id'=>'is_active_create'))}}
                    {{Form::label('is_active_create',__('Active'),['class'=>'form-check-label'])}}
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Create')}}" class="btn  btn-primary">
</div>
    {{Form::close()}}

