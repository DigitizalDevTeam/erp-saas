@extends('layouts.admin')
@section('page-title'){{ __('Hotel expenses') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Hotel expenses') }}</li>
@endsection
@section('action-btn')
@if($canManage ?? false)
<a href="{{ route('hotel.expenses.create', ['category' => $category === 'all' ? 'laundry' : $category]) }}" class="btn btn-sm btn-primary">
    <i class="ti ti-plus"></i> {{ __('Add expense') }}
</a>
@endif
@endsection
@section('content')
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">{{ __('Category') }}</label>
                <select name="category" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="all" @selected($category === 'all')>{{ __('All') }}</option>
                    @foreach($categoryOptions as $value => $label)
                        <option value="{{ $value }}" @selected($category === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body table-border-style">
        <div class="table-responsive">
            <table class="table datatable">
                <thead>
                    <tr>
                        <th>{{ __('Date') }}</th>
                        <th>{{ __('Category') }}</th>
                        <th>{{ __('Title') }}</th>
                        <th>{{ __('Vendor') }}</th>
                        <th>{{ __('Room') }}</th>
                        <th class="text-end">{{ __('Amount') }}</th>
                        <th>{{ __('Payment') }}</th>
                        <th>{{ __('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($expenses as $expense)
                    <tr>
                        <td>{{ optional($expense->expense_date)->format('Y-m-d') }}</td>
                        <td>{{ $categoryOptions[$expense->category] ?? $expense->category }}</td>
                        <td>
                            <strong>{{ $expense->title }}</strong>
                            @if($expense->reference)
                                <br><small class="text-muted">{{ $expense->reference }}</small>
                            @endif
                        </td>
                        <td>{{ $expense->vendor ?: '—' }}</td>
                        <td>{{ $expense->room->room_no ?? '—' }}</td>
                        <td class="text-end">{{ Auth::user()->priceFormat($expense->amount) }}</td>
                        <td>{{ $expense->payment_method ? __(ucwords(str_replace('_', ' ', $expense->payment_method))) : '—' }}</td>
                        <td>
                            @if($canManage ?? false)
                                <a class="btn btn-sm btn-info" href="{{ route('hotel.expenses.edit', $expense) }}"><i class="ti ti-pencil text-white"></i></a>
                                {!! Form::open(['method' => 'DELETE', 'route' => ['hotel.expenses.destroy', $expense->id], 'class' => 'd-inline']) !!}
                                <button class="btn btn-sm btn-danger" onclick="return confirm('{{ __('Are you sure?') }}')"><i class="ti ti-trash"></i></button>
                                {!! Form::close() !!}
                            @else
                                <span class="text-muted small">{{ __('View only') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-muted text-center">{{ __('No expenses found.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
