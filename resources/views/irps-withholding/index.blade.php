@extends('layouts.admin')

@section('page-title')
    {{ __('Manage IRPS Withholding') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('IRPS Withholding') }}</li>
@endsection

@section('action-btn')
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            @include('layouts.hrm_setup')
        </div>
        <div class="col-12">
            <div class="my-3 d-flex justify-content-end">
                @can('manage irps withholding')
                    <a href="#" data-url="{{ route('irps-withholding.create') }}" data-ajax-popup="true"
                        data-title="{{ __('Create New IRPS Withholding Rate') }}" data-bs-toggle="tooltip"
                        title="{{ __('Create') }}" class="btn btn-sm btn-primary">
                        <i class="ti ti-plus"></i>
                    </a>
                @endcan
            </div>
            <div class="row">
                <div class="col-sm-12">
                    <div class="card">
                        <div class="card-body table-border-style">
                            <div class="table-responsive">
                                <table class="table datatable">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Salary From') }}</th>
                                            <th>{{ __('Salary To') }}</th>
                                            <th>{{ __('Base (0 dependents)') }}</th>
                                            <th>{{ __('Base (1 dependent)') }}</th>
                                            <th>{{ __('Base (2 dependents)') }}</th>
                                            <th>{{ __('Base (3 dependents)') }}</th>
                                            <th>{{ __('Base (4+ dependents)') }}</th>
                                            <th>{{ __('Coefficient') }}</th>
                                            <th width="200px">{{ __('Action') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="font-style">
                                        @foreach ($rows as $row)
                                            <tr>
                                                <td>{{ \Auth::user()->priceFormat($row->salary_from) }}</td>
                                                <td>{{ $row->salary_to !== null ? \Auth::user()->priceFormat($row->salary_to) : '—' }}</td>
                                                <td>{{ $row->base_dep0 !== null ? \Auth::user()->priceFormat($row->base_dep0) : '—' }}</td>
                                                <td>{{ $row->base_dep1 !== null ? \Auth::user()->priceFormat($row->base_dep1) : '—' }}</td>
                                                <td>{{ $row->base_dep2 !== null ? \Auth::user()->priceFormat($row->base_dep2) : '—' }}</td>
                                                <td>{{ $row->base_dep3 !== null ? \Auth::user()->priceFormat($row->base_dep3) : '—' }}</td>
                                                <td>{{ $row->base_dep4plus !== null ? \Auth::user()->priceFormat($row->base_dep4plus) : '—' }}</td>
                                                <td>{{ $row->coefficient !== null ? number_format((float) $row->coefficient, 4) : '—' }}</td>
                                                <td>
                                                    @can('manage irps withholding')
                                                        <div class="action-btn me-2">
                                                            <a href="#" class="mx-3 btn btn-sm align-items-center bg-info"
                                                                data-url="{{ URL::to('irps-withholding/' . $row->id . '/edit') }}"
                                                                data-ajax-popup="true"
                                                                data-title="{{ __('Edit IRPS Withholding Rate') }}"
                                                                data-bs-toggle="tooltip" title="{{ __('Edit') }}"
                                                                data-original-title="{{ __('Edit') }}">
                                                                <i class="ti ti-pencil text-white"></i>
                                                            </a>
                                                        </div>
                                                    @endcan
                                                    @can('manage irps withholding')
                                                        <div class="action-btn ">
                                                            {!! Form::open([
                                                                'method' => 'DELETE',
                                                                'route' => ['irps-withholding.destroy', $row->id],
                                                                'id' => 'delete-form-' . $row->id,
                                                            ]) !!}
                                                            <a href="#"
                                                                class="mx-3 btn btn-sm align-items-center bs-pass-para bg-danger"
                                                                data-bs-toggle="tooltip" title="{{ __('Delete') }}"><i
                                                                    class="ti ti-trash text-white text-white"></i></a>
                                                            {!! Form::close() !!}
                                                        </div>
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
        </div>
    </div>
@endsection
