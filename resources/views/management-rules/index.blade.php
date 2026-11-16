@extends('layouts.admin')
@section('page-title')
    {{ __('Management Rules') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Management Rules') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <h5>{{ __('Categories') }}</h5>
                    <p class="text-muted">{{ __('Each category maps to one P&L group and subgroup. A category used by a document cannot be deleted.') }}</p>
                    <form method="POST" action="{{ route('management-rules.load-starter-categories') }}" class="mb-3">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-secondary">{{ __('Load starter categories (57)') }}</button>
                        <small class="text-muted">{{ __('Loads the file\'s 57 categories. Only the missing ones are added.') }}</small>
                    </form>
                    <form method="POST" action="{{ route('management-rules.store') }}" class="row g-2 mb-3">
                        @csrf
                        <div class="col-md-2">
                            <input type="text" name="code" value="{{ old('code') }}" class="form-control @error('code') is-invalid @enderror" placeholder="{{ __('Code') }}" required>
                            @error('code')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-md-4">
                            <input type="text" name="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" placeholder="{{ __('Name') }}" required>
                            @error('name')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-md-3">
                            <select name="group" class="form-control @error('group') is-invalid @enderror" required>
                                @foreach ($groups as $g)
                                    <option value="{{ $g }}" {{ old('group') === $g ? 'selected' : '' }}>{{ $g }}</option>
                                @endforeach
                            </select>
                            @error('group')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-md-2">
                            <input type="text" name="subgroup" value="{{ old('subgroup') }}" class="form-control @error('subgroup') is-invalid @enderror" placeholder="{{ __('Subgroup') }}">
                            @error('subgroup')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Add') }}</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Code') }}</th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('P&L Group') }}</th>
                                <th>{{ __('Subgroup') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($rules as $rule)
                                <tr>
                                    <td>{{ $rule->code }}</td>
                                    <td>{{ $rule->name }}</td>
                                    <td>{{ $rule->group }}</td>
                                    <td>{{ $rule->subgroup ?: '-' }}</td>
                                    <td>
                                        <a href="{{ route('management-rules.edit', $rule) }}" class="btn btn-sm btn-primary" title="{{ __('Edit') }}"><i class="ti ti-pencil"></i></a>
                                        <form method="POST" action="{{ route('management-rules.destroy', $rule) }}" class="d-inline" onsubmit="return confirm('{{ __('Are you sure you want to delete this category?') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger" title="{{ __('Delete') }}"><i class="ti ti-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted">{{ __('No categories found.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h5>{{ __('Supplier Defaults') }}</h5>
                    <p class="text-muted">{{ __('Assign a category to a supplier — all that supplier\'s documents get that category automatically.') }}</p>
                    <form method="POST" action="{{ route('management-rules.supplier-defaults.store') }}" class="row g-2 mb-3">
                        @csrf
                        <div class="col-md-5">
                            <input type="text" name="supplier_name" value="{{ old('supplier_name') }}" class="form-control @error('supplier_name') is-invalid @enderror" placeholder="{{ __('Supplier name') }}" required>
                            @error('supplier_name')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-md-4">
                            <select name="management_rule_id" class="form-control @error('management_rule_id') is-invalid @enderror" required>
                                <option value="">{{ __('Category') }}</option>
                                @foreach ($rules->groupBy('group') as $groupName => $groupRules)
                                    <optgroup label="{{ $groupName }}">
                                        @foreach ($groupRules as $rule)
                                            <option value="{{ $rule->id }}">{{ $rule->label() }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('management_rule_id')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Save') }}</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Supplier') }}</th>
                                <th>{{ __('Category') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($supplierDefaults as $sd)
                                <tr>
                                    <td>{{ $sd->supplier_name }}</td>
                                    <td>{{ $sd->rule ? $sd->rule->label() : '-' }}</td>
                                    <td>
                                        <form method="POST" action="{{ route('management-rules.supplier-defaults.destroy', $sd) }}" class="d-inline" onsubmit="return confirm('{{ __('Are you sure you want to delete this supplier default?') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger" title="{{ __('Delete') }}"><i class="ti ti-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted">{{ __('No supplier defaults yet.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <h5>{{ __('Cost Centres') }}</h5>
                    <p class="text-muted">{{ __('Your own cost centres — 101 and 102 are only the starting point. A company can have none at all.') }}</p>
                    <form method="POST" action="{{ route('management-rules.load-starter-centres') }}" class="mb-3">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-secondary">{{ __('Load starter centres (101/102)') }}</button>
                        <small class="text-muted">{{ __('Loads the file\'s two example centres. Only the missing ones are added.') }}</small>
                    </form>
                    <form method="POST" action="{{ route('management-cost-centres.store') }}" class="row g-2 mb-3">
                        @csrf
                        <div class="col-md-3">
                            <input type="text" name="code" value="{{ old('code') }}" class="form-control @error('code') is-invalid @enderror" placeholder="{{ __('Code') }}" required>
                            @error('code')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-md-6">
                            <input type="text" name="name" value="{{ old('name') }}" class="form-control @error('name') is-invalid @enderror" placeholder="{{ __('Name') }}" required>
                            @error('name')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Add') }}</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Code') }}</th>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($centres as $centre)
                                <tr>
                                    <td>{{ $centre->code }}</td>
                                    <td>{{ $centre->name }}</td>
                                    <td>
                                        <a href="{{ route('management-cost-centres.edit', $centre) }}" class="btn btn-sm btn-primary" title="{{ __('Edit') }}"><i class="ti ti-pencil"></i></a>
                                        <form method="POST" action="{{ route('management-cost-centres.destroy', $centre) }}" class="d-inline" onsubmit="return confirm('{{ __('Are you sure you want to delete this cost centre?') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger" title="{{ __('Delete') }}"><i class="ti ti-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted">{{ __('No cost centres yet.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <h5>{{ __('Account Mapping') }}</h5>
                    <p class="text-muted">{{ __('Map a chart-of-accounts expense account to a P&L group. Manual journal postings on a mapped account flow into the management P&L as costs. Only manual journals count — system postings from bills and invoices are never counted, so a cost is either a document or a journal, never both.') }}</p>
                    <form method="POST" action="{{ route('management-rules.account-mappings.store') }}" class="row g-2 mb-3">
                        @csrf
                        <div class="col-md-4">
                            <select name="chart_account_id" class="form-control @error('chart_account_id') is-invalid @enderror" required>
                                <option value="">{{ __('Chart account') }}</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                                @endforeach
                            </select>
                            @error('chart_account_id')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-md-3">
                            <select name="group" class="form-control @error('group') is-invalid @enderror" required>
                                @foreach ($groups as $g)
                                    <option value="{{ $g }}" {{ old('group') === $g ? 'selected' : '' }}>{{ $g }}</option>
                                @endforeach
                            </select>
                            @error('group')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-md-3">
                            <input type="text" name="subgroup" value="{{ old('subgroup') }}" class="form-control @error('subgroup') is-invalid @enderror" placeholder="{{ __('Subgroup') }}">
                            @error('subgroup')<span class="invalid-feedback d-block">{{ $message }}</span>@enderror
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Add') }}</button>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Account') }}</th>
                                <th>{{ __('P&L Group') }}</th>
                                <th>{{ __('Subgroup') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($mappings as $mapping)
                                <tr>
                                    <td>{{ $mapping->accountLabel() }}</td>
                                    <td>{{ $mapping->group }}</td>
                                    <td>{{ $mapping->subgroup ?: '-' }}</td>
                                    <td>
                                        <form method="POST" action="{{ route('management-rules.account-mappings.update', $mapping) }}" class="d-inline">
                                            @csrf
                                            @method('PUT')
                                            <select name="group" class="form-control form-control-sm d-inline w-auto">
                                                @foreach ($groups as $g)
                                                    <option value="{{ $g }}" {{ $mapping->group === $g ? 'selected' : '' }}>{{ $g }}</option>
                                                @endforeach
                                            </select>
                                            <input type="text" name="subgroup" value="{{ $mapping->subgroup }}" class="form-control form-control-sm d-inline w-auto" placeholder="{{ __('Subgroup') }}" style="width: 140px">
                                            <button type="submit" class="btn btn-sm btn-primary">{{ __('Save') }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('management-rules.account-mappings.destroy', $mapping) }}" class="d-inline" onsubmit="return confirm('{{ __('Are you sure you want to delete this account mapping?') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger" title="{{ __('Delete') }}"><i class="ti ti-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted">{{ __('No account mappings yet.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
