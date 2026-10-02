{{ Form::model($warehouse, array('route' => array('warehouse.update', $warehouse->id), 'method' => 'PUT', 'class'=>'needs-validation', 'novalidate')) }}

<div class="modal-body">
    {{-- start for ai module--}}
    @php
        $plan= \App\Models\Utility::getChatGPTSettings();
    @endphp
    @if($plan->chatgpt == 1)
    <div class="text-end">
        <a href="#" data-size="md" class="btn  btn-primary btn-icon btn-sm" data-ajax-popup-over="true" data-url="{{ route('generate',['warehouse']) }}"
           data-bs-placement="top" data-title="{{ __('Generate content with AI') }}">
            <i class="fas fa-robot"></i> <span>{{__('Generate with AI')}}</span>
        </a>
    </div>
    @endif
    {{-- end for ai module--}}
    <div class="row">
        <div class="form-group col-md-12">
            {{ Form::label('branch_id', __('Branch'),['class'=>'form-label']) }}
            {{ Form::select('branch_id', $branches ?? collect(), $warehouse->branch_id, ['class' => 'form-control select', 'placeholder' => __('Select Branch')]) }}
        </div>
        <div class="form-group col-md-12">
            {{ Form::label('name', __('Name'),['class'=>'form-label']) }}<x-required></x-required>
            {{ Form::text('name', null, array('class' => 'form-control','required'=>'required')) }}
            @error('name')
            <small class="invalid-name" role="alert">
                <strong class="text-danger">{{ $message }}</strong>
            </small>
            @enderror
        </div>
        <div class="form-group col-md-12">
            {{ Form::label('assigned_users', __('Allocate Users'), ['class' => 'form-label']) }}
            <select name="assigned_users[]" id="assigned_users" class="form-control" multiple>
                @foreach (($users ?? collect()) as $userId => $userName)
                    <option value="{{ $userId }}" @selected(in_array((int) $userId, array_map('intval', $assignedUsers ?? []), true))>{{ $userName }}</option>
                @endforeach
            </select>
            <small class="text-muted">{{ __('Users allocated here will be limited to this warehouse in inventory actions. A user can be allocated to more than one warehouse.') }}</small>
        </div>
        <div class="form-group col-md-12">
            {{Form::label('address',__('Address'),array('class'=>'form-label')) }}<x-required></x-required>
            {{Form::textarea('address',null,array('class'=>'form-control','rows'=>3,'required'=>'required'))}}
        </div>
        <div class="form-group col-md-6">
            {{Form::label('city',__('City'),array('class'=>'form-label')) }}<x-required></x-required>
            {{Form::text('city',null,array('class'=>'form-control','required'=>'required'))}}
        </div>
        <div class="form-group col-md-6">
            {{Form::label('city_zip',__('Zip Code'),array('class'=>'form-label')) }}<x-required></x-required>
            {{Form::text('city_zip',null,array('class'=>'form-control','required'=>'required'))}}
        </div>

    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn  btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Edit')}}" class="btn  btn-primary">
</div>
{{ Form::close() }}
