@extends('layouts.admin')
@section('page-title')
    {{ __('GL Dimensions') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('cost-centres.index') }}">{{ __('Cost Centres') }}</a></li>
    <li class="breadcrumb-item">{{ __('GL Dimensions') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body">
                    <p class="text-muted">{{ __('Tag posted journal lines with cost centres. Dimensions sit beside the money: tagging never changes a journal amount. Bill lines are dimensioned by syncing their Phase 1 allocation — see the unassigned report for bills that still need syncing.') }}</p>

                    <div class="mb-3">
                        <a href="{{ route('gl-dimensions.reverse') }}" class="btn btn-outline-primary">{{ __('Reverse journal dimensions') }}</a>
                        <a href="{{ route('cost-centre-reports.expense') }}" class="btn btn-outline-secondary">{{ __('Expense by centre') }}</a>
                        <a href="{{ route('cost-centre-reports.reconciliation') }}" class="btn btn-outline-secondary">{{ __('Reconciliation') }}</a>
                        <a href="{{ route('gl-dimensions.index', ['export' => 'csv']) }}" class="btn btn-outline-secondary">{{ __('Export CSV') }}</a>
                        <a href="{{ route('gl-dimensions.index', ['export' => 'xlsx']) }}" class="btn btn-outline-secondary">{{ __('Export XLSX') }}</a>
                        <a href="{{ route('gl-dimensions.index', ['export' => 'pdf']) }}" class="btn btn-outline-secondary">{{ __('Export PDF') }}</a>
                    </div>

                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{{ __('Journal') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Reference') }}</th>
                                    <th>{{ __('Description') }}</th>
                                    <th>{{ __('Lines') }}</th>
                                    <th>{{ __('Tagged') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($journals as $journal)
                                    @php $cov = $coverage[(int) $journal->id] ?? ['lines' => 0, 'tagged' => 0]; @endphp
                                    <tr>
                                        <td>#{{ $journal->journal_id }}</td>
                                        <td>{{ $journal->date }}</td>
                                        <td>{{ $journal->reference }}</td>
                                        <td>{{ $journal->description }}</td>
                                        <td>{{ $cov['lines'] }}</td>
                                        <td>
                                            {{ $cov['tagged'] }} / {{ $cov['lines'] }}
                                            @if ($cov['lines'] > 0 && $cov['tagged'] < $cov['lines'])
                                                <span class="badge bg-warning">{{ __('Untagged') }}</span>
                                            @elseif ($cov['lines'] > 0)
                                                <span class="badge bg-success">{{ __('Tagged') }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            <a href="{{ route('gl-dimensions.journal.edit', $journal->id) }}" class="btn btn-sm btn-outline-primary">{{ __('Tag lines') }}</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted">{{ __('No journals yet.') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $journals->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
