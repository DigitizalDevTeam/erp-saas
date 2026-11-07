{{--
    Billing Contracts — shared form fields (create / edit / amend).
    Expects: $contract (nullable), $customers, $products, $taxes.
    Lines are posted as lines[][description|product_id|quantity|
    unit_price|discount_amount|tax_id]; blank rows are ignored.
--}}
<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            <label class="form-label">{{ __('Customer') }} *</label>
            <select name="customer_id" class="form-control" required>
                <option value="">{{ __('Select customer') }}</option>
                @foreach ($customers as $customer)
                    <option value="{{ $customer->id }}" {{ (string) old('customer_id', $contract->customer_id ?? '') === (string) $customer->id ? 'selected' : '' }}>{{ $customer->name }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            <label class="form-label">{{ __('Title') }} *</label>
            <input type="text" name="title" class="form-control" value="{{ old('title', $contract->title ?? '') }}" required>
        </div>
    </div>
    <div class="col-md-12">
        <div class="form-group">
            <label class="form-label">{{ __('Description') }}</label>
            <textarea name="description" class="form-control" rows="2">{{ old('description', $contract->description ?? '') }}</textarea>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('Start date') }} *</label>
            <input type="date" name="start_date" class="form-control" value="{{ old('start_date', $contract && $contract->start_date ? $contract->start_date->format('Y-m-d') : '') }}" required>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('End date') }}</label>
            <input type="date" name="end_date" class="form-control" value="{{ old('end_date', $contract && $contract->end_date ? $contract->end_date->format('Y-m-d') : '') }}">
            <small class="text-muted">{{ __('Leave empty for an open-ended contract.') }}</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('Currency') }}</label>
            <input type="text" name="currency" class="form-control" maxlength="3" value="{{ old('currency', $contract->currency ?? 'MZN') }}">
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('Exchange rate to MZN') }}</label>
            <input type="text" name="exchange_rate" class="form-control" value="{{ old('exchange_rate', $contract->exchange_rate ?? '1') }}">
            <small class="text-muted">{{ __('Required when the currency is not MZN. Never guessed.') }}</small>
        </div>
    </div>
</div>

<h6 class="mt-4">{{ __('Billing schedule') }}</h6>
<div class="row">
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('Frequency') }} *</label>
            <select name="billing_frequency" class="form-control" id="billing-frequency">
                @foreach (\App\Models\BillingContract::FREQUENCIES as $frequency)
                    <option value="{{ $frequency }}" {{ old('billing_frequency', $contract->billing_frequency ?? 'monthly') === $frequency ? 'selected' : '' }}>{{ ucfirst($frequency) }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('Every (custom only)') }}</label>
            <input type="number" name="custom_interval" class="form-control" min="1" value="{{ old('custom_interval', $contract->custom_interval ?? '') }}">
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('Custom unit') }}</label>
            <select name="custom_unit" class="form-control">
                <option value="">{{ __('—') }}</option>
                @foreach (\App\Models\BillingContract::CUSTOM_UNITS as $unit)
                    <option value="{{ $unit }}" {{ old('custom_unit', $contract->custom_unit ?? '') === $unit ? 'selected' : '' }}>{{ ucfirst($unit) }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('Anchor day') }} *</label>
            <input type="number" name="anchor_day" class="form-control" min="1" max="31" value="{{ old('anchor_day', $contract->anchor_day ?? date('j')) }}" required>
            <small class="text-muted">{{ __('31 clamps to the last day of shorter months.') }}</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('Billing mode') }}</label>
            <select name="billing_mode" class="form-control">
                <option value="advance" {{ old('billing_mode', $contract->billing_mode ?? 'advance') === 'advance' ? 'selected' : '' }}>{{ __('In advance') }}</option>
                <option value="arrears" {{ old('billing_mode', $contract->billing_mode ?? 'advance') === 'arrears' ? 'selected' : '' }}>{{ __('In arrears') }}</option>
            </select>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('Payment terms (days)') }}</label>
            <input type="number" name="payment_terms_days" class="form-control" min="0" value="{{ old('payment_terms_days', $contract->payment_terms_days ?? 15) }}">
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('Approval mode') }}</label>
            <select name="approval_mode" class="form-control">
                <option value="approval" {{ old('approval_mode', $contract->approval_mode ?? 'approval') === 'approval' ? 'selected' : '' }}>{{ __('Approval — a person approves before activation') }}</option>
                <option value="automatic" {{ old('approval_mode', $contract->approval_mode ?? 'approval') === 'automatic' ? 'selected' : '' }}>{{ __('Automatic — activates on submit') }}</option>
            </select>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('Partial first / final period') }}</label>
            <select name="partial_period_rule" class="form-control">
                <option value="full" {{ old('partial_period_rule', $contract->partial_period_rule ?? 'full') === 'full' ? 'selected' : '' }}>{{ __('Full charge (default)') }}</option>
                <option value="prorate" {{ old('partial_period_rule', $contract->partial_period_rule ?? 'full') === 'prorate' ? 'selected' : '' }}>{{ __('Prorate by days') }}</option>
                <option value="skip" {{ old('partial_period_rule', $contract->partial_period_rule ?? 'full') === 'skip' ? 'selected' : '' }}>{{ __('Skip the partial period') }}</option>
            </select>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <div class="form-check mt-4">
                <input type="checkbox" name="proration_enabled" value="1" class="form-check-input" id="proration-enabled" {{ old('proration_enabled', $contract->proration_enabled ?? false) ? 'checked' : '' }}>
                <label class="form-check-label" for="proration-enabled">{{ __('Proration enabled') }}</label>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label class="form-label">{{ __('Renewal notice (days)') }}</label>
            <input type="number" name="renewal_notice_days" class="form-control" min="0" value="{{ old('renewal_notice_days', $contract->renewal_notice_days ?? 30) }}">
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <div class="form-check mt-4">
                <input type="checkbox" name="auto_renew" value="1" class="form-check-input" id="auto-renew" {{ old('auto_renew', $contract->auto_renew ?? false) ? 'checked' : '' }}>
                <label class="form-check-label" for="auto-renew">{{ __('Automatic renewal') }}</label>
            </div>
        </div>
    </div>
