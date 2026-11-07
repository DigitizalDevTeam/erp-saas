@extends('layouts.admin')
@section('page-title')
    {{ __('Close Session') }} #{{ $preview['session']->id }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-cash-sessions.index') }}">{{ __('Cash Registers') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-cash-sessions.show', $preview['session']) }}">{{ __('Session') }} #{{ $preview['session']->id }}</a></li>
    <li class="breadcrumb-item">{{ __('Close') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <dl class="row mb-3">
                        <dt class="col-sm-4">{{ __('Register') }}</dt>
                        <dd class="col-sm-8">{{ $preview['register']->displayName() }} — {{ $preview['session']->currency }}</dd>
                        <dt class="col-sm-4">{{ __('Expected Cash') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $preview['expected'], 2) }} {{ $preview['session']->currency }}</dd>
                        <dt class="col-sm-4">{{ __('Counted Cash') }}</dt>
                        <dd class="col-sm-8">{{ $preview['counted'] !== null ? number_format((float) $preview['counted'], 2) . ' ' . $preview['session']->currency : __('No count recorded yet') }}</dd>
                        <dt class="col-sm-4">{{ __('Variance') }}</dt>
                        <dd class="col-sm-8">
                            @if ($preview['variance'] !== null)
                                <span class="{{ (float) $preview['variance'] < 0 ? 'text-danger' : ((float) $preview['variance'] > 0 ? 'text-success' : '') }}">{{ number_format((float) $preview['variance'], 2) }} {{ $preview['session']->currency }}</span>
                                {{ (float) $preview['variance'] < 0 ? __('(shortage)') : ((float) $preview['variance'] > 0 ? __('(surplus)') : '') }}
                            @else
                                —
                            @endif
                        </dd>
                        @if ($preview['variance_delta'] !== null && bccomp($preview['variance_delta'], '0', 4) !== 0)
                            <dt class="col-sm-4">{{ __('Adjustment To Post') }}</dt>
                            <dd class="col-sm-8">{{ number_format((float) $preview['variance_delta'], 2) }} {{ $preview['session']->currency }} {{ __('(delta against the variance already posted)') }}</dd>
                        @endif
                        <dt class="col-sm-4">{{ __('Card Total') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $preview['card_total'], 2) }} {{ $preview['session']->currency }}</dd>
                        <dt class="col-sm-4">{{ __('Mobile Total') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $preview['mobile_total'], 2) }} {{ $preview['session']->currency }}</dd>
                    </dl>

                    <h6>{{ __('Recheck') }}</h6>
                    <ul class="list-unstyled">
                        @foreach ($preview['checks'] as $check)
                            <li class="{{ $check['passed'] ? 'text-success' : 'text-danger' }}">{{ $check['passed'] ? '✓' : '✗' }} {{ $check['detail'] }}</li>
                        @endforeach
                    </ul>

                    @if ($preview['can_close'])
                        <form method="POST" action="{{ route('treasury-cash-sessions.close', $preview['session']) }}">
                            @csrf
                            @if ($preview['variance'] !== null && bccomp($preview['variance'], '0', 4) !== 0)
                                <div class="mb-3">
                                    <label class="form-label">{{ __('Variance Reason') }} <span class="text-danger">*</span></label>
                                    <textarea name="variance_reason" class="form-control" rows="2" required maxlength="1000">{{ old('variance_reason', $preview['session']->variance_reason ?? '') }}</textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">{{ __('Variance Approved By') }} <span class="text-danger">*</span></label>
                                    <select name="variance_approved_by" class="form-control" required>
                                        <option value="">{{ __('Select the approver') }}</option>
                                        @foreach ($approvers as $approver)
                                            <option value="{{ $approver->id }}" {{ (int) old('variance_approved_by') === (int) $approver->id ? 'selected' : '' }}>{{ $approver->name }}</option>
                                        @endforeach
                                    </select>
                                    <small class="text-muted">{{ __('A different user than the operator and the closer, holding the approve treasury cash variances permission. The adjustment posts separately from the count.') }}</small>
                                </div>
                            @endif
                            <div class="mb-3">
                                <label class="form-label">{{ __('Posting Date') }}</label>
                                <input type="date" name="posting_date" class="form-control" value="{{ old('posting_date', $preview['posting_date']) }}">
                            </div>
                            <button type="submit" class="btn btn-primary">{{ __('Close Session') }}</button>
                            <a href="{{ route('treasury-cash-sessions.show', $preview['session']) }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
                        </form>
                    @else
                        <a href="{{ route('treasury-cash-sessions.show', $preview['session']) }}" class="btn btn-outline-secondary">{{ __('Back') }}</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
