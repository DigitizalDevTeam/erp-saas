@extends('layouts.admin')
@section('page-title')
    {{ $data['title'] }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Treasury') }}</li>
    <li class="breadcrumb-item"><a href="{{ route('treasury-reports.index') }}">{{ __('Reports') }}</a></li>
    <li class="breadcrumb-item">{{ $data['title'] }}</li>
@endsection

@section('action-btn')
    <div class="float-end">
        <a href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}" class="btn btn-sm btn-secondary">
            <i class="ti ti-download"></i> {{ __('CSV') }}
        </a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'xlsx']) }}" class="btn btn-sm btn-secondary">
            <i class="ti ti-download"></i> {{ __('XLSX') }}
        </a>
        <a href="{{ request()->fullUrlWithQuery(['export' => 'pdf']) }}" class="btn btn-sm btn-secondary">
            <i class="ti ti-download"></i> {{ __('PDF') }}
        </a>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card"><div class="card-body">
                <p class="text-muted">{{ $data['description'] }}</p>

                <form method="GET" action="{{ route('treasury-reports.show', ['report' => $reportKey]) }}" class="row g-2 mb-3">
                    <div class="col-md-2">
                        <label class="form-label">{{ __('From') }}</label>
                        <input type="date" class="form-control" name="from" value="{{ $filters['from'] }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">{{ __('To') }}</label>
                        <input type="date" class="form-control" name="to" value="{{ $filters['to'] }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">{{ __('Account') }}</label>
                        <select class="form-control" name="account_id">
                            <option value="">{{ __('All accounts') }}</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}" {{ $filters['account_id'] === $account->id ? 'selected' : '' }}>{{ $account->code }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">{{ __('Currency') }}</label>
                        <input type="text" class="form-control" name="currency" value="{{ $filters['currency'] }}" placeholder="MZN" maxlength="3">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">{{ __('State') }}</label>
                        <input type="text" class="form-control" name="state" value="{{ $filters['state'] }}" placeholder="{{ __('Any') }}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">{{ __('Search') }}</label>
                        <input type="text" class="form-control" name="search" value="{{ $filters['search'] }}">
                    </div>
                    @if ($reportKey === 'cash-flow')
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Days') }}</label>
                            <select class="form-control" name="days">
                                @foreach ([7, 15, 30, 90] as $option)
                                    <option value="{{ $option }}" {{ $filters['days'] === $option ? 'selected' : '' }}>{{ $option }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ __('Group by') }}</label>
                            <select class="form-control" name="group">
                                @foreach (['day' => __('By day'), 'week' => __('By week'), 'month' => __('By month')] as $value => $label)
                                    <option value="{{ $value }}" {{ $filters['group'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">{{ __('Scenario') }}</label>
                            <select class="form-control" name="scenario">
                                @foreach (['baseline' => __('Baseline'), 'late_receipts' => __('Late receipts (+7 days)'), 'higher_expenses' => __('Higher expenses (+10%)'), 'earlier_payments' => __('Earlier payments (−7 days)')] as $value => $label)
                                    <option value="{{ $value }}" {{ $filters['scenario'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary">{{ __('Filter') }}</button>
                    </div>
                </form>

                @if (! empty($data['totals']))
                    <p>
                        @foreach ($data['totals'] as $label => $value)
                            <strong>{{ $label }}:</strong> {{ $value }}&nbsp;&nbsp;
                        @endforeach
                    </p>
                @endif

                <div class="table-responsive"><table class="table">
                    <thead><tr>
                        @foreach ($data['headings'] as $heading)
                            <th>{{ $heading }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody>
                    @forelse ($data['rows'] as $row)
                        <tr>
                            @foreach ($row as $cell)
                                <td>{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($data['headings']) }}" class="text-center text-muted">{{ __('No rows match these filters.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
                <p class="text-muted">{{ __('Amounts are shown in') }} {{ $baseCurrency }} {{ __('unless a row names another currency. Totals are in base currency.') }}</p>
            </div></div>
        </div>
    </div>

    @if ($exports->isNotEmpty())
        <div class="row">
            <div class="col-md-12">
                <div class="card"><div class="card-body">
                    <h5>{{ __('Recent exports') }}</h5>
                    <div class="table-responsive"><table class="table">
                        <thead><tr><th>{{ __('Report') }}</th><th>{{ __('Format') }}</th><th>{{ __('Status') }}</th><th>{{ __('Expires') }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($exports as $export)
                            <tr>
                                <td>{{ $export->report }}</td>
                                <td>{{ strtoupper($export->format) }}</td>
                                <td>{{ $export->status }}</td>
                                <td>{{ $export->expires_at }}</td>
                                <td>
                                    @if (isset($downloadUrls[$export->id]))
                                        <a href="{{ $downloadUrls[$export->id] }}" class="btn btn-sm btn-primary">{{ __('Download') }}</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                </div></div>
            </div>
        </div>
    @endif
@endsection
