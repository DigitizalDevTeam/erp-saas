@extends('layouts.admin')
@section('page-title')
    {{ __('Landing Page') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{__('Dashboard')}}</a></li>
    <li class="breadcrumb-item">{{__('Landing Page')}}</li>
@endsection

@php

    $settings = \Modules\LandingPage\Entities\LandingPageSetting::landingPageSetting();
    $logo=\App\Models\Utility::get_file('uploads/landing_page_image');
    $languages = \App\Models\Utility::languages();


@endphp



@push('script-page')
<script src="{{ asset('assets/js/jquery.repeater.min.js') }}"></script>
    <script>
        $(document).ready(function () {

            // Init repeater per language form
            $('[id^="imageUploadForm_"]').each(function () {
                $(this).find('[data-repeater-list]').repeater({
                    show: function () { $(this).slideDown(); },
                    hide: function (deleteElement) {
                        if (confirm('Delete this logo?')) $(this).slideUp(deleteElement);
                    }
                });
            });
        
        });
        
        // Banner preview per language
        document.addEventListener('change', function (e) {
            if (e.target.matches('input[type="file"][name^="home_banner"]')) {
                const lang = e.target.name.match(/\[(.*?)\]/)[1];
                document.getElementById('image_' + lang).src = URL.createObjectURL(e.target.files[0]);
            }
        });
        
        // Logo preview in repeater
        function updateImagePreview(input) {
            const img = input.closest('.row').querySelector('img');
            if (input.files[0]) img.src = URL.createObjectURL(input.files[0]);
        }
        
        // Delete existing logo
        document.addEventListener('click', function (e) {
            if (e.target.closest('.delete-button')) {
                const btn = e.target.closest('.delete-button');
                const lang = btn.dataset.lang;
                const image = btn.dataset.image;
        
                const input = document.getElementById('imageNames_' + lang);
                let arr = input.value ? input.value.split(',') : [];
                arr = arr.filter(i => i !== image);
                input.value = arr.join(',');
        
                btn.closest('.product_Image').remove();
            }
        });

    </script>
@endpush

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{__('Dashboard')}}</a></li>
    <li class="breadcrumb-item">{{__('Landing Page')}}</li>
@endsection


