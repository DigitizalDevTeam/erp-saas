@extends('layouts.admin')
@section('page-title')
    {{ __('Cash-Flow Projections') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-cash-flow.index') }}">{{ __('Cash Flow') }}</a></li>
    <li class="breadcrumb-item">{{ __('Projections') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Add a projection') }}</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted"><small>{{ __('Manual projections are estimates — enter a contract reference for a contract estimate. Contract projections are replaced by their issued invoice when it arrives, so nothing is double-counted.') }}</small></p>
                    <form method="POST" action="{{ route('treasury-cash-flow.projections.store') }}" class="row g-2">
                        @csrf
                        <div class="col-md-2">
                            <select name="source_type" class="form-control" required>
                                <option value="manual">{{ __('Manual') }}</option>
                                <option value="contract">{{ __('Contract estimate') }}</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="text" name="reference" class="form-control" placeholder="{{ __('Reference (e.g. CTR-2027-001)') }}" maxlength="150">
                        </div>
                        <div class="col-md-2">
                            <select name="direction" class="form-control" required>
                                <option value="inflow">{{ __('Inflow') }}</option>
                                <option value="outflow">{{ __('Outflow') }}</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <input type="number" name="amount" class="form-control" placeholder="{{ __('Amount') }} ({{ $baseCurrency }})" min="0.0001" step="0.0001" required>
                        </div>
                        <div class="col-md-2">
                            <input type="date" name="expected_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                        </div>
                        <div class="col-md-1">
                            <input type="number" name="probability" class="form-control" placeholder="{{ __('Prob. %') }}" min="0" max="100" step="1">
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-primary w-100">{{ __('Add') }}</button>
                        </div>
                        <div class="col-md-12">
                            <input type="text" name="note" class="form-control" placeholder="{{ __('Note (optional)') }}" maxlength="250">
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    @if ($items->isEmpty())
                        <p class="text-muted">{{ __('No manual or contract projections yet.') }}</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>{{ __('Type') }}</th>
                                        <th>{{ __('Reference') }}</th>
                                        <th>{{ __('Direction') }}</th>
                                        <th class="text-end">{{ __('Amount') }}</th>
                                        <th>{{ __('Expected date') }}</th>
                                        <th>{{ __('Probability') }}</th>
                                        <th>{{ __('State') }}</th>
                                        <th>{{ __('Note') }}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($items as $item)
                                        <tr>
                                            <td>{{ $item->source_type === 'contract' ? ($item->source_id ? __('Contract projection') : __('Contract estimate')) : __('Manual') }}</td>
                                            <td>{{ $item->document ?? '—' }}</td>
                                            <td>{{ ucfirst($item->direction) }}</td>
                                            <td class="text-end">{{ number_format((float) $item->amount, 2) }} {{ $item->currency }}</td>
                                            <td>{{ $item->expected_date }}</td>
                                            <td>{{ $item->probability !== null ? $item->probability . '%' : '—' }}</td>
                                            <td>{{ ucfirst($item->state) }}</td>
                                            <td>{{ $item->description ?? '—' }}</td>
                                            <td>
                                                @if ($item->state === 'forecast' && ! ($item->source_type === 'contract' && $item->source_id))
                                                    <form method="POST" action="{{ route('treasury-cash-flow.projections.destroy', $item->id) }}" onsubmit="return confirm('{{ __('Delete this projection?') }}')">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Delete') }}</button>
                                                    </form>
                                                @elseif ($item->source_type === 'contract' && $item->source_id)
                                                    <small class="text-muted">{{ __('Managed by the contract') }}</small>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $items->links() }}
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
