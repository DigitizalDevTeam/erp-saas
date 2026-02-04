@extends('layouts.auth')

@php
    use App\Models\Utility;
    $settings = Utility::settings();
    $languages = Utility::languages();
@endphp

@section('page-title')
    {{ __('Login') }}
@endsection

@push('custom-scripts')
    @if ($settings['recaptcha_module'] == 'on')
        {!! NoCaptcha::renderJs() !!}
    @endif
@endpush

@if ($settings['cust_darklayout'] == 'on')
<style>
    .g-recaptcha {
        filter: invert(1) hue-rotate(180deg) !important;
    }
</style>
@endif

@section('language-bar')
<div class="lang-dropdown-only-desk">
    <li class="dropdown dash-h-item drp-language">
        <a class="dash-head-link dropdown-toggle btn" href="#" data-bs-toggle="dropdown">
            <span class="drp-text">{{ $languages[$lang] }}</span>
        </a>
        <div class="dropdown-menu dash-h-dropdown dropdown-menu-end">
            @foreach ($languages as $code => $language)
                <a href="{{ route('login', $code) }}"
                   class="dropdown-item @if ($lang == $code) text-primary @endif">
                    {{ Str::upper($language) }}
                </a>
            @endforeach
        </div>
    </li>
</div>
@endsection

