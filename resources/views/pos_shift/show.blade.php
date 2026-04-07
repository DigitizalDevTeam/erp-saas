@extends('layouts.admin')
@section('page-title')
    {{ __('Shift') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS Shifts') }}</a></li>
    <li class="breadcrumb-item">{{ __('View') }}</li>
@endsection

@section('action-btn')
    @if (Gate::check('manage pos') || Gate::check('pos restaurant full') || Gate::check('pos restaurant manager'))
        <a href="{{ route('pos-shift.report', $pos_shift) }}" class="btn btn-sm btn-info">{{ __('Shift Report') }}</a>
        @if($pos_shift->status === 'open')
            <a href="{{ route('pos-shift.assign-table', $pos_shift) }}" class="btn btn-sm btn-warning">{{ __('Assign Tables') }}</a>
            <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#transferTablesModal">{{ __('pos.transfer_tables') }}</button>
            <a href="{{ route('pos-shift.close-form', $pos_shift) }}" class="btn btn-sm btn-danger">{{ __('Close Shift') }}</a>
        @endif
        <a href="{{ route('pos-shift.index') }}" class="btn btn-sm btn-secondary">{{ __('Back') }}</a>
    @endif
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>{{ __('Branch') }}:</strong> {{ $pos_shift->branch ? $pos_shift->branch->name : '-' }}</p>
                            <p><strong>{{ __('User') }}:</strong> {{ $pos_shift->user ? $pos_shift->user->name : '-' }}</p>
                            <p><strong>{{ __('Opened At') }}:</strong> {{ $pos_shift->opened_at ? $pos_shift->opened_at->format('Y-m-d H:i') : '-' }}</p>
                            <p><strong>{{ __('Opening Balance') }}:</strong> {{ Auth::user()->priceFormat($pos_shift->opening_balance) }}</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>{{ __('Status') }}:</strong> {{ $pos_shift->status === 'open' ? __('Open') : __('Closed') }}</p>
                            @if($pos_shift->closed_at)
                                <p><strong>{{ __('Closed At') }}:</strong> {{ $pos_shift->closed_at->format('Y-m-d H:i') }}</p>
                                <p><strong>{{ __('Closing Balance') }}:</strong> {{ Auth::user()->priceFormat($pos_shift->closing_balance) }}</p>
                            @endif
                        </div>
                    </div>
                    <hr>
                    <h6>{{ __('Table Assignments') }}</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{ __('Table') }}</th>
                                    <th>{{ __('Area') }}</th>
                                    <th>{{ __('Assigned At') }}</th>
                                    <th>{{ __('Unassigned At') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($pos_shift->tableAssignments as $a)
                                    <tr>
                                        <td>{{ $a->table ? $a->table->name : '-' }}</td>
                                        <td>{{ $a->table && $a->table->area ? $a->table->area->name : '-' }}</td>
                                        <td>{{ $a->assigned_at ? $a->assigned_at->format('Y-m-d H:i') : '-' }}</td>
                                        <td>{{ $a->unassigned_at ? $a->unassigned_at->format('Y-m-d H:i') : '-' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4">{{ __('No table assignments.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if($pos_shift->status === 'open' && (Gate::check('manage pos') || Gate::check('pos restaurant full') || Gate::check('pos restaurant manager')))
                    <hr>
                    <h6>{{ __('Cash drops') }}</h6>
                    <p class="text-muted small mb-2">{{ __('Withdrawals from register during shift.') }}</p>
                    <button type="button" class="btn btn-sm btn-outline-primary mb-2" data-bs-toggle="modal" data-bs-target="#cashDropModal"><i class="ti ti-cash-off"></i> {{ __('Add cash drop') }}</button>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{ __('Date') }}</th>
                                    <th class="text-end">{{ __('Amount') }}</th>
                                    <th>{{ __('Reason') }}</th>
                                    <th>{{ __('By') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($pos_shift->cashDrops ?? collect()) as $drop)
                                    <tr>
                                        <td>{{ $drop->created_at ? $drop->created_at->format('Y-m-d H:i') : '-' }}</td>
                                        <td class="text-end">{{ Auth::user()->priceFormat($drop->amount) }}</td>
                                        <td>{{ $drop->reason ?: '—' }}</td>
                                        <td>{{ $drop->user ? $drop->user->name : '-' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted">{{ __('No cash drops.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if(($pos_shift->cashDrops ?? collect())->isNotEmpty())
                    <p class="mb-0 small"><strong>{{ __('Total cash drops') }}:</strong> {{ Auth::user()->priceFormat($pos_shift->cashDrops->sum('amount')) }}</p>
                    @endif

                    <hr>
                    <h6>{{ __('Cash in') }}</h6>
                    <p class="text-muted small mb-2">{{ __('Top-ups / float added to the register during shift.') }}</p>
                    <button type="button" class="btn btn-sm btn-outline-success mb-2" data-bs-toggle="modal" data-bs-target="#cashInModal"><i class="ti ti-cash"></i> {{ __('Add cash in') }}</button>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{ __('Date') }}</th>
                                    <th class="text-end">{{ __('Amount') }}</th>
                                    <th>{{ __('Reason') }}</th>
                                    <th>{{ __('By') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse(($pos_shift->cashIns ?? collect()) as $in)
                                    <tr>
                                        <td>{{ $in->created_at ? $in->created_at->format('Y-m-d H:i') : '-' }}</td>
                                        <td class="text-end">{{ Auth::user()->priceFormat($in->amount) }}</td>
                                        <td>{{ $in->reason ?: '—' }}</td>
                                        <td>{{ $in->user ? $in->user->name : '-' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted">{{ __('No cash in.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if(Gate::check('approve expense') || Gate::check('pos restaurant manager'))
                    <hr>
                    <h6>{{ __('Cash out (expense)') }}</h6>
                    <p class="text-muted small mb-2">{{ __('Record a paid expense from the register (requires authorization).') }}</p>
                    <button type="button" class="btn btn-sm btn-outline-danger mb-2" data-bs-toggle="modal" data-bs-target="#cashOutExpenseModal"><i class="ti ti-receipt"></i> {{ __('Add cash out expense') }}</button>
                    @endif
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if (($pos_shift->status === 'open') && (Gate::check('manage pos') || Gate::check('pos restaurant full') || Gate::check('pos restaurant manager')))
    <div class="modal fade" id="cashDropModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Add cash drop') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="{{ route('pos-shift.cash-drop', $pos_shift) }}" method="POST">
                    @csrf
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">{{ __('Amount') }} <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required placeholder="0.00">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('Reason') }}</label>
                            <input type="text" name="reason" class="form-control" maxlength="255" placeholder="{{ __('Optional') }}">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ __('Record cash drop') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="cashInModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Add cash in') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="{{ route('pos-shift.cash-in', $pos_shift) }}" method="POST">
                    @csrf
                    <div class="modal-body">
                        @php
                            $bankAccounts = \App\Models\BankAccount::where('created_by', \Auth::user()->creatorId())->orderBy('bank_name')->get();
                        @endphp
                        <div class="mb-3">
                            <label class="form-label">{{ __('Amount') }} <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required placeholder="0.00">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('Reason') }}</label>
                            <input type="text" name="reason" class="form-control" maxlength="255" placeholder="{{ __('Optional') }}">
                        </div>
                        <div class="row g-2">
                            <div class="col-12 col-md-6">
                                <label class="form-label">{{ __('Source account (optional)') }}</label>
                                <select name="source_account_id" class="form-control">
                                    <option value="0">{{ __('— none —') }}</option>
                                    @foreach($bankAccounts as $b)
                                        <option value="{{ $b->id }}">{{ $b->bank_name }} {{ $b->holder_name }} ({{ $b->account_number }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label">{{ __('Target account (optional)') }}</label>
                                <select name="target_account_id" class="form-control">
                                    <option value="0">{{ __('— none —') }}</option>
                                    @foreach($bankAccounts as $b)
                                        <option value="{{ $b->id }}">{{ $b->bank_name }} {{ $b->holder_name }} ({{ $b->account_number }})</option>
                                    @endforeach
                                </select>
                                <small class="text-muted">{{ __('Choose the cash drawer account to increase.') }}</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-success">{{ __('Record cash in') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @if(Gate::check('approve expense') || Gate::check('pos restaurant manager'))
    <div class="modal fade" id="cashOutExpenseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Add cash out expense') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="{{ route('pos-shift.cash-out-expense', $pos_shift) }}" method="POST">
                    @csrf
                    <div class="modal-body">
                        <div class="mb-2">
                            <label class="form-label">{{ __('Name') }} <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required maxlength="255">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">{{ __('Amount') }} <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required placeholder="0.00">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">{{ __('Description') }}</label>
                            <input type="text" name="description" class="form-control" maxlength="1000" placeholder="{{ __('Optional') }}">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">{{ __('Expense category') }} <span class="text-danger">*</span></label>
                            <select name="expense_category_id" class="form-control" required>
                                @foreach(\App\Models\ExpenseCategory::where('created_by', \Auth::user()->creatorId())->get() as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">{{ __('Expense account (GL)') }} <span class="text-danger">*</span></label>
                            <select name="chart_account_id" class="form-control" required>
                                @foreach(\App\Models\ChartOfAccount::where('created_by', \Auth::user()->creatorId())->orderByRaw('CAST(code AS UNSIGNED)')->get() as $acc)
                                    <option value="{{ $acc->id }}">{{ $acc->code }} — {{ $acc->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">{{ __('Payment account') }} <span class="text-danger">*</span></label>
                            <select name="payment_account_id" class="form-control" required>
                                @foreach(\App\Models\BankAccount::where('created_by', \Auth::user()->creatorId())->orderBy('bank_name')->get() as $b)
                                    <option value="{{ $b->id }}">{{ $b->bank_name }} {{ $b->holder_name }} ({{ $b->account_number }})</option>
                                @endforeach
                            </select>
                            <small class="text-muted">{{ __('Choose the cash drawer/bank account used for payouts.') }}</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="btn btn-danger">{{ __('Record expense payout') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif
    <div class="modal fade" id="transferTablesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('pos.transfer_tables') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="transfer-tables-form" action="{{ route('pos-shift.transfer-tables') }}" method="POST">
                    @csrf
                    <div class="modal-body">
                        <input type="hidden" name="from_shift_id" value="{{ $pos_shift->id }}">
                        <div class="mb-3">
                            <label class="form-label">{{ __('pos.to_shift') }}</label>
                            <select name="to_shift_id" class="form-control" required>
                                <option value="">{{ __('pos.select_shift') }}</option>
                                @foreach(\App\Models\PosShift::where('branch_id', $pos_shift->branch_id)->where('status', 'open')->where('id', '!=', $pos_shift->id)->where('created_by', \Auth::user()->creatorId())->with('user')->get() as $s)
                                    <option value="{{ $s->id }}">{{ $s->user ? $s->user->name : __('Shift') }} #{{ $s->id }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">{{ __('pos.select_tables_to_transfer') }}</label>
                            @php
                                $assigned = $pos_shift->tableAssignments->whereNull('unassigned_at');
                            @endphp
                            @forelse($assigned as $a)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="table_ids[]" value="{{ $a->table_id }}" id="transfer_tbl_{{ $a->table_id }}">
                                    <label class="form-check-label" for="transfer_tbl_{{ $a->table_id }}">{{ $a->table ? $a->table->name : '-' }}</label>
                                </div>
                            @empty
                                <p class="text-muted small mb-0">{{ __('No tables assigned to this shift.') }}</p>
                            @endforelse
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('pos.cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ __('pos.transfer_tables') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
        document.getElementById('transfer-tables-form') && document.getElementById('transfer-tables-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var form = this;
            var data = new FormData(form);
            var tableIds = [];
            form.querySelectorAll('input[name="table_ids[]"]:checked').forEach(function(cb) { tableIds.push(cb.value); });
            if (tableIds.length === 0) {
                alert('{{ __("pos.select_tables_to_transfer") }}');
                return;
            }
            data.delete('table_ids[]');
            tableIds.forEach(function(id) { data.append('table_ids[]', id); });
            fetch(form.action, { method: 'POST', body: data, headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'), 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) { window.location.reload(); }
                    else { alert(res.error || '{{ __("pos.error") }}'); }
                })
                .catch(function() { alert('{{ __("pos.error") }}'); });
        });
    </script>
    @endif
@endsection
