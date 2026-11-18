{{ Form::model($creditNote, array('route' => array('invoice.edit.credit.note',$creditNote->invoice, $creditNote->id), 'method' => 'post', 'class'=>'needs-validation', 'novalidate', 'files' => true)) }}
@php
    // The reason is mandatory for credit notes (Phase 1); debit notes
    // keep the old optional description.
    $isCreditNote = (int) $creditNote->is_debit === 0;
@endphp
<div class="modal-body">
    <div class="row">
        <div class="form-group  col-md-6">
            {{ Form::label('date', __('Date'),['class'=>'form-label']) }}<x-required></x-required>
            {{Form::date('date',null,array('class'=>'form-control','required'=>'required'))}}
        </div>
        <div class="form-group col-md-6">
            {{ Form::label('amount', __('Amount'),['class'=>'form-label']) }}<x-required></x-required>
            {{ Form::number('amount', null, array('class' => 'form-control','required'=>'required','step'=>'0.01', 'placeholder'=>__('Enter Amount'))) }}
        </div>
        <div class="form-group col-md-12">
            {{ Form::label('description', __('Description'),['class'=>'form-label']) }}@if($isCreditNote)<x-required></x-required>@endif
            {!! Form::textarea('description', null, array_merge(['class'=>'form-control','rows'=>'3', 'placeholder'=>__('Enter Description')], $isCreditNote ? ['required'=>'required'] : [])) !!}
        </div>
        <div class="form-group col-md-12">
            {{ Form::label('attachment', __('Supporting document (optional)'),['class'=>'form-label']) }}
            <input type="file" name="attachment" class="form-control">
            @if(!empty($creditNote->attachment))
                <div class="text-xs mt-1"><a href="{{ asset(Storage::url('uploads/credit-notes')) . '/' . $creditNote->attachment }}" target="_blank">{{ __('Current document') }}</a></div>
            @endif
        </div>
    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Update')}}" class="btn  btn-primary">
</div>
{{ Form::close() }}
