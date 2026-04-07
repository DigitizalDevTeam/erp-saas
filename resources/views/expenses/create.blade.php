{{ Form::open(['route' => 'expenses.store', 'method' => 'post', 'enctype' => 'multipart/form-data', 'class' => 'needs-validation', 'novalidate']) }}
<div class="modal-body">
    <div class="row">
        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('name', __('Name'), ['class' => 'form-label']) }}<x-required></x-required>
                {{ Form::text('name', null, ['class' => 'form-control', 'required' => 'required', 'placeholder' => __('Enter expense name')]) }}
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('date', __('Date'), ['class' => 'form-label']) }}<x-required></x-required>
                {{ Form::text('date', date('Y-m-d'), ['class' => 'form-control flatpickr-to-input', 'required' => 'required']) }}
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('amount', __('Amount'), ['class' => 'form-label']) }}<x-required></x-required>
                {{ Form::number('amount', null, ['class' => 'form-control', 'required' => 'required', 'min' => '0', 'step' => '0.01', 'placeholder' => __('0.00')]) }}
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('expense_category_id', __('Category'), ['class' => 'form-label']) }}<x-required></x-required>
                {{ Form::select('expense_category_id', $categories, null, ['class' => 'form-control select', 'required' => 'required', 'placeholder' => __('Select Category')]) }}
            </div>
        </div>
        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('chart_account_id', __('Expense account (GL)'), ['class' => 'form-label']) }}
                {{ Form::select('chart_account_id', $chartAccounts, null, ['class' => 'form-control select']) }}
                <small class="text-muted">{{ __('Required before approval. Sub-accounts are indented. Pick the ledger account for this expense.') }}</small>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }}
                {{ Form::select('branch_id', $branches, null, ['class' => 'form-control select', 'placeholder' => __('Select Branch')]) }}
            </div>
        </div>
        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('payment_account_id', __('Payment account'), ['class' => 'form-label']) }}
                {{ Form::select('payment_account_id', $bankAccounts, null, ['class' => 'form-control select']) }}
                <small class="text-muted">{{ __('Required before approval: bank account that pays this expense (posts to accounting).') }}</small>
            </div>
        </div>
        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('description', __('Description'), ['class' => 'form-label']) }}
                {{ Form::textarea('description', null, ['class' => 'form-control', 'rows' => 2, 'placeholder' => __('Description')]) }}
            </div>
        </div>
        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('attachment', __('Attachment'), ['class' => 'form-label']) }}
                <input type="file" class="form-control" name="attachment" id="attachment" accept=".jpeg,.png,.jpg,.gif,.pdf,.doc,.docx">
            </div>
        </div>
    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{ __('Cancel') }}" class="btn btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{ __('Create') }}" class="btn btn-primary">
</div>
{{ Form::close() }}