</div>

<h6 class="mt-4">{{ __('Billing lines') }}</h6>
<table class="table table-sm" id="billing-lines-table">
    <thead>
        <tr>
            <th>{{ __('Product / service') }}</th>
            <th>{{ __('Description') }} *</th>
            <th>{{ __('Qty') }}</th>
            <th>{{ __('Unit price') }}</th>
            <th>{{ __('Discount') }}</th>
            <th>{{ __('Tax') }}</th>
        </tr>
    </thead>
    <tbody>
        @php
            $formLines = old('lines');
            if (! is_array($formLines)) {
                $formLines = isset($contract) && $contract->lines->isNotEmpty()
                    ? $contract->lines->map(fn ($l) => ['product_id' => $l->product_id, 'description' => $l->description, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'discount_amount' => $l->discount_amount, 'tax_id' => $l->tax_id])->all()
                    : [['description' => '', 'quantity' => '1', 'unit_price' => '', 'discount_amount' => '0']];
                $formLines[] = ['description' => '', 'quantity' => '1', 'unit_price' => '', 'discount_amount' => '0'];
                $formLines[] = ['description' => '', 'quantity' => '1', 'unit_price' => '', 'discount_amount' => '0'];
            }
        @endphp
        @foreach ($formLines as $i => $line)
            <tr>
                <td>
                    <select name="lines[{{ $i }}][product_id]" class="form-control">
                        <option value="">{{ __('—') }}</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" {{ (string) ($line['product_id'] ?? '') === (string) $product->id ? 'selected' : '' }}>{{ $product->name }}</option>
                        @endforeach
                    </select>
                </td>
                <td><input type="text" name="lines[{{ $i }}][description]" class="form-control" value="{{ $line['description'] ?? '' }}"></td>
                <td><input type="text" name="lines[{{ $i }}][quantity]" class="form-control" value="{{ $line['quantity'] ?? '1' }}"></td>
                <td><input type="text" name="lines[{{ $i }}][unit_price]" class="form-control" value="{{ $line['unit_price'] ?? '' }}"></td>
                <td><input type="text" name="lines[{{ $i }}][discount_amount]" class="form-control" value="{{ $line['discount_amount'] ?? '0' }}"></td>
                <td>
                    <select name="lines[{{ $i }}][tax_id]" class="form-control">
                        <option value="">{{ __('—') }}</option>
                        @foreach ($taxes as $tax)
                            <option value="{{ $tax->id }}" {{ (string) ($line['tax_id'] ?? '') === (string) $tax->id ? 'selected' : '' }}>{{ $tax->name }} ({{ $tax->rate }}%)</option>
                        @endforeach
                    </select>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
<button type="button" class="btn btn-sm btn-secondary" id="add-billing-line">{{ __('Add line') }}</button>

<h6 class="mt-4">{{ __('Delivery') }}</h6>
<div class="row">
    <div class="col-md-3">
        <div class="form-group">
            <div class="form-check mt-4">
                <input type="checkbox" name="delivery_auto_email" value="1" class="form-check-input" id="delivery-auto-email" {{ old('delivery_auto_email', $contract->delivery_auto_email ?? false) ? 'checked' : '' }}>
                <label class="form-check-label" for="delivery-auto-email">{{ __('Send invoices automatically') }}</label>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('To (comma-separated emails)') }}</label>
            <input type="text" name="delivery_to" class="form-control" value="{{ old('delivery_to', $contract->delivery_to ?? '') }}">
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-group">
            <label class="form-label">{{ __('CC') }}</label>
            <input type="text" name="delivery_cc" class="form-control" value="{{ old('delivery_cc', $contract->delivery_cc ?? '') }}">
        </div>
    </div>
    <div class="col-md-12">
        <div class="form-group">
            <label class="form-label">{{ __('Notes') }}</label>
            <textarea name="notes" class="form-control" rows="2">{{ old('notes', $contract->notes ?? '') }}</textarea>
        </div>
    </div>
</div>
