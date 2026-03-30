@extends('layouts.admin')

@section('page-title')
    {{ __('Analytical Reports') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Reports') }}</li>
    <li class="breadcrumb-item">{{ __('Analytical Reports') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="mb-2">{{ __('Excel-based analytical reports') }}</h5>
                    <p class="text-muted mb-3">{{ __('These reports follow the client Excel structure (PowerBlox).') }}</p>

                    <div class="list-group">
                        <a class="list-group-item list-group-item-action" href="{{ route('reports.analytical.powerblox') }}">
                            {{ __('PowerBlox - Management Pack') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

