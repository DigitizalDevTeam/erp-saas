@extends('layouts.admin')
@section('page-title')
    {{ __('Treasury Dimensions') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury Dimensions') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('A payment that settles a bill inherits the bill’s cost centre split — the expense was recognised once, at the bill; the payment only moves cash, once. Receipts are tagged for cash-flow analysis only and never change an invoice or a balance.') }}</p>

                    <ul class="nav nav-tabs mb-3">
                        <li class="nav-item">
                            <a class="nav-link {{ $type === 'bill_payment' ? 'active' : '' }}" href="{{ route('treasury-dimensions.index', ['type' => 'bill_payment']) }}">{{ __('Bill payments') }}</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link {{ $type === 'receipt' ? 'active' : '' }}" href="{{ route('treasury-dimensions.index', ['type' => 'receipt']) }}">{{ __('Receipts') }}</a>
                        </li>
                    </ul>

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Payment') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th class="text-end">{{ __('Amount') }}</th>
                                    <th>{{ __('Dimensions') }}</th>
                                    <th>{{ __('Warnings') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($payments as $payment)
                                    @php
                                        $rows = $dimensionsByPayment[(int) $payment->id] ?? collect();
                                        $warnings = $warningsByPayment[(int) $payment->id] ?? [];
                                    @endphp
                                    <tr>
                                        <td>
                                            @if ($type === 'receipt')
                                                {{ __('Receipt') }} #{{ $payment->id }} — {{ __('Invoice') }} #{{ $payment->invoice_id }}
                                            @else
                                                {{ __('Payment') }} #{{ $payment->id }} — {{ __('Bill') }} #{{ $payment->bill_id }}
                                            @endif
                                        </td>
                                        <td>{{ $payment->date }}</td>
                                        <td class="text-end">{{ number_format((float) $payment->amount, 2) }}</td>
                                        <td>
                                            @forelse ($rows as $row)
                                                <div>{{ $row->costCentre ? $row->costCentre->code : '#' . $row->cost_centre_id }}:
                                                    {{ number_format((float) $row->allocated_amount, 2) }}
                                                    @if ($row->percentage !== null)
                                                        ({{ rtrim(rtrim((string) $row->percentage, '0'), '.') }}%)
                                                    @endif
                                                    <span class="badge bg-{{ $row->source === 'inherited' ? 'secondary' : 'info' }}">{{ $row->source === 'inherited' ? __('inherited') : __('manual') }}</span>
                                                </div>
                                            @empty
                                                <span class="text-muted">{{ __('No dimensions') }}</span>
                                            @endforelse
                                        </td>
                                        <td>
                                            @foreach ($warnings as $warning)
                                                <div class="text-warning"><small>{{ $warning }}</small></div>
                                            @endforeach
                                        </td>
                                        <td class="text-end">
                                            @if ($type === 'receipt')
                                                <a href="{{ route('treasury-dimensions.receipt.edit', $payment->id) }}" class="btn btn-sm btn-outline-primary">{{ __('Tag') }}</a>
                                            @else
                                                <a href="{{ route('treasury-dimensions.bill-payment.edit', $payment->id) }}" class="btn btn-sm btn-outline-primary">{{ __('Dimensions') }}</a>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center">{{ $type === 'receipt' ? __('No receipts yet.') : __('No bill payments yet.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $payments->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
