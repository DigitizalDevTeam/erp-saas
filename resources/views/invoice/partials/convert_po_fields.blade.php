{{-- Client PO + quotation reference fields for invoice convert forms --}}
<div class="col-md-6 mb-3">
    {{ Form::label('client_po_number', __('Client PO Number'), ['class' => 'form-label']) }}
    {{ Form::text('client_po_number', old('client_po_number', $clientPoNumber ?? ''), ['class' => 'form-control', 'placeholder' => __('Customer PO number')]) }}
</div>
<div class="col-md-6 mb-3">
    {{ Form::label('client_po_date', __('Client PO Date'), ['class' => 'form-label']) }}
    {{ Form::date('client_po_date', old('client_po_date', $clientPoDate ?? null), ['class' => 'form-control']) }}
</div>
<div class="col-md-12 mb-3">
    {{ Form::label('client_po_details', __('Client PO Details'), ['class' => 'form-label']) }}
    {{ Form::textarea('client_po_details', old('client_po_details', $clientPoDetails ?? ''), ['class' => 'form-control', 'rows' => 2, 'placeholder' => __('Optional PO notes / details')]) }}
</div>
<div class="col-md-6 mb-3">
    {{ Form::label('quotation_reference', __('Quotation Reference'), ['class' => 'form-label']) }}
    {{ Form::text('quotation_reference', old('quotation_reference', $quotationReference ?? ''), ['class' => 'form-control', 'readonly' => true]) }}
    <small class="text-muted">{{ __('Filled automatically from the source quotation.') }}</small>
</div>
<div class="col-md-6 mb-3">
    {{ Form::label('ref_number', __('Reference'), ['class' => 'form-label']) }}
    {{ Form::text('ref_number', old('ref_number', $refNumber ?? ($quotationReference ?? '')), ['class' => 'form-control']) }}
</div>
