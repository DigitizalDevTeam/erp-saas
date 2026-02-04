{{Form::model($branch,array('route' => array('branch.update', $branch->id), 'method' => 'PUT', 'class'=>'needs-validation', 'novalidate')) }}
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
            </div>
        </div>
        <div class="col-12 mt-3">
            <div class="form-group">
                {{Form::label('timezone',__('Timezone'),['class'=>'form-label'])}}
                {{Form::text('timezone',null,array('class'=>'form-control','placeholder'=>'UTC'))}}
            </div>
        </div>
        <div class="col-12 mt-3">
            <div class="form-group">
                {{Form::label('pos_type',__('POS Type'),['class'=>'form-label'])}}
                {{Form::select('pos_type', ['retail' => __('Retail'), 'restaurant' => __('Restaurant')], $branch->pos_type ?? 'retail', ['class'=>'form-control select'])}}
            </div>
        </div>
        <div class="col-12 mt-3">
            <div class="form-group">
                <div class="form-check form-switch">
                    {{Form::checkbox('is_active', 1, $branch->is_active ?? true, array('class'=>'form-check-input','id'=>'is_active_edit'))}}
                    {{Form::label('is_active_edit',__('Active'),['class'=>'form-check-label'])}}
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn  btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Update')}}" class="btn  btn-primary">
</div>

{{Form::close()}}
