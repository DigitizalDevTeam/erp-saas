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
                                @for ($m = 1; $m <= 12; $m++)
                                    <option value="{{ $m }}" {{ (string) $month === (string) $m ? 'selected' : '' }}>{{ date('M', mktime(0, 0, 0, $m, 1)) }}</option>
                                @endfor
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
                                    <option value="{{ $s }}" {{ $status === $s ? 'selected' : '' }}>{{ $s }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="doc_type" class="form-control">
                                <option value="">{{ __('All types') }}</option>
                                @foreach (\App\Models\ManagementDocument::DOC_TYPES as $t)
                                    <option value="{{ $t }}" {{ $type === $t ? 'selected' : '' }}>{{ $t }}</option>
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

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Date') }}</th>
                                <th>{{ __('Supplier') }}</th>
                                <th>{{ __('Document No.') }}</th>
                                <th>{{ __('Type') }}</th>
                                <th>{{ __('Category') }}</th>
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
                                    <td>{{ $doc->doc_type }}</td>
                                    <td>{{ $doc->rule ? $doc->rule->label() : '-' }}</td>
                                    <td>{{ $doc->costCentre ? $doc->costCentre->label() : __('No cost centre') }}</td>
                                    <td class="text-end">{{ $doc->base !== null ? \Auth::user()->priceFormat($doc->base) : '-' }}</td>
                                    <td class="text-end">{{ $doc->iva !== null ? \Auth::user()->priceFormat($doc->iva) : '-' }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($doc->total) }}</td>
                                    <td class="text-end">{{ \Auth::user()->priceFormat($doc->netValue()) }}</td>
                                    <td><span class="badge {{ $doc->status === 'Conciliado' ? 'bg-success' : ($doc->status === 'Anulado' ? 'bg-danger' : 'bg-secondary') }}">{{ $doc->status }}</span></td>
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
                                <tr><td colspan="12" class="text-center text-muted">{{ __('No documents found.') }}</td></tr>
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
