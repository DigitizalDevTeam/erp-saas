@extends('layouts.admin')
@section('page-title')
    {{ __('Treasury Approvals') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item">{{ __('Approvals') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @if ($steps->isEmpty())
                        <p class="text-muted">{{ __('Nothing is waiting for your approval.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Reference') }}</th>
                                        <th>{{ __('Beneficiary') }}</th>
                                        <th>{{ __('Amount') }}</th>
                                        <th>{{ __('Level') }}</th>
                                        <th>{{ __('Votes') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($steps as $step)
                                        <tr>
                                            <td>{{ $step->request->operation->reference ?? __('Draft') }}</td>
                                            <td>{{ ucfirst($step->request->beneficiary_type) }} #{{ $step->request->beneficiary_id }}</td>
                                            <td>{{ number_format((float) $step->request->amount_original, 2) }} {{ $step->request->currency }}</td>
                                            <td>{{ $step->level }}</td>
                                            <td>{{ $step->decisions->where('decision', 'approve')->unique('actor_id')->count() }} / {{ $step->required_votes }}</td>
                                            <td class="text-end">
                                                <a href="{{ route('treasury-payment-requests.show', $step->request) }}" class="btn btn-sm btn-outline-primary">{{ __('Review') }}</a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
