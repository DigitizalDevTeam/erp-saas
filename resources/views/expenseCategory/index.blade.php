@extends('layouts.admin')

@section('page-title')
    {{__('Manage Expense Category')}}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{route('dashboard')}}">{{__('Dashboard')}}</a></li>
    <li class="breadcrumb-item">{{__('Expense Category')}}</li>
@endsection

@section('action-btn')
    <div class="float-end">
    @can('create expense category')
        <a href="#" data-size="lg" data-url="{{ route('expense-category.create') }}" data-ajax-popup="true" data-title="{{__('Create Expense Category')}}" data-bs-toggle="tooltip" title="{{__('Create')}}"  class="btn btn-sm btn-primary">
            <i class="ti ti-plus"></i>
        </a>
    @endcan
    </div>
@endsection


@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
            <div class="card-body table-border-style">
                    <div class="table-responsive">
                    <table class="table datatable">
                            <thead>
                            <tr>
                                <th>{{__('Name')}}</th>
                                @if(Gate::check('edit expense category') || Gate::check('delete expense category'))
                                    <th>{{__('Action')}}</th>
                                @endif
                            </tr>
                            </thead>
                            <tbody class="font-style">
                            @foreach ($ExpenseCategories as $ExpenseCategory)
                                <tr>
                                    <td>{{ $ExpenseCategory->name }}</td>
                                    @if(Gate::check('edit expense category') || Gate::check('delete expense category'))
                                        <td>
                                            @can('edit expense category')
                                            <div class="action-btn me-2">
                                                <a href="#" data-url="{{ route('expense-category.edit',$ExpenseCategory->id)}}" data-size="lg" data-ajax-popup="true" data-title="{{__('Edit Expense Category')}}" class="mx-3 btn btn-sm align-items-center bg-info" data-bs-toggle="tooltip" title="{{__('Edit')}}" data-original-title="{{__('Edit')}}"><i class="ti ti-pencil text-white"></i></a>
                                            </div>
                                                @endcan
                                            @can('delete expense category')
                                            <div class="action-btn ">
                                            {!! Form::open(['method' => 'DELETE', 'route' => ['expense-category.destroy', $ExpenseCategory->id],'id'=>'delete-form-'.$ExpenseCategory->id]) !!}

                                                <a href="#" class="mx-3 btn btn-sm align-items-center bs-pass-para bg-danger" data-bs-toggle="tooltip" title="{{__('Delete')}}" data-original-title="{{__('Delete')}}" data-confirm="{{__('Are You Sure?').'|'.__('This action can not be undone. Do you want to continue?')}}" data-confirm-yes="document.getElementById('delete-form-{{$ExpenseCategory->id}}').submit();"><i class="ti ti-trash text-white"></i></a>
                                                {!! Form::close() !!}
                                            </div>
                                            @endif
                                        </td>
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
@endsection
