@extends('layouts.admin')
@section('page-title')
    {{ __('Bank reconciliation') }} #{{ $session->id }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('bank-reconciliation.index') }}">{{ __('Bank reconciliation') }}</a></li>
    <li class="breadcrumb-item">#{{ $session->id }}</li>
@endsection
@section('action-btn')
    <div class="float-end d-flex gap-2">
        {{ Form::open(['route' => ['bank-reconciliation.auto-match', $session], 'method' => 'post', 'class' => 'd-inline']) }}
        @csrf
        <button type="submit" class="btn btn-sm btn-primary">{{ __('Run auto-match') }}</button>
        {{ Form::close() }}
        <a href="{{ route('bank-reconciliation.create') }}" class="btn btn-sm btn-outline-primary">{{ __('New import') }}</a>
    </div>
@endsection
@section('content')
    <div class="row mb-3">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-3">
                    <span class="text-muted d-block">{{ __('Statement lines') }}</span>
                    <strong>{{ $stats['lines'] }}</strong>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-3">
                    <span class="text-muted d-block">{{ __('Matched') }}</span>
                    <strong class="text-success">{{ $stats['matched'] }}</strong>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-3">
                    <span class="text-muted d-block">{{ __('Unmatched (statement)') }}</span>
                    <strong class="text-warning">{{ $stats['unmatched_statement'] }}</strong>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body py-3">
                    <span class="text-muted d-block">{{ __('Unmatched (ERP)') }}</span>
                    <strong class="text-danger">{{ $stats['unmatched_erp'] }}</strong>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <p class="mb-1"><strong>{{ __('File') }}:</strong> {{ $session->original_filename }}</p>
                    <p class="mb-1"><strong>{{ __('Bank') }}:</strong>
                        {{ $session->bankAccount ? $session->bankAccount->bank_name . ' — ' . $session->bankAccount->holder_name : '-' }}
                    </p>
                    <p class="mb-1"><strong>{{ __('Preset') }}:</strong>
                        {{ config('bank_reconciliation.presets.' . $session->preset_key . '.label', $session->preset_key) }}</p>
                    <p class="mb-1"><strong>{{ __('Status') }}:</strong>
                        @if ($session->isClosed())
                            <span class="badge bg-success">{{ __('Closed') }}</span>
                            {{ __('on') }} {{ $session->closed_at ? $session->closed_at->format('Y-m-d H:i') : '—' }}
                        @else
                            <span class="badge bg-secondary">{{ __('Draft') }}</span>
                        @endif
                    </p>
                    <p class="mb-0"><strong>{{ __('File hash') }}:</strong> {{ $session->file_hash ? substr($session->file_hash, 0, 16) . '…' : '—' }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Close') }}</h5>
                </div>
                <div class="card-body">
                    <p class="mb-1"><strong>{{ __('Statement balance') }}:</strong> {{ number_format((float) $summary['statement_balance'], 2) }}</p>
                    <p class="mb-1"><strong>{{ __('Book balance') }}:</strong> {{ number_format((float) $summary['book_balance'], 2) }}</p>
                    <p class="mb-1"><strong>{{ __('Outstanding items') }}:</strong> {{ number_format((float) $summary['outstanding_items'], 2) }}</p>
                    <p class="mb-1"><strong>{{ __('Explained differences') }}:</strong> {{ number_format((float) $summary['explained_differences'], 2) }}</p>
                    <p class="mb-3"><strong>{{ __('Difference') }}:</strong>
                        <span class="{{ abs((float) $summary['difference']) > 0.01 ? 'text-danger' : 'text-success' }}">{{ number_format((float) $summary['difference'], 2) }}</span>
                        <span class="text-muted">({{ __('statement − (book + outstanding); close needs zero') }})</span>
                    </p>
                    @if ($session->isClosed())
                        <p class="mb-3 text-muted">{{ __('Closed with statement :s, book :b, outstanding :o, explained :e.', ['s' => number_format((float) $session->statement_balance, 2), 'b' => number_format((float) $session->book_balance, 2), 'o' => number_format((float) $session->outstanding_items, 2), 'e' => number_format((float) $session->explained_differences, 2)]) }}</p>
                        @can('approve treasury reconciliation')
                            {{ Form::open(['route' => ['bank-reconciliation.reopen', $session], 'method' => 'post', 'class' => 'row g-2 align-items-center']) }}
                            @csrf
                            <div class="col-md-6">
                                {{ Form::text('reopen_reason', null, ['class' => 'form-control', 'placeholder' => __('Reason to reopen (required)')]) }}
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-warning">{{ __('Reopen') }}</button>
                            </div>
                            {{ Form::close() }}
                        @endcan
                    @else
                        @can('approve treasury reconciliation')
                            {{ Form::open(['route' => ['bank-reconciliation.close', $session], 'method' => 'post', 'class' => 'row g-2 align-items-center']) }}
                            @csrf
                            <div class="col-md-3">
                                {{ Form::number('statement_balance', $summary['statement_balance'], ['class' => 'form-control', 'step' => '0.01', 'placeholder' => __('Statement balance')]) }}
                            </div>
                            <div class="col-md-3">
                                {{ Form::number('book_balance', $summary['book_balance'], ['class' => 'form-control', 'step' => '0.01', 'placeholder' => __('Book balance')]) }}
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-sm btn-success">{{ __('Close statement') }}</button>
                            </div>
                            {{ Form::close() }}
                        @endcan
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Matched') }}</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('Statement date') }}</th>
                                    <th>{{ __('Description') }}</th>
                                    <th>{{ __('Amount') }}</th>
                                    <th>{{ __('Matched to') }}</th>
                                    <th>{{ __('Matched amount') }}</th>
                                    <th>{{ __('Type') }}</th>
                                    <th>{{ __('Match') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($matchRows as $m)
                                    @php $line = $m->statementLine; $tx = $m->erpTransaction; $op = $m->treasuryOperation; @endphp
                                    <tr>
                                        <td>{{ $line && $line->txn_date ? $line->txn_date->format('Y-m-d') : '—' }}</td>
                                        <td>{{ \Illuminate\Support\Str::limit($line->description ?? '', 60) }}</td>
                                        <td>{{ $line && $line->amount !== null ? number_format((float) $line->amount, 2) : '—' }}</td>
                                        <td>
                                            @if ($tx)
                                                {{ __('ERP transaction') }} #{{ $tx->id }} — {{ $tx->date }} — {{ number_format((float) $tx->amount, 2) }}
                                            @elseif ($op)
                                                {{ __('Treasury operation') }} {{ $op->reference }} — {{ number_format((float) $op->amount_base, 2) }}
                                            @elseif ($m->match_type === 'missing_movement')
                                                {{ __('Missing movement (posted)') }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ $m->matched_amount !== null ? number_format((float) $m->matched_amount, 2) : '—' }}</td>
                                        <td>{{ $tx ? $tx->type : ($op ? $op->type : '—') }}</td>
                                        <td><span class="badge bg-{{ $m->match_type === 'manual' ? 'info' : 'secondary' }}">{{ $m->match_type ?? '' }}</span></td>
                                        <td class="text-end">
                                            @if ($tx)
                                                {{ Form::open(['route' => ['bank-reconciliation.unmatch', $session], 'method' => 'post', 'class' => 'd-inline']) }}
                                                @csrf
                                                {{ Form::hidden('statement_line_id', $line->id) }}
                                                <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Unmatch') }}</button>
                                                {{ Form::close() }}
                                            @elseif ($op)
                                                {{ Form::open(['route' => ['treasury-reconciliation.treasury-unmatch', $session], 'method' => 'post', 'class' => 'd-inline']) }}
                                                @csrf
                                                {{ Form::hidden('statement_line_id', $line->id) }}
                                                {{ Form::hidden('match_id', $m->id) }}
                                                <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Unmatch') }}</button>
                                                {{ Form::close() }}
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted">{{ __('No matches yet.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Unmatched statement lines') }}</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Description') }}</th>
                                    <th>{{ __('Amount') }}</th>
                                    <th>{{ __('Link ERP transaction') }}</th>
                                    <th>{{ __('Link Treasury operation') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($unmatchedStatement as $line)
                                    <tr>
                                        <td>{{ $line->txn_date ? $line->txn_date->format('Y-m-d') : '—' }}</td>
                                        <td>{{ \Illuminate\Support\Str::limit($line->description, 80) }}</td>
                                        <td>{{ $line->amount !== null ? number_format((float) $line->amount, 2) : '—' }}</td>
                                        <td>
                                            {{ Form::open(['route' => ['bank-reconciliation.manual-match', $session], 'method' => 'post', 'class' => 'row g-2 align-items-center']) }}
                                            @csrf
                                            {{ Form::hidden('statement_line_id', $line->id) }}
                                            <div class="col-auto">
                                                @php
                                                    $txOpts = $unmatchedErp
                                                        ->mapWithKeys(function ($t) {
                                                            return [
                                                                $t->id =>
                                                                    $t->date .
                                                                    ' | ' .
                                                                    number_format((float) $t->amount, 2) .
                                                                    ' | ' .
                                                                    ($t->type ?? '') .
                                                                    ' | #' .
                                                                    $t->id,
                                                            ];
                                                        })
                                                        ->all();
                                                @endphp
                                                {{ Form::select('transaction_id', ['' => __('— Select —')] + $txOpts, null, ['class' => 'form-select form-select-sm']) }}
                                            </div>
                                            <div class="col-auto">
                                                <button type="submit" class="btn btn-sm btn-primary">{{ __('Link') }}</button>
                                            </div>
                                            {{ Form::close() }}
                                        </td>
                                        <td>
                                            {{ Form::open(['route' => ['treasury-reconciliation.treasury-match', $session], 'method' => 'post', 'class' => 'row g-2 align-items-center']) }}
                                            @csrf
                                            {{ Form::hidden('statement_line_id', $line->id) }}
                                            <div class="col-auto">
                                                @php
                                                    $opOpts = $unmatchedOperations
                                                        ->mapWithKeys(function ($o) {
                                                            return [
                                                                $o->id =>
                                                                    $o->reference .
                                                                    ' | ' .
                                                                    number_format((float) $o->amount_base, 2) .
                                                                    ' | ' .
                                                                    __('residual') .
                                                                    ' ' .
                                                                    number_format((float) $o->residual_amount, 2),
                                                            ];
                                                        })
                                                        ->all();
                                                @endphp
                                                {{ Form::select('treasury_operation_id', ['' => __('— Select —')] + $opOpts, null, ['class' => 'form-select form-select-sm']) }}
                                            </div>
                                            <div class="col-auto">
                                                {{ Form::number('matched_amount', null, ['class' => 'form-control form-control-sm', 'step' => '0.01', 'placeholder' => __('Amount (blank = full)')]) }}
                                            </div>
                                            <div class="col-auto">
                                                <button type="submit" class="btn btn-sm btn-primary">{{ __('Link') }}</button>
                                            </div>
                                            {{ Form::close() }}
                                        </td>
                                        <td>
                                            @can('manage treasury reconciliation')
                                                <a href="{{ route('treasury-reconciliation.missing-movement-form', [$session, $line]) }}" class="btn btn-sm btn-outline-secondary">{{ __('Missing movement') }}</a>
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">{{ __('None') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Missing movements (fees, interest, other items on the statement but not in the books)') }}</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>{{ __('Line') }}</th>
                                    <th>{{ __('Category') }}</th>
                                    <th>{{ __('Amount') }}</th>
                                    <th>{{ __('State') }}</th>
                                    <th>{{ __('Operation') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($missingMovements as $mm)
                                    <tr>
                                        <td>{{ $mm->statementLine ? ($mm->statementLine->txn_date ? $mm->statementLine->txn_date->format('Y-m-d') . ' — ' : '') . \Illuminate\Support\Str::limit($mm->statementLine->description ?? '', 40) : '#' . $mm->statement_line_id }}</td>
                                        <td>{{ $mm->category }}</td>
                                        <td>{{ number_format((float) $mm->amount_original, 2) }} {{ $mm->currency }}</td>
                                        <td><span class="badge bg-{{ $mm->state === 'confirmed' ? 'success' : ($mm->state === 'rejected' ? 'danger' : ($mm->state === 'reversed' ? 'secondary' : 'warning')) }}">{{ $mm->state }}</span></td>
                                        <td>{{ $mm->operation ? $mm->operation->reference : '—' }}</td>
                                        <td class="text-end">
                                            @if ($mm->state === 'proposed')
                                                @can('approve treasury reconciliation')
                                                    {{ Form::open(['route' => ['treasury-reconciliation.missing-movement-confirm', $mm], 'method' => 'post', 'class' => 'd-inline']) }}
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-success">{{ __('Confirm & post') }}</button>
                                                    {{ Form::close() }}
                                                @endcan
                                                {{ Form::open(['route' => ['treasury-reconciliation.missing-movement-reject', $mm], 'method' => 'post', 'class' => 'd-inline']) }}
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Reject') }}</button>
                                                {{ Form::close() }}
                                            @elseif ($mm->state === 'confirmed')
                                                @can('approve treasury reconciliation')
                                                    {{ Form::open(['route' => ['treasury-reconciliation.missing-movement-reverse', $mm], 'method' => 'post', 'class' => 'd-inline']) }}
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-warning">{{ __('Reverse') }}</button>
                                                    {{ Form::close() }}
                                                @endcan
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted">{{ __('None proposed yet.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('ERP transactions not matched (same account & window)') }}</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Amount') }}</th>
                                    <th>{{ __('Type') }}</th>
                                    <th>{{ __('Description') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($unmatchedErp as $t)
                                    <tr>
                                        <td>{{ $t->id }}</td>
                                        <td>{{ $t->date }}</td>
                                        <td>{{ number_format((float) $t->amount, 2) }}</td>
                                        <td>{{ $t->type }}</td>
                                        <td>{{ \Illuminate\Support\Str::limit($t->description, 80) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">{{ __('None in range or all matched.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
