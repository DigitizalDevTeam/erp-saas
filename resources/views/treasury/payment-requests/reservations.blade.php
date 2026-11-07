@extends('layouts.admin')
@section('page-title')
    {{ __('Treasury Fund Reservations') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item">{{ __('Reservations') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <form method="GET" action="{{ route('treasury-payment-requests.reservations') }}" class="row g-2 mb-3">
                        <div class="col-md-3">
                            <select name="state" class="form-control" onchange="this.form.submit()">
                                <option value="">{{ __('All states') }}</option>
                                @foreach (['active', 'consumed', 'released', 'expired'] as $state)
                                    <option value="{{ $state }}" {{ request('state') === $state ? 'selected' : '' }}>{{ ucfirst($state) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </form>

                    @if ($reservations->isEmpty())
                        <p class="text-muted">{{ __('No fund reservations yet. A reservation is created when a payment request is approved, if reservations are configured. A reservation is never a posting — it only reduces the available balance.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Account') }}</th>
                                        <th>{{ __('Request') }}</th>
                                        <th>{{ __('Amount') }}</th>
                                        <th>{{ __('State') }}</th>
                                        <th>{{ __('Expires') }}</th>
                                        <th>{{ __('Consumed') }}</th>
                                        <th>{{ __('Released') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($reservations as $reservation)
                                        <tr>
                                            <td>{{ $reservation->account->code ?? '—' }}</td>
                                            <td>
                                                @if ($reservation->request)
                                                    <a href="{{ route('treasury-payment-requests.show', $reservation->request) }}">{{ $reservation->request->operation->reference ?? __('Draft') }}</a>
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td>{{ number_format((float) $reservation->amount, 2) }} {{ $reservation->currency }}</td>
                                            <td><span class="badge bg-{{ $reservation->state === 'active' ? 'warning' : ($reservation->state === 'consumed' ? 'success' : 'secondary') }}">{{ ucfirst($reservation->state) }}</span></td>
                                            <td>{{ $reservation->expires_at ? $reservation->expires_at->format('Y-m-d H:i') : '—' }}</td>
                                            <td>{{ number_format((float) $reservation->consumed_amount, 2) }}</td>
                                            <td>{{ number_format((float) $reservation->released_amount, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $reservations->appends(request()->query())->links() }}
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
