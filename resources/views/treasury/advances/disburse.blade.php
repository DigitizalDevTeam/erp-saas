@extends('layouts.admin')
@section('page-title')
    {{ __('Disburse Advance') }} {{ $advance->operation->reference ?? '' }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-advances.index') }}">{{ __('Advances') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-advances.show', $advance) }}">{{ $advance->operation->reference ?? __('Draft') }}</a></li>
    <li class="breadcrumb-item">{{ __('Disburse') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <dl class="row mb-3">
                        <dt class="col-sm-4">{{ __('Recipient') }}</dt>
                        <dd class="col-sm-8">{{ $advance->recipientLabel() }}</dd>
                        <dt class="col-sm-4">{{ __('Approved Amount') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $advance->amount_approved, 2) }} {{ $advance->currency }}</dd>
                        <dt class="col-sm-4">{{ __('Source Account') }}</dt>
                        <dd class="col-sm-8">{{ $source->code }} — {{ $source->displayName() }}</dd>
                        <dt class="col-sm-4">{{ __('Available Balance') }}</dt>
                        <dd class="col-sm-8">{{ number_format((float) $available_balance, 2) }} {{ $advance->currency }}</dd>
                        <dt class="col-sm-4">{{ __('Posting') }}</dt>
                        <dd class="col-sm-8">{{ __('Dr advance asset account / Cr') }} {{ $source->code }}</dd>
                    </dl>

                    @if (! empty($checks))
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{ __('Check') }}</th>
                                    <th>{{ __('Result') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($checks as $check)
                                    <tr>
                                        <td>{{ ucfirst(str_replace('_', ' ', $check['key'])) }}</td>
                                        <td><span class="badge bg-{{ $check['passed'] ? 'success' : 'danger' }}">{{ $check['passed'] ? __('OK') : __('Failed') }}</span> {{ $check['detail'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif

                    <form method="POST" action="{{ route('treasury-advances.disburse', $advance) }}">
                        @csrf
                        <div class="form-group">
                            <label class="form-label" for="posting_date">{{ __('Posting Date') }}</label>
                            <input type="date" name="posting_date" id="posting_date" class="form-control" value="{{ date('Y-m-d') }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="adjustment_authorised_by">{{ __('Closed-Period Adjustment — Authorised By') }}</label>
                            <select name="adjustment_authorised_by" id="adjustment_authorised_by" class="form-control">
                                <option value="">{{ __('Not an adjustment') }}</option>
                                @foreach ($users as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </select>
                            <small class="text-muted">{{ __('Only fill this in when the intended posting date is in a closed period. The authoriser must be a different user who holds the manage treasury advances permission.') }}</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="adjustment_reason">{{ __('Adjustment Reason') }}</label>
                            <input type="text" name="adjustment_reason" id="adjustment_reason" class="form-control" maxlength="1000">
                        </div>
                        <button type="submit" class="btn btn-primary" {{ empty($can_disburse) ? 'disabled' : '' }}>{{ __('Confirm Disbursement') }}</button>
                        <a href="{{ route('treasury-advances.show', $advance) }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
