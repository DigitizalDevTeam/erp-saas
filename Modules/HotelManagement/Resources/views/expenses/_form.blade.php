@php
    $expense = $expense ?? null;
    $isEdit = $expense !== null;
@endphp
<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            {{ Form::label('category', __('Category'), ['class' => 'form-label']) }}
            {!! Form::select('category', $categoryOptions, old('category', $expense->category ?? $category ?? 'laundry'), ['class' => 'form-control', 'required' => true]) !!}
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            {{ Form::label('expense_date', __('Expense date'), ['class' => 'form-label']) }}
            {!! Form::date('expense_date', old('expense_date', optional($expense->expense_date ?? null)->format('Y-m-d') ?? date('Y-m-d')), ['class' => 'form-control', 'required' => true]) !!}
        </div>
    </div>
    <div class="col-md-8">
        <div class="form-group">
            {{ Form::label('title', __('Title'), ['class' => 'form-label']) }}
            {!! Form::text('title', old('title', $expense->title ?? ''), ['class' => 'form-control', 'required' => true]) !!}
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            {{ Form::label('amount', __('Amount'), ['class' => 'form-label']) }}
            {!! Form::number('amount', old('amount', $expense->amount ?? ''), ['class' => 'form-control', 'step' => '0.01', 'min' => '0.01', 'required' => true]) !!}
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            {{ Form::label('payment_method', __('Payment method'), ['class' => 'form-label']) }}
            {!! Form::select('payment_method', ['' => __('Select')] + $paymentMethods, old('payment_method', $expense->payment_method ?? 'cash'), ['class' => 'form-control']) !!}
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            {{ Form::label('vendor', __('Vendor'), ['class' => 'form-label']) }}
            {!! Form::text('vendor', old('vendor', $expense->vendor ?? ''), ['class' => 'form-control']) !!}
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            {{ Form::label('reference', __('Reference'), ['class' => 'form-label']) }}
            {!! Form::text('reference', old('reference', $expense->reference ?? ''), ['class' => 'form-control']) !!}
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            {{ Form::label('hotel_room_id', __('Room'), ['class' => 'form-label']) }}
            {!! Form::select('hotel_room_id', ['' => __('None')] + $rooms->toArray(), old('hotel_room_id', $expense->hotel_room_id ?? ''), ['class' => 'form-control']) !!}
        </div>
    </div>
    <div class="col-md-8">
        <div class="form-group">
            {{ Form::label('notes', __('Notes'), ['class' => 'form-label']) }}
            {!! Form::textarea('notes', old('notes', $expense->notes ?? ''), ['class' => 'form-control', 'rows' => 3]) !!}
        </div>
    </div>
</div>
