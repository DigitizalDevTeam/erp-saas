@extends('layouts.admin')
@section('page-title')
    {{ __('POS Areas') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos.report') }}">{{ __('POS') }}</a></li>
    <li class="breadcrumb-item">{{ __('Areas') }}</li>
@endsection

@section('action-btn')
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="mb-4 d-flex justify-content-end">
                @can('manage pos')
                    <a href="{{ route('pos-area.create') }}" data-bs-toggle="tooltip" title="{{ __('Create') }}"
                        class="btn btn-sm btn-primary">
                        <i class="ti ti-plus"></i> {{ __('Add Area') }}
                    </a>
                @endcan
            </div>
            <div class="card">
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                                <tr>
                                    <th>{{ __('Branch') }}</th>
                                    <th>{{ __('Area') }}</th>
                                    <th>{{ __('Sort Order') }}</th>
                                    <th width="200px">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($areas as $area)
                                    <tr>
                                        <td>{{ $area->branch ? $area->branch->name : '-' }}</td>
                                        <td>{{ $area->name }}</td>
                                        <td>{{ $area->sort_order }}</td>
                                        <td>
                                            @can('manage pos')
                                                @if($area->created_by == \Auth::user()->creatorId())
                                                    <a href="{{ route('pos-area.edit', $area) }}" class="btn btn-sm btn-info" data-bs-toggle="tooltip" title="{{ __('Edit') }}"><i class="ti ti-pencil text-white"></i></a>
                                                    {!! Form::open(['method' => 'DELETE', 'route' => ['pos-area.destroy', $area->id], 'id' => 'delete-area-' . $area->id, 'class' => 'd-inline']) !!}
                                                    <a href="#" class="btn btn-sm btn-danger bs-pass-para" data-bs-toggle="tooltip" title="{{ __('Delete') }}"
                                                        data-confirm="{{ __('Are You Sure?') }}"
                                                        data-confirm-yes="document.getElementById('delete-area-{{ $area->id }}').submit();"><i class="ti ti-trash text-white"></i></a>
                                                    {!! Form::close() !!}
                                                @endif
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
