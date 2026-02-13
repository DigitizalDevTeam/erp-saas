{{ Form::model($chartOfAccount, array('route' => array('chart-of-account.update', $chartOfAccount->id), 'method' => 'PUT', 'class'=>'needs-validation', 'novalidate')) }}
<div class="modal-body">
    {{-- start for ai module--}}
    @php
        $plan= \App\Models\Utility::getChatGPTSettings();
        $settings = \App\Models\Utility::settings();
        $act_lan = isset($settings['languages']) ?  json_decode($settings['languages']) : [];
    @endphp
    @if($plan->chatgpt == 1)
    <div class="text-end">
        <a href="#" data-size="md" class="btn  btn-primary btn-icon btn-sm" data-ajax-popup-over="true" data-url="{{ route('generate',['chart of account']) }}"
           data-bs-placement="top" data-title="{{ __('Generate content with AI') }}">
            <i class="fas fa-robot"></i> <span>{{__('Generate with AI')}}</span>
        </a>
    </div>
    @endif
    {{-- end for ai module--}}
    <div class="row">
        @if(in_array('en',$act_lan))
        <div class="form-group col-md-6">
            {{ Form::label('name', __('Name').' (english)',['class'=>'form-label']) }}<x-required></x-required>
            {{ Form::text('name', null, array('class' => 'form-control','required'=>'required' , 'placeholder'=>__('Enter Name'))) }}
        </div>
        @endif
        @if(in_array('fr',$act_lan))
        <div class="form-group col-md-6">
            {{ Form::label('name', __('Name').' (french)',['class'=>'form-label']) }}
            {{ Form::text('name_fr', null, array('class' => 'form-control', 'placeholder'=>__('Enter Name'))) }}
        </div>
        @endif
        @if(in_array('zh',$act_lan))
        <div class="form-group col-md-6">
            {{ Form::label('name', __('Name').' (chinese)',['class'=>'form-label']) }}
            {{ Form::text('name_zh', null, array('class' => 'form-control', 'placeholder'=>__('Enter Name'))) }}
        </div>
        @endif
        @if(in_array('es',$act_lan))
        <div class="form-group col-md-6">
            {{ Form::label('name', __('Name').' (spanish)',['class'=>'form-label']) }}
            {{ Form::text('name_es', null, array('class' => 'form-control', 'placeholder'=>__('Enter Name'))) }}
        </div>
        @endif
        <div class="form-group col-md-6">
            {{ Form::label('name', __('Name').' (portuguese)',['class'=>'form-label']) }}<x-required></x-required>
            {{ Form::text('name_pt_br', null, array('class' => 'form-control','required'=>'required' , 'placeholder'=>__('Enter Name'))) }}
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('code', __('Code'),['class'=>'form-label']) }}<x-required></x-required>
            {{ Form::number('code', null, array('class' => 'form-control','required'=>'required' , 'placeholder'=>__('Enter Code'))) }}
        </div>
        <div class="form-group col-md-12">
            {{Form::label('is_enabled',__('Is Enabled'),array('class'=>'form-label')) }}
            <div class="form-check form-switch">
                <input type="checkbox" class="form-check-input" name="is_enabled" id="is_enabled" {{$chartOfAccount->is_enabled==1?'checked':''}}>
                <label class="custom-control-label form-check-label" for="is_enabled"></label>
            </div>
        </div>


        @if(in_array('en',$act_lan))
        <div class="form-group col-md-6">
            {{ Form::label('description', __('Description').' (english)',['class'=>'form-label']) }}
            {!! Form::textarea('description', null, ['class'=>'form-control','rows'=>'2' , 'placeholder'=>__('Enter Description')]) !!}
        </div>
        @endif
        @if(in_array('fr',$act_lan))
        <div class="form-group col-md-6">
            {{ Form::label('description', __('Description').' (french)',['class'=>'form-label']) }}
            {!! Form::textarea('description_fr', null, ['class'=>'form-control','rows'=>'2' , 'placeholder'=>__('Enter Description')]) !!}
        </div>
        @endif
        @if(in_array('zh',$act_lan))
        <div class="form-group col-md-6">
            {{ Form::label('description', __('Description').' (chinese)',['class'=>'form-label']) }}
            {!! Form::textarea('description_zh', null, ['class'=>'form-control','rows'=>'2' , 'placeholder'=>__('Enter Description')]) !!}
        </div>
        @endif
        @if(in_array('es',$act_lan))
        <div class="form-group col-md-6">
            {{ Form::label('description', __('Description').' (spanish)',['class'=>'form-label']) }}
            {!! Form::textarea('description_es', null, ['class'=>'form-control','rows'=>'2' , 'placeholder'=>__('Enter Description')]) !!}
        </div>
        @endif
        <div class="form-group col-md-6">
            {{ Form::label('description', __('Description').' (portuguese)',['class'=>'form-label']) }}
            {!! Form::textarea('description_pt_br', null, ['class'=>'form-control','rows'=>'2' , 'placeholder'=>__('Enter Description')]) !!}
        </div>

    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn  btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Update')}}" class="btn  btn-primary">
</div>
{{ Form::close() }}