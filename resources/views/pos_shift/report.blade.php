@extends('layouts.admin')
@section('page-title')
    {{ __('Shift Report') }} #{{ $pos_shift->id }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS Shifts') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.show', $pos_shift) }}">{{ __('Shift') }} #{{ $pos_shift->id }}</a></li>
    <li class="breadcrumb-item">{{ __('Report') }}</li>
@endsection

@section('action-btn')
    <button type="button" class="btn btn-sm btn-primary" onclick="window.print();"><i class="ti ti-printer"></i> {{ __('Print') }}</button>
    <a href="{{ route('pos-shift.show', $pos_shift) }}" class="btn btn-sm btn-secondary">{{ __('Back') }}</a>
@endsection

@section('content')
    <div id="shift-report-printable" class="card">
        <div class="card-header">
            <h5 class="mb-0">{{ __('Shift Report') }} — {{ $pos_shift->branch ? $pos_shift->branch->name : '-' }} / {{ $pos_shift->user ? $pos_shift->user->name : '-' }}</h5>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-6">
                    <p class="mb-1"><strong>{{ __('Branch') }}:</strong> {{ $pos_shift->branch ? $pos_shift->branch->name : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('User') }}:</strong> {{ $pos_shift->user ? $pos_shift->user->name : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('Opened At') }}:</strong> {{ $pos_shift->opened_at ? $pos_shift->opened_at->format('Y-m-d H:i') : '-' }}</p>
                    <p class="mb-1"><strong>{{ __('Opening Balance') }}:</strong> {{ \Auth::user()->priceFormat($pos_shift->opening_balance ?? 0) }}</p>
                </div>
                <div class="col-md-6">
                    <p class="mb-1"><strong>{{ __('Status') }}:</strong> {{ $pos_shift->status === 'open' ? __('Open') : __('Closed') }}</p>
                    @if($pos_shift->closed_at)
                        <p class="mb-1"><strong>{{ __('Closed At') }}:</strong> {{ $pos_shift->closed_at->format('Y-m-d H:i') }}</p>
                        <p class="mb-1"><strong>{{ __('Closing Balance') }}:</strong> {{ \Auth::user()->priceFormat($pos_shift->closing_balance ?? 0) }}</p>
                    @endif
                    <p class="mb-1"><strong>{{ __('Sales Total') }}:</strong> {{ \Auth::user()->priceFormat($salesTotal) }}</p>
                </div>
            </div>

            <h6 class="border-bottom pb-2 mb-2">{{ __('Cash reconciliation') }}</h6>
            <table class="table table-sm table-bordered mb-3">
                <tbody>
                    <tr>
                        <td>{{ __('Opening balance') }}</td>
                        <td class="text-end">{{ \Auth::user()->priceFormat($pos_shift->opening_balance ?? 0) }}</td>
                    </tr>
                    <tr>
                        <td>{{ __('Cash in (POS sales)') }}@if(!empty($balanceCash) && $balanceCash > 0)<span class="text-muted small"> + {{ __('balance invoice payments') }}</span>@endif</td>
                        <td class="text-end">{{ \Auth::user()->priceFormat($cashTotal ?? 0) }}</td>
                    </tr>
                    @if(!empty($balanceCash) && $balanceCash > 0)
                    <tr>
                        <td class="ps-4 small text-muted">{{ __('Of which balance / invoice payments (cash)') }}</td>
                        <td class="text-end small text-muted">{{ \Auth::user()->priceFormat($balanceCash) }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td>{{ __('Cash drops (spent / out)') }}</td>
                        <td class="text-end text-danger">-{{ \Auth::user()->priceFormat($cashDropsTotal ?? 0) }}</td>
                    </tr>
                    <tr class="fw-bold">
                        <td>{{ __('Expected cash in drawer') }}</td>
                        <td class="text-end">{{ \Auth::user()->priceFormat($expectedCash ?? 0) }}</td>
                    </tr>
                    @if($pos_shift->closed_at)
                    <tr>
                        <td>{{ __('Closing balance (counted)') }}</td>
                        <td class="text-end">{{ \Auth::user()->priceFormat($pos_shift->closing_balance ?? 0) }}</td>
                    </tr>
                    <tr>
                        <td>{{ __('Difference (over / short)') }}</td>
                        <td class="text-end">{{ \Auth::user()->priceFormat($pos_shift->difference ?? 0) }}</td>
                    </tr>
                    @endif
                </tbody>
            </table>

            <h6 class="border-bottom pb-2 mb-2">{{ __('Payment Summary') }}</h6>
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>{{ __('Payment Method') }}</th>
                        <th class="text-end">{{ __('Amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>{{ __('pos.payment_cash') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($cashTotal) }}</td></tr>
                    <tr><td>{{ __('Card / M-Pesa / Bank transfer') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($cardTotal) }}</td></tr>
                    <tr><td>{{ __('pos.payment_credit') }}</td><td class="text-end">{{ \Auth::user()->priceFormat($creditTotal) }}</td></tr>
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td>{{ __('Total') }}</td>
                        <td class="text-end">{{ \Auth::user()->priceFormat($cashTotal + $cardTotal + $creditTotal) }}</td>
                    </tr>
                </tfoot>
            </table>
            <h6 class="border-bottom pb-2 mb-2 mt-3">{{ __('Cash drops (detail)') }}</h6>
            <p class="mb-1"><strong>{{ __('Total spent / withdrawn') }}:</strong> {{ \Auth::user()->priceFormat($cashDropsTotal ?? 0) }}</p>
            <table class="table table-sm table-bordered">
                <thead>
                    <tr>
                        <th>{{ __('Date') }}</th>
                        <th class="text-end">{{ __('Amount') }}</th>
                        <th>{{ __('Reason') }}</th>
                        <th>{{ __('By') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pos_shift->cashDrops ?? [] as $drop)
                        <tr>
                            <td>{{ $drop->created_at ? $drop->created_at->format('Y-m-d H:i') : '-' }}</td>
                            <td class="text-end">{{ \Auth::user()->priceFormat($drop->amount) }}</td>
                            <td>{{ $drop->reason ?: '—' }}</td>
                            <td>{{ $drop->user ? $drop->user->name : '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-muted text-center">{{ __('No cash drops recorded for this shift.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if(isset($voidedLines) && $voidedLines->isNotEmpty())
            <h6 class="border-bottom pb-2 mb-2 mt-4">{{ __('pos.void_summary') }} ({{ $voidedLines->count() }})</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>{{ __('pos.pos_id') }}</th>
                            <th>{{ __('Product') }}</th>
                            <th class="text-center">{{ __('Qty') }}</th>
                            <th class="text-end">{{ __('Amount') }}</th>
                            <th>{{ __('Reason') }}</th>
                            <th>{{ __('Voided at') }}</th>
                            <th>{{ __('By') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($voidedLines as $line)
                            <tr>
                                <td>{{ $line->pos ? \Auth::user()->posNumberFormat($line->pos->pos_id) : '-' }}</td>
                                <td>{{ $line->product ? $line->product->name : '-' }}</td>
                                <td class="text-center">{{ $line->quantity }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat($line->price * $line->quantity) }}</td>
                                <td>{{ $line->void_reason ?: '—' }}</td>
                                <td>{{ $line->voided_at ? $line->voided_at->format('Y-m-d H:i') : '-' }}</td>
                                <td>{{ $line->voidedByUser ? $line->voidedByUser->name : '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif

            <h6 class="border-bottom pb-2 mb-2 mt-4">{{ __('Sales') }} ({{ $sales->count() }} {{ __('transactions') }})</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>{{ __('pos.pos_id') }}</th>
                            <th>{{ __('Date') }}</th>
                            <th class="text-end">{{ __('pos.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sales as $pos)
                            <tr>
                                <td>{{ \Auth::user()->posNumberFormat($pos->pos_id) }}</td>
                                <td>{{ $pos->pos_date ?? '-' }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat($pos->getTotal()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
