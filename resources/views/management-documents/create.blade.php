@extends('layouts.admin')
@section('page-title')
    {{ __('Add Document') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('management-documents.index') }}">{{ __('Management Documents') }}</a></li>
    <li class="breadcrumb-item">{{ __('Add Document') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ route('management-documents.store') }}">
                        @include('management-documents._form')
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
