@extends('layouts.auth')

@section('page-title')
    {{ __('Two-Factor Verification') }}
@endsection

@section('content')
    <div class="d-flex justify-content-center align-items-center">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-body p-4">
                    <h4 class="mb-2">{{ __('Verify your login') }}</h4>
                    <p class="text-muted mb-4">{{ __('Enter the 6-digit code from Google Authenticator, or use a recovery code.') }}</p>

                    @if (session('error'))
                        <div class="alert alert-danger">{{ session('error') }}</div>
                    @endif

                    {{ Form::open(['route' => '2fa.challenge.verify', 'method' => 'post']) }}
                        <div class="form-group mb-3">
                            <label class="form-label">{{ __('Authenticator Code') }}</label>
                            <input type="text" name="otp" class="form-control" maxlength="6" pattern="\d{6}" placeholder="{{ __('123456') }}" autocomplete="one-time-code">
                        </div>

                        <div class="text-center text-muted mb-3">{{ __('OR') }}</div>

                        <div class="form-group mb-3">
                            <label class="form-label">{{ __('Recovery Code') }}</label>
                            <input type="text" name="recovery_code" class="form-control" placeholder="{{ __('ABCD-EFGH') }}">
                        </div>

                        @error('otp')
                            <small class="text-danger d-block mb-2">{{ $message }}</small>
                        @enderror
                        @error('recovery_code')
                            <small class="text-danger d-block mb-2">{{ $message }}</small>
                        @enderror

                        <button type="submit" class="btn btn-primary w-100">{{ __('Verify') }}</button>
                    {{ Form::close() }}
                </div>
            </div>
        </div>
    </div>
@endsection
