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
                                    <th>{{ __('Preset') }}</th>
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
                                        <td>{{ config('bank_reconciliation.presets.' . $s->preset_key . '.label', $s->preset_key) }}
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
                                            <a class="btn btn-sm btn-outline-primary"
                                                href="{{ route('bank-reconciliation.show', $s) }}">{{ __('Open') }}</a>
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
