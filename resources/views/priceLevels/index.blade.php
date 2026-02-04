@extends('layouts.admin')

@section('page-title')
    {{ __('Manage Price Levels') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Price Levels') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('create constant tax')
            <a href="#" data-url="{{ route('price-levels.create') }}" data-ajax-popup="true" data-title="{{ __('Create Price Level') }}" data-bs-toggle="tooltip" title="{{ __('Create') }}" class="btn btn-sm btn-primary">
                <i class="ti ti-plus"></i>
            </a>
        @endcan
    </div>
@endsection

@section('content')
    <div class="row">
        @include('layouts.account_setup')

        <div class="col-lg-9">
            <div class="card">
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                                <tr>
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ __('Type') }}</th>
                                    <th>{{ __('Value') }}</th>
                                    <th width="10%">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($priceLevels as $level)
                                    <tr class="font-style">
                                        <td>{{ $level->name }}</td>
                                        <td>{{ ucfirst($level->type) }}</td>
                                        <td>
                                            @if($level->type === 'percentage')
                                                {{ $level->value }}%
                                            @else
                                                {{ $level->value }}
                                            @endif
                                        </td>
                                        <td class="Action">
                                            <span>
                                                @can('edit constant tax')
                                                    <div class="action-btn me-2">
                                                        <a href="#" class="mx-3 btn btn-sm align-items-center bg-info"
                                                           data-url="{{ route('price-levels.edit', $level->id) }}"
                                                           data-ajax-popup="true"
                                                           data-title="{{ __('Edit Price Level') }}"
                                                           data-bs-toggle="tooltip" title="{{ __('Edit') }}">
                                                            <i class="ti ti-pencil text-white"></i>
                                                        </a>
                                                    </div>
                                                @endcan

                                                @can('delete constant tax')
                                                    <div class="action-btn">
                                                        {!! Form::open(['method' => 'DELETE', 'route' => ['price-levels.destroy', $level->id], 'id' => 'delete-form-'.$level->id]) !!}
                                                            <a href="#" class="mx-3 btn btn-sm align-items-center bs-pass-para bg-danger"
                                                               data-bs-toggle="tooltip"
                                                               title="{{ __('Delete') }}"
                                                               data-confirm="{{ __('Are You Sure?').'|'.__('This action can not be undone. Do you want to continue?') }}"
                                                               data-confirm-yes="document.getElementById('delete-form-{{ $level->id }}').submit();">
                                                                <i class="ti ti-trash text-white"></i>
                                                            </a>
                                                        {!! Form::close() !!}
                                                    </div>
                                                @endcan
                                            </span>
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
