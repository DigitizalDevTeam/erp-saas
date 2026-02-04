@extends('layouts.admin')
@section('page-title')
    {{ __('POS Tables') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos.report') }}">{{ __('POS') }}</a></li>
    <li class="breadcrumb-item">{{ __('Tables') }}</li>
@endsection

@section('action-btn')
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="mb-4 d-flex justify-content-end">
                @can('manage pos')
                    <a href="{{ route('pos-table.create') }}" data-bs-toggle="tooltip" title="{{ __('Create') }}"
                        class="btn btn-sm btn-primary">
                        <i class="ti ti-plus"></i> {{ __('Add Table') }}
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
                                    <th>{{ __('Table') }}</th>
                                    <th>{{ __('Capacity') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th width="200px">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tables as $table)
                                    <tr>
                                        <td>{{ $table->branch ? $table->branch->name : '-' }}</td>
                                        <td>{{ $table->area ? $table->area->name : '-' }}</td>
                                        <td>{{ $table->name }}</td>
                                        <td>{{ $table->capacity }}</td>
                                        <td>
                                            @if($table->status === 'available')
                                                <span class="badge bg-success">{{ __('Available') }}</span>
                                            @elseif($table->status === 'occupied')
                                                <span class="badge bg-warning">{{ __('Occupied') }}</span>
                                            @else
                                                <span class="badge bg-info">{{ __('Reserved') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @can('manage pos')
                                                @if($table->created_by == \Auth::user()->creatorId())
                                                    <a href="{{ route('pos-table.edit', $table) }}" class="btn btn-sm btn-info" data-bs-toggle="tooltip" title="{{ __('Edit') }}"><i class="ti ti-pencil text-white"></i></a>
                                                    {!! Form::open(['method' => 'DELETE', 'route' => ['pos-table.destroy', $table->id], 'id' => 'delete-table-' . $table->id, 'class' => 'd-inline']) !!}
                                                    <a href="#" class="btn btn-sm btn-danger bs-pass-para" data-bs-toggle="tooltip" title="{{ __('Delete') }}"
                                                        data-confirm="{{ __('Are You Sure?') }}"
                                                        data-confirm-yes="document.getElementById('delete-table-{{ $table->id }}').submit();"><i class="ti ti-trash text-white"></i></a>
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
