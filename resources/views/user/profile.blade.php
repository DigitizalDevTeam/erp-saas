@extends('layouts.admin')
@php
    $profile=\App\Models\Utility::get_file('uploads/avatar/');
@endphp
@section('page-title')
    {{__('Profile Account')}}
@endsection
@push('script-page')
    <script>
        var scrollSpy = new bootstrap.ScrollSpy(document.body, {
            target: '#useradd-sidenav',
            offset: 300,
        })
        $(".list-group-item").click(function(){
            $('.list-group-item').filter(function(){
                return this.href == id;
            }).parent().removeClass('text-primary');
        });
    </script>

    <script>
        document.getElementById('avatar').onchange = function () {
            var src = URL.createObjectURL(this.files[0])
            document.getElementById('image').src = src
        }
        </script>
@endpush
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{__('Dashboard')}}</a></li>
    <li class="breadcrumb-item">{{__('Profile')}}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-3">
            <div class="card sticky-top" style="top:30px">
                <div class="list-group list-group-flush" id="useradd-sidenav">
                    <a href="#personal_info" class="list-group-item list-group-item-action border-0">{{__('Personal Info')}} <div class="float-end"><i class="ti ti-chevron-right"></i></div></a>

                    <a href="#change_password" class="list-group-item list-group-item-action border-0">{{__('Change Password')}}<div class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                    <a href="#two_factor_auth" class="list-group-item list-group-item-action border-0">{{__('Two-Factor Authentication')}}<div class="float-end"><i class="ti ti-chevron-right"></i></div></a>
                </div>
            </div>
        </div>
        <div class="col-xl-9">
            <div id="personal_info" class="card">
                <div class="card-header">
                    <h5>{{__('Personal Info')}}</h5>
                </div>
                    <div class="card-body">
                    {{Form::model($userDetail,array('route' => array('update.account'), 'method' => 'post', 'enctype' => "multipart/form-data"))}}
                        @csrf
                        <div class="row">
                            <div class="col-lg-6 col-sm-6">
                                <div class="form-group">
                                    <label class="col-form-label text-dark">{{__('Name')}}</label>
                                    <input class="form-control" name="name" type="text" id="name" placeholder="{{ __('Enter Your Name') }}" value="{{ $userDetail->name }}" required autocomplete="name">
                                </div>
                            </div>
                            <div class="col-lg-6 col-sm-6">
                                <div class="form-group">
                                    <label for="email" class="col-form-label text-dark">{{__('Email')}}</label>
                                    <input class="form-control" name="email" type="email" id="email" placeholder="{{ __('Enter Your Email Address') }}" value="{{ $userDetail->email }}" required autocomplete="email">
                                </div>
                            </div>
                            <div class="col-lg-6 col-md-6">
                                <div class="form-group">
                                    <div class="theme-avtar-logo mt-4">
                                        <img id="image" src="{{ ($userDetail->avatar) ? $profile  . $userDetail->avatar : $profile . 'avatar.png' }}"
                                             class="big-logo">
                                    </div>
                                    <div class="choose-files mt-3">
                                        <label for="avatar">
                                            <div class=" bg-primary profile_update"> <i class="ti ti-upload px-1"></i>{{__('Choose file here')}}</div>
                                            <input type="file" class="form-control file file-validate" name="profile" id="avatar" data-filename="profile_update">
                                            <p id="" class="file-error text-danger"></p>
                                        </label>
                                    </div>
                                    <span class="text-xs text-muted">{{ __('Please upload a valid image file. Size of image should not be more than 2MB.')}}</span>
                                </div>

                            </div>
                            <div class="col-lg-12 text-end">
                                <input type="submit" value="{{__('Save Changes')}}" class="btn btn-print-invoice  btn-primary m-r-10">
                            </div>
                        </div>
                    </form>
                </div>

            </div>
            <div id="change_password" class="card">
                <div class="card-header">
                    <h5>{{__('Change Password')}}</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="{{route('update.password')}}">
                        @csrf
                        <div class="row">
                            <div class="col-lg-6 col-sm-6 form-group">
                                <label for="old_password" class="col-form-label text-dark">{{ __('Old Password') }}</label>
                                <input class="form-control" name="old_password" type="password" id="old_password" required autocomplete="old_password" placeholder="{{ __('Enter Old Password') }}">
                            </div>

                            <div class="col-lg-6 col-sm-6 form-group">
                                <label for="password" class="col-form-label text-dark">{{ __('New Password') }}</label>
                                <input class="form-control" name="password" type="password" required autocomplete="new-password" id="password" placeholder="{{ __('Enter Your New Password') }}">
                            </div>
                            <div class="col-lg-6 col-sm-6 form-group">
                                <label for="password_confirmation" class="col-form-label text-dark">{{ __('New Confirm Password') }}</label>
                                <input class="form-control" name="password_confirmation" type="password" required autocomplete="new-password" id="password_confirmation" placeholder="{{ __('Enter Your Confirm Password') }}">
                            </div>
                            <div class="col-lg-12 text-end">
                                <input type="submit" value="{{__('Change Password')}}" class="btn btn-print-invoice  btn-primary m-r-10">
                            </div>
                        </div>
                    </form>
                </div>

            </div>

            <div id="two_factor_auth" class="card">
                <div class="card-header">
                    <h5>{{__('Google Authenticator (2FA)')}}</h5>
                </div>
                <div class="card-body">
                    @if(session('two_factor_recovery_codes_plain'))
                        <div class="alert alert-warning">
                            <strong>{{ __('Save these recovery codes in a safe place. Each code can be used once.') }}</strong>
                            <div class="mt-2">
                                @foreach(session('two_factor_recovery_codes_plain') as $code)
                                    <div><code>{{ $code }}</code></div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if(!$userDetail->two_factor_enabled)
                        <p class="text-muted">{{ __('Scan this QR code in Google Authenticator and verify using a 6-digit code.') }}</p>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <img src="{{ $twoFactorQrUrl }}" alt="2FA QR" class="img-fluid border rounded p-2">
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label">{{ __('Manual setup key') }}</label>
                                <input type="text" class="form-control" value="{{ $twoFactorSecret }}" readonly>
                            </div>
                        </div>

                        <form method="post" action="{{ route('2fa.enable') }}">
                            @csrf
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label class="form-label">{{ __('Authenticator Code') }}</label>
                                    <input type="text" name="otp" maxlength="6" pattern="\d{6}" class="form-control" placeholder="{{ __('123456') }}" required>
                                </div>
                                <div class="col-md-12 text-end">
                                    <button type="submit" class="btn btn-primary">{{ __('Enable 2FA') }}</button>
                                </div>
                            </div>
                        </form>
                    @else
                        <div class="alert alert-success">{{ __('Two-factor authentication is enabled for your account.') }}</div>

                        <form method="post" action="{{ route('2fa.recovery.regenerate') }}" class="mb-3">
                            @csrf
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label class="form-label">{{ __('Current Password (for recovery code regeneration)') }}</label>
                                    <input type="password" name="password" class="form-control" required>
                                </div>
                                <div class="col-md-12 text-end">
                                    <button type="submit" class="btn btn-light-primary">{{ __('Regenerate Recovery Codes') }}</button>
                                </div>
                            </div>
                        </form>

                        <form method="post" action="{{ route('2fa.disable') }}">
                            @csrf
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label class="form-label">{{ __('Current Password (to disable 2FA)') }}</label>
                                    <input type="password" name="password" class="form-control" required>
                                </div>
                                <div class="col-md-12 text-end">
                                    <button type="submit" class="btn btn-danger">{{ __('Disable 2FA') }}</button>
                                </div>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>
@endsection