@section('content')
    <div class="row">
        <div class="col-sm-12">
            <div class="row">
                <div class="col-xl-3">
                    <div class="card sticky-top" style="top:30px">
                        <div class="list-group list-group-flush" id="useradd-sidenav">

                            @include('landingpage::layouts.tab')


                        </div>
                    </div>
                </div>

                <div class="col-xl-9">
                    {{--  Start for all settings tab --}}
                    @foreach($languages as $code => $language)
                        <?php
                           $home_offer_text = json_decode($settings['home_offer_text'] ?? '{}', true);
                           $home_title = json_decode($settings['home_title'] ?? '{}', true);
                           $home_heading = json_decode($settings['home_heading'] ?? '{}', true);
                           $home_trusted_by = json_decode($settings['home_trusted_by'] ?? '{}', true);
                           $home_description = json_decode($settings['home_description'] ?? '{}', true);
                           $home_live_demo_link = json_decode($settings['home_live_demo_link'] ?? '{}', true);
                           $home_buy_now_link = json_decode($settings['home_buy_now_link'] ?? '{}', true);
                           $home_banner = json_decode($settings['home_banner'] ?? '{}', true);
                           $home_logo_data = json_decode($settings['home_logo'] ?? '{}', true);
                        ?>
                        <div class="card">
                            <div class="card-header">
                                <div class="row">
                                    <div class="col-lg-10 col-md-10 col-sm-10">
                                        <h5>{{ __('Home Section') }} ({{ $language }})</h5>
                                    </div>
                                </div>
                            </div>

                            {{ Form::open(['route' => 'homesection.store', 'method' => 'post', 'enctype' => 'multipart/form-data' ,'id' => 'imageUploadForm_'.$code, 'class'=>'needs-validation', 'novalidate']) }}
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('Offer Text', __('Offer Text'), ['class' => 'form-label']) }}
                                                {{ Form::text('home_offer_text['.$code.']', $home_offer_text[$code] ?? '', ['class' => 'form-control', 'placeholder' => __('70% Special Offer')]) }}
                                                @error('mail_driver')
                                                    <span class="invalid-mail_driver" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('Title', __('Title'), ['class' => 'form-label']) }}
                                                {{ Form::text('home_title['.$code.']',$home_title[$code] ?? '', ['class' => 'form-control ', 'placeholder' => __('Enter Title')]) }}
                                                @error('mail_host')
                                                <span class="invalid-mail_driver" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('Heading', __('Heading'), ['class' => 'form-label']) }}
                                                {{ Form::text('home_heading['.$code.']',$home_heading[$code] ?? '', ['class' => 'form-control ', 'placeholder' => __('Enter Heading')]) }}
                                                @error('mail_host')
                                                <span class="invalid-mail_driver" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('Trusted by', __('Trusted by'), ['class' => 'form-label']) }}
                                                {{ Form::text('home_trusted_by['.$code.']', $home_trusted_by[$code] ?? '', ['class' => 'form-control', 'placeholder' => __('1,000+ customers')]) }}
                                                @error('mail_port')
                                                <span class="invalid-mail_port" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-md-12">
                                            <div class="form-group">
                                                {{ Form::label('Description', __('Description'), ['class' => 'form-label']) }}
                                                {{ Form::text('home_description['.$code.']', $home_description[$code] ?? '', ['class' => 'form-control', 'placeholder' => __('Enter Description')]) }}
                                                @error('mail_port')
                                                <span class="invalid-mail_port" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>


                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('Live Demo Link', __('Live Demo Link'), ['class' => 'form-label']) }}
                                                {{ Form::text('home_live_demo_link['.$code.']', $home_live_demo_link[$code] ?? '', ['class' => 'form-control', 'placeholder' => __('Enter Link')]) }}
                                                @error('mail_port')
                                                <span class="invalid-mail_port" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('Buy Now Link', __('Buy Now Link'), ['class' => 'form-label']) }}
                                                {{ Form::text('home_buy_now_link['.$code.']', $home_buy_now_link[$code] ?? '', ['class' => 'form-control', 'placeholder' => __('Enter Link')]) }}
                                                @error('mail_port')
                                                <span class="invalid-mail_port" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                {{ Form::label('Banner', __('Banner'), ['class' => 'form-label']) }}
                                                <div class="logo-content mt-4">
                                                    <img id="image_{{$code}}" src="{{ isset($home_banner[$code]) ? $logo.'/'.$home_banner[$code] : $logo.'/placeholder.png' }}"
                                                        class="big-logo img_setting">
                                                </div>
                                                <div class="choose-files mt-5">
                                                    <label for="home_banner">
                                                        <div class=" bg-primary company_logo_update" style="cursor: pointer;">
                                                            <i class="ti ti-upload px-1"></i>{{ __('Choose file here') }}
                                                        </div>
                                                        <input type="file" name="home_banner[{{$code}}]" id="home_banner[{{$code}}]" class="form-control file file-validate" data-filename="home_banner">
                                                    <p id="" class="file-error text-danger"></p>
                                                    </label>
                                                </div>
                                                @error('home_banner')
                                                    <div class="row">
                                                    <span class="invalid-logo" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                    </div>
                                                @enderror
                                            </div>
                                        </div>


                                        <div class="col-md-8">
                                            <div class="form-group">
                                                {{ Form::label('Logo', __('Logo'), ['class' => 'form-label']) }}


                                                <div class="text-end">
                                                <button class="btn btn-sm btn-primary btn-icon m-1 " data-repeater-create type="button" data-bs-toggle="tooltip" title="{{ __('Create') }}" data-title="{{ __('Create') }}"><i class="ti ti-plus"></i></button>
                                                </div>
                                                <div data-repeater-list="home_logo[{{$code}}]">
                                                    <div data-repeater-item class="text-end">
                                                        <div class="card mb-3 border shadow-none product_Image" >
                                                            <div class="px-2 py-2">
                                                                <div class="row align-items-center">
                                                                    <div class="col">
                                                                        <input type="file" name="file" class="form-control" accept="image/*" onchange="updateImagePreview(this)">

                                                                    </div>
                                                                    <div class="col-auto">
                                                                        <p class="card-text small text-muted">
                                                                            {{-- <img class="rounded" src="{{ $logo.'/placeholder.png' }}" width="70px" alt="Image placeholder" data-dz-thumbnail=""> --}}
                                                                            <img src="{{ $logo.'/home_logo.png' }}" width="70px" alt="Image placeholder" data-dz-thumbnail="">

                                                                        </p>
                                                                    </div>
                                                                    <div class="col-auto actions">
                                                                        <a data-repeater-delete href="javascript:void(0)" class="action-item btn btn-sm btn-icon btn-danger repeater-action-btn ms-2" data-bs-toggle="tooltip" title="{{ __('Delete') }}" data-title="{{ __('Delete') }}">
                                                                            <i class="ti ti-trash"></i>
                                                                        </a>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>

                                                    </div>

                                                </div>
                                            </div>

                                            @if(!empty($home_logo_data[$code]))
                                                <div id="imageContainer_{{$code}}">
                                                    @foreach ($home_logo_data[$code] as $logo_img)
                                                        <div class="card mb-3 border shadow-none product_Image">
                                                            <div class="px-2 py-2">
                                                                <div class="row align-items-center">
                                                                    <div class="col ml-n2">
                                                                        <p class="card-text small text-muted">
                                                                            <img src="{{ $logo.'/'.$logo_img }}" width="70px">
                                                                        </p>
                                                                    </div>
                                                                    <div class="col-auto actions">
                                                                        <a class="btn btn-sm btn-icon btn-primary" href="{{ $logo.'/'.$logo_img }}" download>
                                                                            <i class="ti ti-download text-white"></i>
                                                                        </a>
                                                                    </div>
                                                                    <div class="col-auto actions">
                                                                        <a class="btn btn-sm btn-icon btn-danger delete-button"
                                                                           data-image="{{ $logo_img }}" data-lang="{{$code}}">
                                                                            <i class="ti ti-trash text-white"></i>
                                                                        </a>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif

                                            <input type="hidden" id="imageNames_{{$code}}" name="savedlogo[{{$code}}]" value="{{ isset($home_logo_data[$code]) ? implode(',', $home_logo_data[$code]) : '' }}">
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer text-end">
                                    <input class="btn btn-print-invoice btn-primary m-r-10" type="submit" value="{{ __('Save Changes') }}">
                                </div>
                            {{ Form::close() }}
                        </div>
                    @endforeach

                    {{--  End for all settings tab --}}
                </div>
            </div>
        </div>
    </div>
@endsection



