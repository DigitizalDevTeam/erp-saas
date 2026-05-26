@extends('layouts.admin')
@section('page-title')
    {{ __('pos.shift_report') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS') }}</a></li>
    <li class="breadcrumb-item">{{ __('pos.shift_report') }}</li>
@endsection

@section('action-btn')
    <button type="button" class="btn btn-sm btn-primary" onclick="window.print();"><i class="ti ti-printer"></i> {{ __('Print') }}</button>
    <a href="{{ route('report.pos.shift.export', request()->query()) }}" class="btn btn-sm btn-success"><i class="ti ti-file-export"></i> {{ __('Export Excel') }}</a>
@endsection

@push('css-page')
<style>
    @media print {
        .no-print { display: none !important; }
    }
    .pos-shift-summary .card { min-height: 100%; }
</style>
@endpush

@section('content')
    <div id="pos-shift-report-printable">
        <div class="card mb-3 no-print">
            <div class="card-body">
                {{ Form::open(['route' => 'report.pos.shift', 'method' => 'get', 'id' => 'pos-shift-report-filter']) }}
                <div class="row g-2 align-items-end">
                    <div class="col-md-6 col-lg-3">
                        {{ Form::label('start_date', __('Start Date'), ['class' => 'form-label']) }}
                        {{ Form::text('start_date', $filter['start_date'], ['class' => 'form-control flatpickr-to-input']) }}
                    </div>
                    <div class="col-md-6 col-lg-3">
                        {{ Form::label('end_date', __('End Date'), ['class' => 'form-label']) }}
                        {{ Form::text('end_date', $filter['end_date'], ['class' => 'form-control flatpickr-to-input']) }}
                    </div>
                    <div class="col-md-6 col-lg-3">
                        {{ Form::label('branch_id', __('Branch'), ['class' => 'form-label']) }}
                        {{ Form::select('branch_id', $filterOptions['branches'], $filter['branch_id'], ['class' => 'form-control select']) }}
                    </div>
                    <div class="col-md-6 col-lg-3">
                        {{ Form::label('warehouse_id', __('Warehouse'), ['class' => 'form-label']) }}
                        {{ Form::select('warehouse_id', $filterOptions['warehouses'], $filter['warehouse_id'], ['class' => 'form-control select']) }}
                    </div>
                    <div class="col-md-6 col-lg-3">
                        {{ Form::label('product_id', __('Product'), ['class' => 'form-label']) }}
                        {{ Form::select('product_id', $filterOptions['products'], $filter['product_id'], ['class' => 'form-control select']) }}
                    </div>
                    <div class="col-md-6 col-lg-3">
                        {{ Form::label('shift_user_id', __('pos.shift_user'), ['class' => 'form-label']) }}
                        {{ Form::select('shift_user_id', $filterOptions['users'], $filter['shift_user_id'], ['class' => 'form-control select']) }}
                    </div>
                    <div class="col-md-6 col-lg-3">
                        {{ Form::label('pos_user_id', __('pos.sale_operator'), ['class' => 'form-label']) }}
                        {{ Form::select('pos_user_id', $filterOptions['users'], $filter['pos_user_id'], ['class' => 'form-control select']) }}
                    </div>
                    <div class="col-md-6 col-lg-3">
                        {{ Form::label('shift_status', __('Status'), ['class' => 'form-label']) }}
                        {{ Form::select('shift_status', $filterOptions['shift_statuses'], $filter['shift_status'], ['class' => 'form-control select']) }}
                    </div>
                    <div class="col-md-6 col-lg-3">
                        {{ Form::label('order_type', __('pos.order_type'), ['class' => 'form-label']) }}
                        {{ Form::select('order_type', $filterOptions['order_types'], $filter['order_type'], ['class' => 'form-control select']) }}
                    </div>
                    <div class="col-md-6 col-lg-3">
                        {{ Form::label('payment_method', __('Payment Method'), ['class' => 'form-label']) }}
                        {{ Form::select('payment_method', $filterOptions['payment_methods'], $filter['payment_method'], ['class' => 'form-control select']) }}
                    </div>
                    <div class="col-md-6 col-lg-3 d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
                        <a href="{{ route('report.pos.shift') }}" class="btn btn-outline-secondary">{{ __('Reset') }}</a>
                    </div>
                </div>
                {{ Form::close() }}
            </div>
        </div>

        <div class="row g-3 mb-3 pos-shift-summary">
            <div class="col-6 col-md-4 col-lg-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body py-2">
                        <div class="text-muted small">{{ __('Shifts') }}</div>
                        <div class="h5 mb-0">{{ $summary['shift_count'] }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body py-2">
                        <div class="text-muted small">{{ __('Sales Total') }}</div>
                        <div class="h6 mb-0">{{ Auth::user()->priceFormat($summary['sales_total']) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body py-2">
                        <div class="text-muted small">{{ __('pos.payment_cash') }}</div>
                        <div class="h6 mb-0">{{ Auth::user()->priceFormat($summary['cash_total']) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body py-2">
                        <div class="text-muted small">{{ __('Card') }}</div>
                        <div class="h6 mb-0">{{ Auth::user()->priceFormat($summary['card_total']) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body py-2">
                        <div class="text-muted small">{{ __('pos.payment_credit') }}</div>
                        <div class="h6 mb-0">{{ Auth::user()->priceFormat($summary['credit_total']) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="card border-0 shadow-sm">
                    <div class="card-body py-2">
                        <div class="text-muted small">{{ __('Cash drops') }}</div>
                        <div class="h6 mb-0">{{ Auth::user()->priceFormat($summary['cash_drops_total']) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">{{ __('Shifts') }} ({{ $shiftIds->count() }})</h5></div>
            <div class="card-body table-border-style">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>{{ __('Shift') }}</th>
                                <th>{{ __('Branch') }}</th>
                                <th>{{ __('pos.shift_user') }}</th>
                                <th>{{ __('Opened At') }}</th>
                                <th>{{ __('Closed At') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th class="text-end">{{ __('Opening Balance') }}</th>
                                <th class="text-end">{{ __('Sales Total') }}</th>
                                <th class="text-end">{{ __('Cash') }}</th>
                                <th class="text-end">{{ __('Card') }}</th>
                                <th class="text-end">{{ __('Credit') }}</th>
                                <th class="text-end">{{ __('Expected cash') }}</th>
                                <th class="text-end">{{ __('Difference') }}</th>
                                <th class="no-print">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($shiftRows as $row)
                                @php $shift = $row['shift']; $m = $row['metrics']; @endphp
                                <tr>
                                    <td>#{{ $shift->id }}</td>
                                    <td>{{ $shift->branch ? $shift->branch->name : '—' }}</td>
                                    <td>{{ $shift->user ? $shift->user->name : '—' }}</td>
                                    <td>{{ $shift->opened_at ? $shift->opened_at->format('Y-m-d H:i') : '—' }}</td>
                                    <td>{{ $shift->closed_at ? $shift->closed_at->format('Y-m-d H:i') : '—' }}</td>
                                    <td>
                                        @if($shift->status === 'open')
                                            <span class="badge bg-success">{{ __('Open') }}</span>
                                        @else
                                            <span class="badge bg-secondary">{{ __('Closed') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($shift->opening_balance ?? 0) }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($m['salesTotal']) }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($m['cashTotal']) }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($m['cardTotal']) }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($m['creditTotal']) }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($m['expectedCash']) }}</td>
                                    <td class="text-end">
                                        @if($shift->status === 'closed')
                                            {{ Auth::user()->priceFormat($shift->difference ?? 0) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="no-print">
                                        <a href="{{ route('pos-shift.report', $shift) }}" class="btn btn-sm btn-primary" target="_blank" title="{{ __('Report') }}"><i class="ti ti-file-text"></i></a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="14" class="text-center text-muted">{{ __('No data in this period') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-end mt-2 no-print">
                    {{ $shifts->links() }}
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h6 class="mb-0">{{ __('Payment Summary') }}</h6></div>
                    <div class="card-body table-border-style">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('Payment Method') }}</th>
                                    <th class="text-end">{{ __('Amount') }}</th>
                                    <th class="text-center">{{ __('Count') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($aggregates['paymentsByMethod'] ?? [] as $pm)
                                    <tr>
                                        <td>{{ $pm['name'] }}</td>
                                        <td class="text-end">{{ Auth::user()->priceFormat($pm['amount']) }}</td>
                                        <td class="text-center">{{ $pm['count'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted text-center">—</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h6 class="mb-0">{{ __('Products sold (Qty Summary)') }}</h6></div>
                    <div class="card-body table-border-style">
                        <div class="table-responsive" style="max-height: 320px;">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ __('Product') }}</th>
                                        <th class="text-center">{{ __('Qty') }}</th>
                                        <th class="text-end">{{ __('Amount') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($aggregates['products'] ?? [] as $p)
                                        <tr>
                                            <td>{{ $p['name'] }}</td>
                                            <td class="text-center">{{ number_format($p['quantity'], 2) }}</td>
                                            <td class="text-end">{{ Auth::user()->priceFormat($p['amount']) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="3" class="text-muted text-center">—</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if(($aggregates['voidedCount'] ?? 0) > 0)
        <div class="card mt-3">
            <div class="card-header">
                <h6 class="mb-0">{{ __('pos.void_summary') }} ({{ $aggregates['voidedCount'] }})</h6>
            </div>
            <div class="card-body table-border-style">
                <div class="table-responsive">
                    <table class="table table-sm">
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
                            @foreach($aggregates['voidedLines'] as $line)
                                <tr>
                                    <td>{{ $line->pos ? Auth::user()->posNumberFormat($line->pos->pos_id) : '—' }}</td>
                                    <td>{{ $line->product ? $line->product->name : '—' }}</td>
                                    <td class="text-center">{{ $line->quantity }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($line->price * $line->quantity) }}</td>
                                    <td>{{ $line->void_reason ?: '—' }}</td>
                                    <td>{{ $line->voided_at ? $line->voided_at->format('Y-m-d H:i') : '—' }}</td>
                                    <td>{{ $line->voidedByUser ? $line->voidedByUser->name : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif

        <div class="card mt-3">
            <div class="card-header"><h6 class="mb-0">{{ __('Cash movements') }}</h6></div>
            <div class="card-body">
                <h6 class="small text-muted">{{ __('Cash drops (detail)') }}</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr>
                                <th>{{ __('Shift') }}</th>
                                <th>{{ __('Branch') }}</th>
                                <th>{{ __('Date') }}</th>
                                <th class="text-end">{{ __('Amount') }}</th>
                                <th>{{ __('Reason') }}</th>
                                <th>{{ __('By') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($aggregates['cashDrops'] ?? [] as $drop)
                                <tr>
                                    <td>#{{ $drop->shift_id }}</td>
                                    <td>{{ $drop->shift && $drop->shift->branch ? $drop->shift->branch->name : '—' }}</td>
                                    <td>{{ $drop->created_at ? $drop->created_at->format('Y-m-d H:i') : '—' }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($drop->amount) }}</td>
                                    <td>{{ $drop->reason ?: '—' }}</td>
                                    <td>{{ $drop->user ? $drop->user->name : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-muted text-center">{{ __('No cash drops recorded for this shift.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <h6 class="small text-muted">{{ __('Cash in (top-ups)') }}</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr>
                                <th>{{ __('Shift') }}</th>
                                <th>{{ __('Date') }}</th>
                                <th class="text-end">{{ __('Amount') }}</th>
                                <th>{{ __('Reason') }}</th>
                                <th>{{ __('By') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($aggregates['cashIns'] ?? [] as $in)
                                <tr>
                                    <td>#{{ $in->shift_id }}</td>
                                    <td>{{ $in->created_at ? $in->created_at->format('Y-m-d H:i') : '—' }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($in->amount) }}</td>
                                    <td>{{ $in->reason ?: '—' }}</td>
                                    <td>{{ $in->user ? $in->user->name : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-muted text-center">—</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <h6 class="small text-muted">{{ __('Cash out (expenses)') }}</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr>
                                <th>{{ __('Shift') }}</th>
                                <th>{{ __('Date') }}</th>
                                <th class="text-end">{{ __('Amount') }}</th>
                                <th>{{ __('Description') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($aggregates['expenses'] ?? [] as $exp)
                                <tr>
                                    <td>#{{ $exp->pos_shift_id }}</td>
                                    <td>{{ $exp->date }}</td>
                                    <td class="text-end">{{ Auth::user()->priceFormat($exp->amount) }}</td>
                                    <td>{{ $exp->description ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-muted text-center">—</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
