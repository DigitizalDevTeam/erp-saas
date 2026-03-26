@extends('layouts.admin')
@section('page-title')
    {{__('Warehouse Stock Details')}}
@endsection

@push('script-page')
@endpush
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{__('Dashboard')}}</a></li>
    <li class="breadcrumb-item">{{__('Warehouse Stock Details')}}</li>
@endsection
@section('action-btn')
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-body table-border-style">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6 class="mb-1">{{ $warehouse->name }}</h6>
                            <small class="text-muted">{{ $warehouse->address }}, {{ $warehouse->city }} {{ $warehouse->city_zip }}</small>
                        </div>
                        <div class="col-md-6 text-md-end">
                            <small class="text-muted">
                                {{ __('Allocated Users') }}:
                                @if(($assignedUsers ?? collect())->count() > 0)
                                    {{ $assignedUsers->pluck('name')->implode(', ') }}
                                @else
                                    -
                                @endif
                            </small>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table datatable">
                            <thead>
                            <tr>
                                <th>{{ __('Product') }}</th>
                                <th>{{ __('Quantity') }}</th>
                            </tr>
                            </thead>
                            <tbody>

                            @foreach ($warehouseProducts as $warehouses)
                                <tr class="font-style">
                                    @if(!empty($warehouses->product))
                                        <td>{{ !empty($warehouses->product)? $warehouses->product->name:'' }}</td>
                                        <td>{{ $warehouses->quantity }}</td>
                                    @endif
                                </tr>
                            @endforeach

                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0">{{ __('Warehouse Documentation') }}</h6>
                </div>
                <div class="card-body">
                    @can('edit warehouse')
                    {{ Form::open(['route' => ['warehouse.document.upload', $warehouse->id], 'files' => true, 'method' => 'post']) }}
                    <div class="row">
                        <div class="form-group col-md-4">
                            {{ Form::label('document_title', __('Title'), ['class' => 'form-label']) }}
                            {{ Form::text('document_title', null, ['class' => 'form-control']) }}
                        </div>
                        <div class="form-group col-md-6">
                            {{ Form::label('document_file', __('File'), ['class' => 'form-label']) }}
                            {{ Form::file('document_file', ['class' => 'form-control', 'required' => true]) }}
                        </div>
                        <div class="form-group col-md-2 d-flex align-items-end">
                            <button class="btn btn-primary w-100" type="submit">{{ __('Upload') }}</button>
                        </div>
                    </div>
                    {{ Form::close() }}
                    @endcan

                    <div class="table-responsive mt-3">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Title') }}</th>
                                    <th>{{ __('File') }}</th>
                                    <th>{{ __('Uploaded') }}</th>
                                    <th>{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($documents as $doc)
                                    <tr>
                                        <td>{{ $doc->title ?: '-' }}</td>
                                        <td>
                                            <a href="{{ asset(Storage::url('uploads/warehouse_documents/' . $doc->file_path)) }}" target="_blank">
                                                {{ $doc->file_path }}
                                            </a>
                                        </td>
                                        <td>{{ \Auth::user()->dateFormat($doc->created_at) }}</td>
                                        <td>
                                            @can('edit warehouse')
                                            {{ Form::open(['route' => ['warehouse.document.delete', $warehouse->id, $doc->id], 'method' => 'delete']) }}
                                            <button class="btn btn-sm btn-danger" type="submit">{{ __('Delete') }}</button>
                                            {{ Form::close() }}
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center text-muted">{{ __('No documents found.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>


@endsection

