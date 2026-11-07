@extends('layouts.admin')
@section('page-title')
    {{ __('Cost Centre Allocations') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item">{{ __('Allocations') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Posted splits are never edited — reverse one with a reason, then post a new allocation. Allocated amounts are the persisted amounts; they are never recomputed.') }}</p>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('ID') }}</th>
                                    <th>{{ __('Source') }}</th>
                                    <th>{{ __('Line') }}</th>
                                    <th>{{ __('Basis') }}</th>
                                    <th class="text-end">{{ __('Source amount') }}</th>
                                    <th>{{ __('Split') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($allocations as $allocation)
                                    <tr>
                                        <td>{{ $allocation->id }}</td>
                                        <td>{{ ucfirst($allocation->source_type) }} #{{ $allocation->source_id }}</td>
                                        <td>{{ $allocation->source_line_type }} #{{ $allocation->source_line_id }}</td>
                                        <td>{{ $allocation->basis === 'fixed_amount' ? __('Fixed amount') : __('Percentage') }}</td>
                                        <td class="text-end">{{ number_format((float) $allocation->source_amount, 2) }}</td>
                                        <td>
                                            @foreach ($allocation->lines as $line)
                                                <div>{{ $line->costCentre ? $line->costCentre->code : '#' . $line->cost_centre_id }}:
                                                    @if ($allocation->basis === 'fixed_amount')
                                                        {{ number_format((float) $line->allocated_amount, 2) }}
                                                    @else
                                                        {{ rtrim(rtrim((string) $line->percentage, '0'), '.') }}% = {{ number_format((float) $line->allocated_amount, 2) }}
                                                    @endif
                                                </div>
                                            @endforeach
                                        </td>
                                        <td>
                                            <span class="badge bg-{{ $allocation->status === 'posted' ? 'success' : ($allocation->status === 'reversed' ? 'danger' : 'secondary') }}">{{ ucfirst($allocation->status) }}</span>
                                            @if ($allocation->approved_at)
                                                <div><small>{{ __('Approved') }}</small></div>
                                            @endif
                                            @if ($allocation->reversal_reason)
                                                <div><small>{{ $allocation->reversal_reason }}</small></div>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if ($allocation->status === 'posted')
                                                @can('approve cost centre allocations')
                                                    <form method="POST" action="{{ route('cost-centre-allocations.approve', $allocation->id) }}" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-outline-success">{{ __('Approve') }}</button>
                                                    </form>
                                                @endcan
                                                @can('manage cost centre allocations')
                                                    <form method="POST" action="{{ route('cost-centre-allocations.reverse', $allocation->id) }}" class="d-inline" onsubmit="var r = prompt('{{ __('Reason for reversing (required):') }}'); if (!r) return false; this.querySelector('input[name=reason]').value = r;">
                                                        @csrf
                                                        <input type="hidden" name="reason" value="">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Reverse') }}</button>
                                                    </form>
                                                @endcan
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center">{{ __('No allocations yet. Open a bill or expense line and use Allocate.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $allocations->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
