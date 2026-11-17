@extends('layouts.admin')
@section('page-title')
    {{ __('Bank reconciliation') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Banking') }}</li>
    <li class="breadcrumb-item">{{ __('Bank reconciliation') }}</li>
@endsection
@section('action-btn')
    <div class="float-end">
        <a href="{{ route('bank-reconciliation.create') }}" class="btn btn-sm btn-primary">
            <i class="ti ti-upload"></i> {{ __('New import') }}
        </a>
    </div>
@endsection
@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>{{ __('ID') }}</th>
                                    <th>{{ __('Bank account') }}</th>
                                    <th>{{ __('Mapping') }}</th>
                                    <th>{{ __('Period') }}</th>
                                    <th>{{ __('Created') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($sessions as $s)
                                    <tr>
                                        <td>{{ $s->id }}</td>
                                        <td>{{ $s->bankAccount ? $s->bankAccount->bank_name . ' — ' . $s->bankAccount->holder_name : '-' }}
                                        </td>
                                        <td>
                                            @if ($s->statement_lines_count === 0)
                                                <span class="badge bg-warning text-dark">{{ __('Pending') }}</span>
                                            @else
                                                {{ config('bank_reconciliation.presets.' . $s->preset_key . '.label') ?: ucfirst(str_replace('_', ' ', $s->preset_key)) }}
                                            @endif
                                        </td>
                                        <td>
                                            @if ($s->period_from && $s->period_to)
                                                {{ $s->period_from }} → {{ $s->period_to }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ $s->created_at }}</td>
                                        <td class="text-end">
                                            @if ($s->statement_lines_count === 0)
                                                <a class="btn btn-sm btn-primary"
                                                    href="{{ route('bank-reconciliation.map', $s) }}">{{ __('Continue mapping') }}</a>
                                            @else
                                                <a class="btn btn-sm btn-outline-primary"
                                                    href="{{ route('bank-reconciliation.show', $s) }}">{{ __('Open') }}</a>
                                            @endif
                                            @if (!$s->isClosed())
                                                @can('manage transaction')
                                                    {!! Form::open(['method' => 'DELETE', 'route' => ['bank-reconciliation.destroy', $s->id], 'id' => 'delete-form-'.$s->id, 'class' => 'd-inline']) !!}
                                                    <a href="#" class="btn btn-sm align-items-center bs-pass-para bg-danger ms-1" data-bs-toggle="tooltip" title="{{ __('Delete') }}" data-confirm="{{ __('Are You Sure?').'|'.__('This action can not be undone. Do you want to continue?') }}" data-confirm-yes="document.getElementById('delete-form-{{ $s->id }}').submit();">
                                                        <i class="ti ti-trash text-white"></i>
                                                    </a>
                                                    {!! Form::close() !!}
                                                @endcan
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">{{ __('No imports yet.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $sessions->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
