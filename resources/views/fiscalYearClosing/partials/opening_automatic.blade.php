<h5 class="mb-3">{{ __('Automatic carry-forward') }}</h5>
<p class="text-muted small">
    {{ __('Calculates net closing balances from the previous calendar year and posts them to period 0 on January 1 of the target year.') }}
</p>

{{ Form::open(['route' => 'fiscal-year-closing.index', 'method' => 'get', 'class' => 'mb-3']) }}
{{ Form::hidden('tab', 'opening') }}
{{ Form::hidden('opening_mode', 'automatic') }}
<div class="row align-items-end">
    <div class="col-md-4">
        <div class="form-group mb-md-0">
            {{ Form::label('opening_target_year', __('Target fiscal year'), ['class' => 'form-label']) }}
            {{ Form::number('opening_target_year', $openingTargetYear, ['class' => 'form-control', 'required' => true, 'min' => 2001, 'max' => 2100]) }}
            <small class="text-muted">{{ __('Source year: :y', ['y' => $openingTargetYear - 1]) }}</small>
        </div>
    </div>
    <div class="col-md-4">
        {{ Form::submit(__('Preview opening balances'), ['class' => 'btn btn-outline-primary']) }}
    </div>
</div>
{{ Form::close() }}

@if ($openingPreview !== null)
    <div class="alert alert-{{ $openingPreview['success'] ? 'info' : 'warning' }} mb-3">
        <p class="mb-1">
            {{ __('Source year') }}: <strong>{{ $openingPreview['source_year'] }}</strong>
            · {{ __('Opening date') }}: <strong>{{ $openingPreview['opening_date'] }}</strong>
            · {{ __('Reference') }}: <strong>{{ $openingPreview['reference'] }}</strong>
        </p>
        @if ($openingPreview['already_posted'])
            <p class="mb-0 text-danger">{{ __('Automatic opening balances for this year are already posted.') }}</p>
        @endif
        @foreach ($openingPreview['messages'] as $message)
            <p class="mb-0">{{ $message }}</p>
        @endforeach
    </div>

    @if ($openingPreview['lines'] !== [])
        <div class="table-responsive mb-3">
            <table class="table table-sm table-striped">
                <thead>
                    <tr>
                        <th>{{ __('Code') }}</th>
                        <th>{{ __('Account') }}</th>
                        <th class="text-end">{{ __('Debit') }}</th>
                        <th class="text-end">{{ __('Credit') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($openingPreview['lines'] as $line)
                        <tr>
                            <td>{{ $line['code'] }}</td>
                            <td>{{ $line['name'] }}</td>
                            <td class="text-end">{{ $line['debit'] > 0 ? number_format($line['debit'], 2) : '' }}</td>
                            <td class="text-end">{{ $line['credit'] > 0 ? number_format($line['credit'], 2) : '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td colspan="2">{{ __('Totals') }}</td>
                        <td class="text-end">{{ number_format($openingPreview['total_debit'], 2) }}</td>
                        <td class="text-end">{{ number_format($openingPreview['total_credit'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    @if ($openingPreview['success'])
        {{ Form::open(['route' => 'fiscal-year-closing.opening', 'method' => 'post']) }}
        @csrf
        {{ Form::hidden('opening_target_year', $openingTargetYear) }}
        <div class="form-check mb-3">
            {{ Form::checkbox('allow_duplicate_opening', 1, false, ['class' => 'form-check-input', 'id' => 'allow_duplicate_opening']) }}
            {{ Form::label('allow_duplicate_opening', __('Allow posting even if automatic opening reference already exists (not recommended)'), ['class' => 'form-check-label']) }}
        </div>
        {{ Form::submit(__('Post automatic opening balances'), ['class' => 'btn btn-primary']) }}
        {{ Form::close() }}
    @endif
@endif
