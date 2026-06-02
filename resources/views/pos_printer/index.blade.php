@extends('layouts.admin')
@section('page-title')
    {{ __('Kitchen / Bar / Restaurant Printers') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS Shifts') }}</a></li>
    <li class="breadcrumb-item">{{ __('Printers') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    {{ Form::open(['route' => 'pos-printer.index', 'method' => 'get', 'class' => 'row g-2 align-items-end']) }}
                    <div class="col-auto">
                        {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }}
                        {{ Form::select('branch_id', $branches, $branchId, ['class' => 'form-control select', 'onchange' => 'this.form.submit()']) }}
                    </div>
                    <div class="col-auto">
                        <a href="{{ route('pos-printer.create') }}?branch_id={{ $branchId }}" class="btn btn-sm btn-primary"><i class="ti ti-plus"></i> {{ __('Add Printer') }}</a>
                    </div>
                    {{ Form::close() }}
                </div>
            </div>
            <div class="card">
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ __('Type') }}</th>
                                    <th>{{ __('Connection') }}</th>
                                    <th>{{ __('Sort') }}</th>
                                    <th width="120">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($printers as $p)
                                    <tr>
                                        <td>{{ $p->name }}</td>
                                        @php
                                            $typeLabels = ['kitchen' => __('Kitchen'), 'bar' => __('Bar'), 'restaurant' => __('Restaurant')];
                                            $typeClasses = ['kitchen' => 'bg-primary', 'bar' => 'bg-info', 'restaurant' => 'bg-success'];
                                            $typeKey = $p->type ?? 'kitchen';
                                        @endphp
                                        <td><span class="badge {{ $typeClasses[$typeKey] ?? 'bg-secondary' }}">{{ $typeLabels[$typeKey] ?? ucfirst($typeKey) }}</span></td>
                                        <td>{{ $p->connection === 'browser' ? __('Browser (print dialog)') : $p->connection }}</td>
                                        <td>{{ $p->sort_order }}</td>
                                        <td>
                                            <a href="{{ route('pos-printer.edit', $p) }}" class="btn btn-sm btn-info" data-bs-toggle="tooltip" title="{{ __('Edit') }}"><i class="ti ti-pencil text-white"></i></a>
                                            {!! Form::open(['method' => 'DELETE', 'route' => ['pos-printer.destroy', $p->id], 'id' => 'delete-printer-' . $p->id, 'class' => 'd-inline']) !!}
                                            <a href="#" class="btn btn-sm btn-danger bs-pass-para" data-bs-toggle="tooltip" title="{{ __('Delete') }}"
                                                data-confirm="{{ __('Are You Sure?') }}"
                                                data-confirm-yes="document.getElementById('delete-printer-{{ $p->id }}').submit();"><i class="ti ti-trash text-white"></i></a>
                                            {!! Form::close() !!}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">{{ __('No printers for this branch. Add one to use kitchen/bar/restaurant print routing.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
