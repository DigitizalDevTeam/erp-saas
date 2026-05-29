{{ Form::open(['url' => 'chart-of-account', 'class'=>'needs-validation', 'novalidate']) }}
<div class="modal-body">
    {{-- start for ai module --}}
    @php
        $plan = \App\Models\Utility::getChatGPTSettings();
        $settings = \App\Models\Utility::settings();
        $act_lan = isset($settings['languages']) ?  json_decode($settings['languages']) : [];
    @endphp
    @if ($plan->chatgpt == 1)
        <div class="text-end">
            <a href="#" data-size="md" class="btn  btn-primary btn-icon btn-sm" data-ajax-popup-over="true"
                data-url="{{ route('generate', ['chart of account']) }}" data-bs-placement="top"
                data-title="{{ __('Generate content with AI') }}">
                <i class="fas fa-robot"></i> <span>{{ __('Generate with AI') }}</span>
            </a>
        </div>
    @endif
    {{-- end for ai module --}}
    <div class="row">
        @if(in_array('en',$act_lan))
            <div class="form-group col-md-6">
                {{ Form::label('name', __('Name'), ['class' => 'form-label']) }} <strong>(english)</strong><x-required></x-required>
                {{ Form::text('name', '', ['class' => 'form-control', 'required' => 'required' , 'placeholder'=>__('Enter Name')]) }}
            </div>
        @endif
        @if(in_array('fr',$act_lan))
        <div class="form-group col-md-6">
            {{ Form::label('name', __('Name'), ['class' => 'form-label']) }} <strong>(french)</strong><x-required></x-required>
            {{ Form::text('name_fr', '', ['class' => 'form-control', 'required' => 'required', 'placeholder'=>__('Enter Name')]) }}
        </div>
        @endif
        @if(in_array('zh',$act_lan))
        <div class="form-group col-md-6">
            {{ Form::label('name', __('Name'), ['class' => 'form-label']) }} <strong>(chinese)</strong><x-required></x-required>
            {{ Form::text('name_zh', '', ['class' => 'form-control', 'required' => 'required', 'placeholder'=>__('Enter Name')]) }}
        </div>
        @endif
        @if(in_array('es',$act_lan))
        <div class="form-group col-md-6">
            {{ Form::label('name', __('Name'), ['class' => 'form-label']) }} <strong>(spanish)</strong><x-required></x-required>
            {{ Form::text('name_es', '', ['class' => 'form-control', 'required' => 'required',  'placeholder'=>__('Enter Name')]) }}
        </div>
        @endif
        <div class="form-group col-md-6">
            {{ Form::label('name', __('Name'), ['class' => 'form-label']) }} <strong>(portuguese)</strong><x-required></x-required>
            {{ Form::text('name_pt_br', '', ['class' => 'form-control', 'required' => 'required' , 'placeholder'=>__('Enter Name')]) }}
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('code', __('Code'), ['class' => 'form-label']) }}<x-required></x-required>
            {{ Form::number('code', '', ['class' => 'form-control', 'required' => 'required' , 'placeholder'=>__('Enter Code')]) }}
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('sub_type', __('Account Type'), ['class' => 'form-label']) }}<x-required></x-required>
            {{ Form::select('sub_type', $account_type, null, ['class' => 'form-control select', 'required' => 'required']) }}
        </div>

        <div class="col-md-12">
            <div class="form-group ">
                {{ Form::label('is_enabled', __('Is Enabled'), ['class' => 'form-label']) }}
                <div class="form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="is_enabled" id="is_enabled" checked>
                    <label class="custom-control-label form-check-label" for="is_enabled"></label>
                </div>
            </div>
        </div>

        <div class="col-md-4 mt-4 acc_check">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="account">
                <label class="form-check-label" for="account">{{__('Make this a sub-account')}}</label>
            </div>
        </div>

        <div class="form-group col-md-12 acc_type d-none">
            {{ Form::label('parent_account_id', __('Parent Account'), ['class' => 'form-label']) }}
            <select class="form-control" name="parent_account_id" id="parent_account_id">
                <option value="0">{{ __('Select an account') }}</option>
            </select>
            <small class="text-muted">{{ __('Choose any account to nest under it (unlimited levels).') }}</small>
        </div>

        @if(in_array('en',$act_lan))
        <div class="form-group col-md-6">
            {{ Form::label('description', __('Description (english)'), ['class' => 'form-label']) }}
            {!! Form::textarea('description', null, ['class' => 'form-control', 'rows' => '2'  , 'placeholder'=>__('Enter Description')]) !!}
        </div>
        @endif
        @if(in_array('fr',$act_lan))
        <div class="form-group col-md-6">
            {{ Form::label('description', __('Description (french)'), ['class' => 'form-label']) }}
            {!! Form::textarea('description_fr', null, ['class' => 'form-control', 'rows' => '2'  , 'placeholder'=>__('Enter Description')]) !!}
        </div>
        @endif
        @if(in_array('zh',$act_lan))
        <div class="form-group col-md-6">
            {{ Form::label('description', __('Description (chinese)'), ['class' => 'form-label']) }}
            {!! Form::textarea('description_zh', null, ['class' => 'form-control', 'rows' => '2'  , 'placeholder'=>__('Enter Description')]) !!}
        </div>
        @endif
        
        @if(in_array('es',$act_lan))
        <div class="form-group col-md-6">
            {{ Form::label('description', __('Description (spanish)'), ['class' => 'form-label']) }}
            {!! Form::textarea('description_es', null, ['class' => 'form-control', 'rows' => '2'  , 'placeholder'=>__('Enter Description')]) !!}
        </div>
        @endif
        <div class="form-group col-md-6">
            {{ Form::label('description', __('Description (portuguese)'), ['class' => 'form-label']) }}
            {!! Form::textarea('description_pt_br', null, ['class' => 'form-control', 'rows' => '2'  , 'placeholder'=>__('Enter Description')]) !!}
        </div>

    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{ __('Cancel') }}" class="btn  btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{ __('Create') }}" class="btn  btn-primary">
</div>
{{ Form::close() }}