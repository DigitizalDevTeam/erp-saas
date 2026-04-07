@extends('layouts.admin')
@section('page-title')
    {{ __('Manage Expenses') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Expenses') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        @can('create expense')
            <a href="#" data-size="lg" data-url="{{ route('expenses.create') }}" data-ajax-popup="true" data-title="{{ __('Create Expense') }}" data-bs-toggle="tooltip" title="{{ __('Create') }}" class="btn btn-sm btn-primary">
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
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Category') }}</th>
                                    <th>{{ __('Expense account (GL)') }}</th>
                                    <th>{{ __('Branch') }}</th>
                                    <th>{{ __('Amount') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    @if(Gate::check('edit expense') || Gate::check('delete expense') || Gate::check('approve expense') || Gate::check('reject expense'))
                                        <th>{{ __('Action') }}</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $ulane = auth()->user()->lang ?? 'en';
                                    $name_column = match ($ulane) {
                                        'fr' => 'name_fr',
                                        'zh' => 'name_zh',
                                        'es' => 'name_es',
                                        'pt_br', 'pt-br' => 'name_pt_br',
                                        default => 'name',
                                    };
                                @endphp
                                @foreach ($expenses as $expense)
                                    <tr>
                                        <td>{{ $expense->name }}</td>
                                        <td>{{ $expense->date ? $expense->date->format('d M Y') : '-' }}</td>
                                        <td>{{ $expense->expenseCategory ? $expense->expenseCategory->name : '-' }}</td>
                                        <td>{{ $expense->chartAccount ? $expense->chartAccount->code . ' — ' . $expense->chartAccount->$name_column : '—' }}</td>
                                        <td>{{ $expense->branch ? $expense->branch->name : '-' }}</td>
                                        <td>{{ \Auth::user()->priceFormat($expense->amount) }}</td>
                                        <td>
                                            @if($expense->status == \App\Models\Expense::STATUS_PENDING)
                                                <span class="badge bg-warning">{{ __('Pending') }}</span>
                                            @elseif($expense->status == \App\Models\Expense::STATUS_APPROVED)
                                                <span class="badge bg-success">{{ __('Approve') }}</span>
                                            @else
                                                <span class="badge bg-danger">{{ __('Reject') }}</span>
                                            @endif
                                        </td>
                                        @if(Gate::check('edit expense') || Gate::check('delete expense') || Gate::check('approve expense') || Gate::check('reject expense'))
                                            <td>
                                                @can('approve expense')
                                                    @if($expense->status == \App\Models\Expense::STATUS_PENDING)
                                                        {!! Form::open(['method' => 'POST', 'route' => ['expenses.approve', $expense->id], 'id' => 'approve-form-' . $expense->id, 'class' => 'd-inline']) !!}
                                                        <a href="#" class="mx-1 btn btn-sm btn-success bs-pass-para" data-bs-toggle="tooltip" title="{{ __('Approve') }}" data-confirm="{{ __('Approve this expense?') }}" data-confirm-yes="document.getElementById('approve-form-{{ $expense->id }}').submit();"><i class="ti ti-check text-white"></i></a>
                                                        {!! Form::close() !!}
                                                    @endif
                                                @endcan
                                                @can('reject expense')
                                                    @if($expense->status == \App\Models\Expense::STATUS_PENDING)
                                                        {!! Form::open(['method' => 'POST', 'route' => ['expenses.reject', $expense->id], 'id' => 'reject-form-' . $expense->id, 'class' => 'd-inline']) !!}
                                                        <a href="#" class="mx-1 btn btn-sm btn-danger bs-pass-para" data-bs-toggle="tooltip" title="{{ __('Reject') }}" data-confirm="{{ __('Reject this expense?') }}" data-confirm-yes="document.getElementById('reject-form-{{ $expense->id }}').submit();"><i class="ti ti-x text-white"></i></a>
                                                        {!! Form::close() !!}
                                                    @endif
                                                @endcan
                                                @can('edit expense')
                                                    <div class="action-btn me-2 d-inline">
                                                        <a href="#" data-url="{{ route('expenses.edit', $expense->id) }}" data-size="lg" data-ajax-popup="true" data-title="{{ __('Edit Expense') }}" class="mx-1 btn btn-sm align-items-center bg-info" data-bs-toggle="tooltip" title="{{ __('Edit') }}"><i class="ti ti-pencil text-white"></i></a>
                                                    </div>
                                                @endcan
                                                @can('delete expense')
                                                    <div class="action-btn d-inline">
                                                        {!! Form::open(['method' => 'DELETE', 'route' => ['expenses.destroy', $expense->id], 'id' => 'delete-form-' . $expense->id]) !!}
                                                        <a href="#" class="mx-1 btn btn-sm align-items-center bs-pass-para bg-danger" data-bs-toggle="tooltip" title="{{ __('Delete') }}" data-confirm="{{ __('Are You Sure?') . '|' . __('This action can not be undone. Do you want to continue?') }}" data-confirm-yes="document.getElementById('delete-form-{{ $expense->id }}').submit();"><i class="ti ti-trash text-white"></i></a>
                                                        {!! Form::close() !!}
                                                    </div>
                                                @endcan
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
