@extends('layouts.admin')

@section('page-title')
    {{ __('Road Transport Record') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Road Transport Record') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                {!! Form::open(['route' => 'road-transport-record.store', 'method' => 'post']) !!}
                <div class="card-body">
                    @if(!empty($roadManifest))
                        <input type="hidden" name="road_manifest_id" value="{{ $roadManifest->id }}">
                        <div class="alert alert-info">
                            {{ __('Linked Road Manifest') }}: {{ $roadManifest->manifest_no }}
                        </div>
                    @endif

                    <div class="row">
                        <div class="form-group col-md-4">
                            {{ Form::label('transport_date', __('Transport Date'), ['class' => 'form-label']) }}
                            {{ Form::date('transport_date', date('Y-m-d'), ['class' => 'form-control', 'required' => true]) }}
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('vehicle_no', __('Vehicle No'), ['class' => 'form-label']) }}
                            {{ Form::text('vehicle_no', null, ['class' => 'form-control']) }}
                        </div>
                        <div class="form-group col-md-4">
                            {{ Form::label('driver_name', __('Driver Name'), ['class' => 'form-label']) }}
                            {{ Form::text('driver_name', null, ['class' => 'form-control']) }}
                        </div>

                        <div class="form-group col-md-6">
                            {{ Form::label('from_location', __('From'), ['class' => 'form-label']) }}
                            {{ Form::text('from_location', null, ['class' => 'form-control']) }}
                        </div>
                        <div class="form-group col-md-6">
                            {{ Form::label('to_location', __('To'), ['class' => 'form-label']) }}
                            {{ Form::text('to_location', null, ['class' => 'form-control']) }}
                        </div>

                        <div class="form-group col-md-6">
                            {{ Form::label('departure_at', __('Departure At'), ['class' => 'form-label']) }}
                            <input type="datetime-local" name="departure_at" class="form-control">
                        </div>
                        <div class="form-group col-md-6">
                            {{ Form::label('arrival_at', __('Arrival At'), ['class' => 'form-label']) }}
                            <input type="datetime-local" name="arrival_at" class="form-control">
                        </div>

                        <div class="form-group col-md-12">
                            {{ Form::label('notes', __('Notes'), ['class' => 'form-label']) }}
                            {{ Form::textarea('notes', null, ['class' => 'form-control', 'rows' => 3]) }}
                        </div>
                    </div>
                </div>
                <div class="card-footer text-end">
                    <button type="submit" class="btn btn-primary btn-sm">{{ __('Save') }}</button>
                </div>
                {!! Form::close() !!}
            </div>
        </div>
    </div>
@endsection

