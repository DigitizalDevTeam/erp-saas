@php
    $v = $vender ?? null;
@endphp
<h5 class="sub-title mt-3">{{ __('Notifications') }}</h5>
<p class="text-muted small">{{ __('Optional contact email/phone for operational messages. Leave email empty to use the login email.') }}</p>
<div class="row">
    <div class="col-md-6">
        <div class="form-group">
            {{ Form::label('notification_email', __('Notification email'), ['class' => 'form-label']) }}
            {{ Form::email('notification_email', old('notification_email', $v ? ($v->notification_email ?? '') : ''), ['class' => 'form-control', 'placeholder' => __('Same as main email if empty')]) }}
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group">
            {{ Form::label('notification_phone', __('Notification phone (SMS / WhatsApp)'), ['class' => 'form-label']) }}
            {{ Form::text('notification_phone', old('notification_phone', $v ? ($v->notification_phone ?? '') : ''), ['class' => 'form-control', 'placeholder' => __('E.164 e.g. +351912345678')]) }}
        </div>
    </div>
</div>
<div class="row">
    <div class="col-md-12">
        <p class="small text-muted mb-2">{{ __('Account / statement style messages') }}</p>
    </div>
    <div class="col-md-4">
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" name="notify_account_email" value="1" id="v_notify_account_email"
                {{ old('notify_account_email', $v ? ($v->notify_account_email ? '1' : '') : '1') ? 'checked' : '' }}>
            <label class="form-check-label" for="v_notify_account_email">{{ __('Account — Email') }}</label>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" name="notify_account_sms" value="1" id="v_notify_account_sms"
                {{ old('notify_account_sms', $v && $v->notify_account_sms) ? 'checked' : '' }}>
            <label class="form-check-label" for="v_notify_account_sms">{{ __('Account — SMS') }}</label>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" name="notify_account_whatsapp" value="1" id="v_notify_account_whatsapp"
                {{ old('notify_account_whatsapp', $v && $v->notify_account_whatsapp) ? 'checked' : '' }}>
            <label class="form-check-label" for="v_notify_account_whatsapp">{{ __('Account — WhatsApp') }}</label>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-md-12">
        <p class="small text-muted mb-2">{{ __('Payments') }}</p>
    </div>
    <div class="col-md-4">
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" name="notify_payment_email" value="1" id="v_notify_payment_email"
                {{ old('notify_payment_email', $v ? ($v->notify_payment_email ? '1' : '') : '1') ? 'checked' : '' }}>
            <label class="form-check-label" for="v_notify_payment_email">{{ __('Payment — Email') }}</label>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" name="notify_payment_sms" value="1" id="v_notify_payment_sms"
                {{ old('notify_payment_sms', $v && $v->notify_payment_sms) ? 'checked' : '' }}>
            <label class="form-check-label" for="v_notify_payment_sms">{{ __('Payment — SMS') }}</label>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" name="notify_payment_whatsapp" value="1" id="v_notify_payment_whatsapp"
                {{ old('notify_payment_whatsapp', $v && $v->notify_payment_whatsapp) ? 'checked' : '' }}>
            <label class="form-check-label" for="v_notify_payment_whatsapp">{{ __('Payment — WhatsApp') }}</label>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-md-12">
        <p class="small text-muted mb-2">{{ __('Bills / purchase documents') }}</p>
    </div>
    <div class="col-md-4">
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" name="notify_bill_email" value="1" id="v_notify_bill_email"
                {{ old('notify_bill_email', $v ? ($v->notify_bill_email ? '1' : '') : '1') ? 'checked' : '' }}>
            <label class="form-check-label" for="v_notify_bill_email">{{ __('Bill — Email') }}</label>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" name="notify_bill_sms" value="1" id="v_notify_bill_sms"
                {{ old('notify_bill_sms', $v && $v->notify_bill_sms) ? 'checked' : '' }}>
            <label class="form-check-label" for="v_notify_bill_sms">{{ __('Bill — SMS') }}</label>
        </div>
    </div>
    <div class="col-md-4">
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input" name="notify_bill_whatsapp" value="1" id="v_notify_bill_whatsapp"
                {{ old('notify_bill_whatsapp', $v && $v->notify_bill_whatsapp) ? 'checked' : '' }}>
            <label class="form-check-label" for="v_notify_bill_whatsapp">{{ __('Bill — WhatsApp') }}</label>
        </div>
    </div>
</div>
