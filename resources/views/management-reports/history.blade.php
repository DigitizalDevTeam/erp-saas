@extends('layouts.admin')
@section('page-title')
    {{ __('Management History') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Accounting') }}</li>
    <li class="breadcrumb-item">{{ __('Management History') }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ route('management-history.create') }}" class="btn btn-sm btn-primary">
            <i class="ti ti-plus"></i> {{ __('Add History Row') }}
        </a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Imported figures from the Excel file. A row wins for its line and month in the P&L — editing a row here changes the P&L for that line-month.') }}</p>
                    <form method="GET" action="{{ route('management-history.index') }}" class="row g-2 mb-3">
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
                            <button type="submit" class="btn btn-primary w-100">{{ __('Filter') }}</button>
                        </div>
                        <div class="col-md-2">
                            <a href="{{ route('management-history.index') }}" class="btn btn-secondary w-100">{{ __('Clear') }}</a>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Year') }}</th>
                                <th>{{ __('Month') }}</th>
                                <th>{{ __('Line') }}</th>
                                <th class="text-end">{{ __('Amount') }}</th>
                                <th>{{ __('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($rows as $row)
                                <tr>
                                    <td>{{ $row->year }}</td>
                                    <td>{{ __('month.' . ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'][$row->month - 1]) }}</td>
                                    <td>{{ $row->line }}</td>
                                    <td class="text-end">{{ \App\Services\ManagementMoney::format(\Auth::user(), (float) $row->amount) }}</td>
                                    <td>
                                        <a href="{{ route('management-history.edit', $row) }}" class="btn btn-sm btn-primary" title="{{ __('Edit') }}"><i class="ti ti-pencil"></i></a>
                                        <form method="POST" action="{{ route('management-history.destroy', $row) }}" class="d-inline" onsubmit="return confirm('{{ __('Are you sure you want to delete this history row?') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger" title="{{ __('Delete') }}"><i class="ti ti-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted">{{ __('No history rows found.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $rows->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
