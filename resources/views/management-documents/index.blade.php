@extends('layouts.admin')
@section('page-title')
    {{ __('Management Documents') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Management Documents') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('management-documents.export', request()->query()) }}" class="btn btn-sm btn-secondary">
            <i class="ti ti-download"></i> {{ __('Export Excel') }}
        </a>
        <a href="{{ route('management-documents.create') }}" class="btn btn-sm btn-primary">
            <i class="ti ti-plus"></i> {{ __('Add Document') }}
        </a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Each document is one real expense document. The management P&L costs come from these documents.') }}</p>

                    @if (session('importSkipped'))
                        <div class="alert alert-warning">
                            <strong>{{ __('Some rows were skipped:') }}</strong>
                            <ul class="mb-0">
                                @foreach (session('importSkipped') as $skipped)
                                    <li>{{ __('Row :row: :reason', ['row' => $skipped['row'], 'reason' => $skipped['reason']]) }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form id="import-form" method="POST" action="{{ route('management-documents.import') }}" enctype="multipart/form-data" class="row g-2 mb-3 align-items-end">
                        @csrf
                        <div class="col-md-4">
                            <label class="form-label">{{ __('Import documents (CSV or Excel)') }}</label>
                            <input type="file" name="file" accept=".csv,.xlsx" class="form-control" required>
                            <small class="text-muted">{{ __('Columns: date, supplier NIF, supplier name, doc number, type, base, IVA, retention, total, status, bank account, payment method, category code, cost centre code, notes.') }}</small>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary">{{ __('Import') }}</button>
                        </div>
                    </form>

                    @if ($total === 0)
                        <div class="alert alert-info">
                            {{ __('No documents yet. Documents feed the management P&L costs — months without documents show 0 costs.') }}
                            <a href="{{ route('management-documents.create') }}">{{ __('Add Document') }}</a>
                            {{ __('or') }}
                            <a href="#import-form">{{ __('import a file') }}</a>.
                        </div>
                    @endif
                    <form method="GET" action="{{ route('management-documents.index') }}" class="row g-2 mb-3">
                        <div class="col-md-2">
                            <select name="year" class="form-control">
                                @foreach ($years as $y)
                                    <option value="{{ $y }}" {{ (int) $year === $y ? 'selected' : '' }}>{{ $y }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="month" class="form-control">
                                <option value="">{{ __('All months') }}</option>
                                @foreach (['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'] as $i => $monthKey)
                                    <option value="{{ $i + 1 }}" {{ (string) $month === (string) ($i + 1) ? 'selected' : '' }}>{{ __('month.' . $monthKey) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="management_rule_id" class="form-control">
                                <option value="">{{ __('All categories') }}</option>
                                @foreach ($rules as $rule)
                                    <option value="{{ $rule->id }}" {{ (string) $ruleId === (string) $rule->id ? 'selected' : '' }}>{{ $rule->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="management_cost_centre_id" class="form-control">
                                <option value="">{{ __('All cost centres') }}</option>
                                @foreach ($centres as $centre)
                                    <option value="{{ $centre->id }}" {{ (string) $centreId === (string) $centre->id ? 'selected' : '' }}>{{ $centre->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="text" name="supplier" value="{{ $supplier }}" class="form-control" placeholder="{{ __('Supplier') }}">
                        </div>
                        <div class="col-md-2">
                            <select name="status" class="form-control">
                                <option value="">{{ __('All statuses') }}</option>
                                @foreach (\App\Models\ManagementDocument::STATUSES as $s)
                                    <option value="{{ $s }}" {{ $status === $s ? 'selected' : '' }}>{{ __('doc_status.' . $s) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="doc_type" class="form-control">
                                <option value="">{{ __('All types') }}</option>
                                @foreach (\App\Models\ManagementDocument::DOC_TYPES as $t)
                                    <option value="{{ $t }}" {{ $type === $t ? 'selected' : '' }}>{{ __('doc_type.' . $t) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                        <div class="col-md-2">
                            <a href="{{ route('management-documents.index') }}" class="btn btn-secondary w-100">{{ __('Clear') }}</a>
                        </div>
                    </form>

                    <p class="text-muted">{{ $total }} {{ __('documents') }}</p>

                    @if ($total > 0)
                        <p class="text-muted">
                            {{ __('Filtered totals:') }}
                            {{ __('Base') }} <strong>{{ \App\Services\ManagementMoney::format(\Auth::user(), (float) $sums->base_sum) }}</strong> ·
                            {{ __('VAT') }} <strong>{{ \App\Services\ManagementMoney::format(\Auth::user(), (float) $sums->iva_sum) }}</strong> ·
                            {{ __('Total') }} <strong>{{ \App\Services\ManagementMoney::format(\Auth::user(), (float) $sums->total_sum) }}</strong> ·
                            {{ __('Net Value') }} <strong>{{ \App\Services\ManagementMoney::format(\Auth::user(), (float) $sums->net_sum) }}</strong>
                        </p>
                    @endif

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Date') }}</th>
                                <th>{{ __('Supplier') }}</th>
                                <th>{{ __('Document No.') }}</th>
                                <th>{{ __('Type') }}</th>
                                <th>{{ __('Category') }}</th>
                                <th>{{ __('P&L Group') }}</th>
                                <th>{{ __('Cost Centre') }}</th>
                                <th class="text-end">{{ __('Base') }}</th>
                                <th class="text-end">{{ __('VAT') }}</th>
                                <th class="text-end">{{ __('Total') }}</th>
                                <th class="text-end">{{ __('Net Value') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($docs as $doc)
                                <tr>
                                    <td>{{ $doc->doc_date ? $doc->doc_date->format('Y-m-d') : '-' }}</td>
                                    <td>{{ $doc->supplier_name ?: '-' }}</td>
                                    <td>{{ $doc->doc_number ?: '-' }}</td>
                                    <td>{{ __('doc_type.' . $doc->doc_type) }}</td>
                                    <td>{{ $doc->rule ? $doc->rule->label() : '-' }}</td>
                                    <td>{{ $doc->rule ? $doc->rule->group : '-' }}</td>
                                    <td>{{ $doc->costCentre ? $doc->costCentre->label() : __('No cost centre') }}</td>
                                    <td class="text-end">{{ $doc->base !== null ? \App\Services\ManagementMoney::format(\Auth::user(), (float) $doc->base) : '-' }}</td>
                                    <td class="text-end">{{ $doc->iva !== null ? \App\Services\ManagementMoney::format(\Auth::user(), (float) $doc->iva) : '-' }}</td>
                                    <td class="text-end">{{ \App\Services\ManagementMoney::format(\Auth::user(), (float) $doc->total) }}</td>
                                    <td class="text-end">{{ \App\Services\ManagementMoney::format(\Auth::user(), $doc->netValue()) }}</td>
                                    <td><span class="badge {{ $doc->status === 'Conciliado' ? 'bg-success' : ($doc->status === 'Anulado' ? 'bg-danger' : 'bg-secondary') }}">{{ __('doc_status.' . $doc->status) }}</span></td>
                                    <td>
                                        <a href="{{ route('management-documents.edit', $doc) }}" class="btn btn-sm btn-primary" title="{{ __('Edit') }}"><i class="ti ti-pencil"></i></a>
                                        <form method="POST" action="{{ route('management-documents.destroy', $doc) }}" class="d-inline" onsubmit="return confirm('{{ __('Are you sure you want to delete this document?') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger" title="{{ __('Delete') }}"><i class="ti ti-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="13" class="text-center text-muted">{{ __('No documents found.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $docs->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
