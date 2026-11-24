@extends('layouts.admin')
@section('page-title')
    {{ __('Download Your Backup') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Settings') }}</li>
    <li class="breadcrumb-item">{{ __('Download Your Backup') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-3">{{ __('Download Your Backup') }}</h5>
                    <p class="text-muted">
                        {{ __('Download a complete SQL backup of your data — Sales, Products, Inventory, HRM and all other modules. The file contains only the data of the companies listed below. Data belonging to other companies is never included.') }}
                    </p>

                    <p class="mb-1"><strong>{{ __('Companies included in this backup') }}</strong></p>
                    @if ($companies->isEmpty())
                        <p class="text-danger">{{ __('No company is associated with your account, so this backup would be empty.') }}</p>
                    @else
                        <ul class="mb-4">
                            @foreach ($companies as $company)
                                <li>{{ $company->name }}</li>
                            @endforeach
                        </ul>
                    @endif

                    <a href="{{ route('company-backup.download') }}" class="btn btn-primary">
                        <i class="ti ti-download"></i> {{ __('Download Your Backup') }}
                    </a>
                    <p class="text-muted mt-3 mb-0">
                        {{ __('The backup is generated when you click the button and downloads as a .sql file. Depending on the size of your data, it may take a while — please keep this page open until the download starts.') }}
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