@section('content')
<style>
        .login-container {
            display: flex;
            border-radius: 50px;
            overflow: hidden;
        }

        .login-left {
            background: linear-gradient(135deg, #43b536 0%, #43b536 100%);
            color: white;
            flex: 0 0 40%;
            padding: 40px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
        }

        .login-left-content {
            width: 100%;
        }

        .login-left h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 15px;
            letter-spacing: -0.5px;
        }

        .login-left > p {
            font-size: 1.2rem;
            font-weight: 300;
            line-height: 1.5;
            margin-bottom: 50px;
            opacity: 0.95;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .feature-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }

        .feature-icon {
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }

        .feature-item p {
            font-size: 0.95rem;
            font-weight: 500;
            margin-bottom: 0px;
        }

        .login-left-footer {
            margin-top: auto;
            font-size: 0.9rem;
            opacity: 0.85;
        }

        .login-right {
            flex: 0 0 60%;
            background: white;
            padding: 40px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .login-form-wrapper {
            width: 100%;
            max-width: 400px;
        }

        .login-right-header {
            text-align: left;
            margin-bottom: 20px;
        }

        .login-right-header p {
            color: #666;
            font-size: 0.95rem;
            margin: 0;
            margin-top: 8px;
        }

        .login-right-header h2 {
            font-size: 2.2rem;
            font-weight: 700;
            color: #1a1a1a;
            margin: 0;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-control {
            border: 1px solid #ddd;
            padding: 12px 14px;
            font-size: 0.95rem;
            border-radius: 6px;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            border-color: #10a373;
            box-shadow: 0 0 0 0.2rem rgba(16, 163, 115, 0.1);
        }

        .form-control::placeholder {
            color: #999;
        }

        .form-check {
            margin-bottom: 15px;
        }

        .form-check-input {
            width: 18px;
            height: 18px;
            margin-top: 3px;
            border: 1px solid #ddd;
            cursor: pointer;
            background-color: white;
        }

        .form-check-input:checked {
            background-color: #10a373;
            border-color: #10a373;
        }

        .form-check-input:focus {
            border-color: #10a373;
            box-shadow: 0 0 0 0.2rem rgba(16, 163, 115, 0.1);
        }

        .form-check-label {
            margin-left: 8px;
            font-size: 0.95rem;
            color: #1a1a1a;
            cursor: pointer;
            user-select: none;
        }

        .btn-login {
            background-color: #6fd943;
            border: none;
            padding: 12px 20px;
            font-size: 1rem;
            font-weight: 600;
            color: white;
            border-radius: 6px;
            width: 100%;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .btn-login:hover {
            background-color: #6fd943;
            color: white;
        }

        .login-footer-link {
            text-align: center;
            margin-top: 20px;
        }

        .login-footer-link a {
            color: #10a373;
            text-decoration: none;
            font-size: 0.95rem;
            transition: color 0.3s;
        }

        .login-footer-link a:hover {
            color: #0d8b5f;
            text-decoration: underline;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .login-left {
                flex: 0 0 35%;
                padding: 20px 20px;
            }

            .login-right {
                flex: 0 0 65%;
                padding: 30px 30px;
            }

            .login-left h1 {
                font-size: 2.5rem;
            }

            .features-grid {
                gap: 10px;
                margin-bottom: 15px;
            }
        }

        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
            }

            .login-left {
                flex: 0 0 auto;
                padding: 20px 20px;
                min-height: 50vh;
            }

            .login-right {
                flex: 0 0 auto;
                padding: 20px 20px;
                min-height: 50vh;
            }

            .login-left h1 {
                font-size: 2rem;
            }

            .login-left > p {
                font-size: 1rem;
                margin-bottom: 30px;
            }

            .features-grid {
                gap: 8px;
                margin-bottom: 10px;
            }

            .feature-icon {
                width: 50px;
                height: 50px;
                font-size: 24px;
            }

            .login-form-wrapper {
                max-width: 100%;
            }
        }
        
        @media (max-width: 500px) {
            .login-left{
                display: none;
            }
        }
    </style>
    <div class="d-flex justify-content-center align-items-center">
        <div class="col-lg-8">
            <div class="login-container">
    
                {{-- LEFT PANEL --}}
                <div class="login-left">
                    <div class="login-left-content">
                        <h1 class="">{{ str_replace('_', ' ', config('app.name')) }}</h1>
                        <p>{{ __('Integrated management.') }}<br>{{ __('Real results.') }}</p>
    
                        <div class="features-grid">
                            <div class="feature-item"><i class="fas fa-briefcase feature-icon"></i><p>{{ __('Accounting') }}</p></div>
                            <div class="feature-item"><i class="fas fa-user feature-icon"></i><p>{{ __('HR') }}</p></div>
                            <div class="feature-item"><i class="fas fa-cube feature-icon"></i><p>{{ __('Projects') }}</p></div>
                            <div class="feature-item"><i class="fas fa-users feature-icon"></i><p>{{ __('CRM') }}</p></div>
                            <div class="feature-item"><i class="fas fa-shopping-cart feature-icon"></i><p>{{ __('Sales') }}</p></div>
                            <div class="feature-item"><i class="fas fa-calculator feature-icon"></i><p>{{ __('POS') }}</p></div>
                        </div>
    
                        <div class="login-left-footer">
                            <p>© {{ date('Y') }} {{ str_replace('_', ' ', config('app.name')) }}</p>
                        </div>
                    </div>
                </div>
    
                {{-- RIGHT PANEL --}}
                <div class="login-right">
                    <div class="login-form-wrapper">
    
                        <div class="login-right-header">
                            <p>{{ __('Welcome to') }}</p>
                            <h2>{{ str_replace('_', ' ', config('app.name')) }}</h2>
                            <p>{{ __('Log in to continue your business management session') }}</p>
                        </div>
    
                        {{ Form::open(['route' => 'login', 'method' => 'post', 'class'=>'needs-validation', 'novalidate']) }}
    
                        {{-- EMAIL --}}
                        <div class="form-group">
                            {{ Form::email('email', null, [
                                'class' => 'form-control',
                                'placeholder' => __('Email / User'),
                                'required'
                            ]) }}
                            @error('email')
                                <small class="text-danger">{{ $message }}</small>
                            @enderror
                        </div>
    
                        {{-- PASSWORD --}}
                        <div class="form-group">
                            {{ Form::password('password', [
                                'class' => 'form-control',
                                'placeholder' => __('Password'),
                                'required'
                            ]) }}
                            @error('password')
                                <small class="text-danger">{{ $message }}</small>
                            @enderror
                        </div>
    
                        {{-- REMEMBER ME --}}
                        <div class="form-check mb-3">
                            {{ Form::checkbox('remember', 1, true, ['class'=>'form-check-input','id'=>'rememberMe']) }}
                            <label class="form-check-label" for="rememberMe">
                                {{ __('Keep me logged in') }}
                            </label>
                        </div>
    
                        {{-- RECAPTCHA --}}
                        @if ($settings['recaptcha_module'] == 'on')
                            @if ($settings['google_recaptcha_version'] == 'v2-checkbox')
                                {!! NoCaptcha::display() !!}
                            @else
                                <input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">
                            @endif
                        @endif
    
                        {{-- SUBMIT --}}
                        <button type="submit" class="btn btn-primary w-100">{{ __('Login') }}</button>
    
                        {{-- FORGOT PASSWORD --}}
                        @if (Route::has('password.request'))
                            <div class="login-footer-link">
                                <a href="{{ route('password.request', $lang) }}">
                                    {{ __('Forgot your password?') }}
                                </a>
                            </div>
                        @endif
    
                        {{-- REGISTER --}}
                        @if ($settings['enable_signup'] == 'on')
                            <div class="login-footer-link mt-2">
                                <a href="{{ route('register', ['0', $lang]) }}">
                                    {{ __("Don't have an account? Register") }}
                                </a>
                            </div>
                        @endif
    
                        {{ Form::close() }}
                    </div>
                </div>
    
            </div>
        </div>
    </div>
@endsection
