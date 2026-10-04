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
    <a href="{{ route('pos-shift.report', ['pos_shift' => $pos_shift, 'print' => 'a4']) }}" target="_blank" rel="noopener" class="btn btn-sm btn-secondary"><i class="ti ti-file-text"></i> {{ __('Print A4') }}</a>
    <a href="{{ route('pos-shift.report', ['pos_shift' => $pos_shift, 'print' => '80']) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"><i class="ti ti-receipt"></i> {{ __('Print 80mm Slip') }}</a>
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
                    @if(!empty($cashInsTotal) && $cashInsTotal > 0)
                    <tr>
                        <td>{{ __('Cash in (top-ups)') }}</td>
                        <td class="text-end">{{ \Auth::user()->priceFormat($cashInsTotal) }}</td>
                    </tr>
                    @endif
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
                    @if(!empty($cashOutExpensesTotal) && $cashOutExpensesTotal > 0)
                    <tr>
                        <td>{{ __('Cash out (expenses)') }}</td>
                        <td class="text-end text-danger">-{{ \Auth::user()->priceFormat($cashOutExpensesTotal) }}</td>
                    </tr>
                    @endif
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

            <h6 class="border-bottom pb-2 mb-2 mt-4">{{ __('Products sold (Qty Summary)') }}</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>{{ __('Product') }}</th>
                            <th>{{ __('Table No') }}</th>
                            <th>{{ __('Waiter/User Name') }}</th>
                            <th class="text-end">{{ __('Price excl. tax') }}</th>
                            <th class="text-end">{{ __('Price incl. tax') }}</th>
                            <th class="text-center">{{ __('Qty') }}</th>
                            <th class="text-end">{{ __('Amount excl. tax') }}</th>
                            <th class="text-end">{{ __('VAT') }}</th>
                            <th class="text-end">{{ __('Amount incl. tax') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse(($productQtySummary ?? collect()) as $row)
                            <tr>
                                <td>{{ $row->product ? $row->product->name : __('Unknown product') }}</td>
                                <td>{{ $row->table_name ?: '—' }}</td>
                                <td>{{ $row->waiter_name ?: '—' }}</td>
                                <td class="text-end">{{ $row->exclusive_unit === null ? '—' : \Auth::user()->priceFormat((float) $row->exclusive_unit) }}</td>
                                <td class="text-end">{{ $row->inclusive_unit === null ? '—' : \Auth::user()->priceFormat((float) $row->inclusive_unit) }}</td>
                                <td class="text-center">{{ (float) $row->qty_total }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat((float) $row->exclusive_total) }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat((float) $row->tax_total) }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat((float) $row->inclusive_total) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted">{{ __('No sold products for this shift.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if(($productQtySummary ?? collect())->isNotEmpty())
                        <tfoot>
                            <tr class="fw-semibold">
                                <td colspan="5">{{ __('Total') }}</td>
                                <td class="text-center">{{ (float) $productQtySummary->sum('qty_total') }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat((float) $productQtySummary->sum('exclusive_total')) }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat((float) $productQtySummary->sum('tax_total')) }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat((float) $productQtySummary->sum('inclusive_total')) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>

            @foreach([
                ['title' => __('Sales'), 'rows' => $sales, 'empty' => __('No paid transactions for this shift.')],
                ['title' => __('Internal Consumption'), 'rows' => $internalConsumption ?? collect(), 'empty' => __('No internal consumption for this shift.')],
            ] as $section)
            <h6 class="border-bottom pb-2 mb-2 mt-4">{{ $section['title'] }} ({{ $section['rows']->count() }} {{ __('transactions') }})</h6>
            <div class="table-responsive">
                <table class="table table-sm table-bordered">
                    <thead>
                        <tr>
                            <th>{{ __('pos.pos_id') }}</th>
                            <th>{{ __('Date') }}</th>
                            <th>{{ __('Document Type') }}</th>
                            <th>{{ __('Payment Method') }}</th>
                            <th class="text-end">{{ __('Sub Total') }}</th>
                            <th class="text-end">{{ __('VAT') }}</th>
                            <th class="text-end">{{ __('Total incl. tax') }}</th>
                            <th class="text-end">{{ __('pos.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($section['rows'] as $pos)
                            <tr>
                                <td>{{ \Auth::user()->posNumberFormat($pos->pos_id) }}</td>
                                <td>{{ $pos->pos_date ?? '-' }}</td>
                                <td>{{ $pos->document_type_label }}</td>
                                <td>{{ $pos->payment_method_label !== '' ? $pos->payment_method_label : '—' }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat((float) $pos->exclusive_total) }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat((float) $pos->tax_total) }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat((float) $pos->inclusive_total) }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat((float) $pos->posPayments->sum('amount')) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted">{{ $section['empty'] }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($section['rows']->isNotEmpty())
                        <tfoot>
                            <tr class="fw-semibold">
                                <td colspan="4">{{ __('Total') }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat((float) $section['rows']->sum('exclusive_total')) }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat((float) $section['rows']->sum('tax_total')) }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat((float) $section['rows']->sum('inclusive_total')) }}</td>
                                <td class="text-end">{{ \Auth::user()->priceFormat((float) $section['rows']->sum(fn ($pos) => (float) $pos->posPayments->sum('amount'))) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            @endforeach

            @include('report.partials.pos_shift_min_stock_alert_card')
        </div>
    </div>
@endsection
