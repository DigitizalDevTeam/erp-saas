@php
    $ulane = auth()->user()->lang ?? 'en';
    $name_column = match ($ulane) {
        'fr' => 'name_fr',
        'zh' => 'name_zh',
        'es' => 'name_es',
        'pt_br', 'pt-br' => 'name_pt_br',
        default => 'name',
    };
    $expensePath = \App\Models\Utility::get_file('uploads/expenses/');
@endphp
<div class="modal-body">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label text-muted small mb-0">{{ __('Name') }}</label>
            <div class="fw-semibold">{{ $expense->name }}</div>
        </div>
        <div class="col-md-6">
            <label class="form-label text-muted small mb-0">{{ __('Date') }}</label>
            <div>{{ $expense->date ? $expense->date->format('d M Y') : '—' }}</div>
        </div>
        <div class="col-md-6">
            <label class="form-label text-muted small mb-0">{{ __('Category') }}</label>
            <div>{{ $expense->expenseCategory?->name ?? '—' }}</div>
        </div>
        <div class="col-md-6">
            <label class="form-label text-muted small mb-0">{{ __('Amount') }}</label>
            <div class="fw-semibold">{{ \Auth::user()->priceFormat($expense->amount) }}</div>
        </div>
        <div class="col-md-6">
            <label class="form-label text-muted small mb-0">{{ __('Expense account (GL)') }}</label>
            <div>{{ $expense->chartAccount ? $expense->chartAccount->code . ' — ' . $expense->chartAccount->$name_column : '—' }}</div>
        </div>
        <div class="col-md-6">
            <label class="form-label text-muted small mb-0">{{ __('Payment account') }}</label>
            <div>{{ $expense->paymentBankAccount ? $expense->paymentBankAccount->bank_name . ' ' . $expense->paymentBankAccount->holder_name : '—' }}</div>
        </div>
        <div class="col-md-6">
            <label class="form-label text-muted small mb-0">{{ __('Branch') }}</label>
            <div>{{ $expense->branch?->name ?? '—' }}</div>
        </div>
        <div class="col-md-6">
            <label class="form-label text-muted small mb-0">{{ __('Status') }}</label>
            <div>
                @if($expense->status == \App\Models\Expense::STATUS_APPROVED)
                    <span class="badge bg-success">{{ __('Approved') }}</span>
                @elseif($expense->status == \App\Models\Expense::STATUS_EXECUTED)
                    <span class="badge bg-primary">{{ __('Executed') }}</span>
                @else
                    <span class="badge bg-secondary">{{ $expense->status_label }}</span>
                @endif
            </div>
        </div>
        @if($expense->description)
        <div class="col-12">
            <label class="form-label text-muted small mb-0">{{ __('Description') }}</label>
            <div>{{ $expense->description }}</div>
        </div>
        @endif
        @if($expense->attachment)
        <div class="col-12">
            <label class="form-label text-muted small mb-0">{{ __('Attachment') }}</label>
            <div><a href="{{ $expensePath . $expense->attachment }}" target="_blank" class="btn btn-sm btn-outline-secondary">{{ __('View attachment') }}</a></div>
        </div>
        @endif
        @if($expense->executed_at)
        <div class="col-12">
            <small class="text-muted">{{ __('Executed') }}: {{ $expense->executed_at->format('d M Y H:i') }}{{ $expense->executedByUser ? ' — ' . $expense->executedByUser->name : '' }}</small>
        </div>
        @endif
    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{ __('Close') }}" class="btn btn-secondary" data-bs-dismiss="modal">
    @if($expense->status == \App\Models\Expense::STATUS_APPROVED)
        @can('manage expense')
            {!! Form::open(['method' => 'POST', 'route' => ['expenses.execute', $expense->id], 'id' => 'execute-form-' . $expense->id, 'class' => 'd-inline']) !!}
            <button type="submit" class="btn btn-primary" onclick="return confirm('{{ __('Mark this expense as executed?') }}');">
                <i class="ti ti-player-play me-1"></i>{{ __('Execute') }}
            </button>
            {!! Form::close() !!}
        @endcan
    @endif
</div>
