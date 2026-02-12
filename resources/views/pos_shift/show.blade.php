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
                </div>
            </div>
        </div>
    </div>

    @if (($pos_shift->status === 'open') && (Gate::check('manage pos') || Gate::check('pos restaurant full') || Gate::check('pos restaurant manager')))
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
