<p class="text-muted small">
    {{ __('Period 0 holds opening balances for the new fiscal year. Balance-sheet accounts (classes 1–5) only. View in Trial Balance with period 0 selected.') }}
</p>

<ul class="nav nav-pills mb-4" role="tablist">
    <li class="nav-item">
        <a class="nav-link {{ $openingMode === 'automatic' ? 'active' : '' }}"
           href="{{ route('fiscal-year-closing.index', ['tab' => 'opening', 'opening_mode' => 'automatic', 'opening_target_year' => $openingTargetYear]) }}">
            {{ __('Automatic carry-forward') }}
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ $openingMode === 'manual' ? 'active' : '' }}"
           href="{{ route('fiscal-year-closing.index', ['tab' => 'opening', 'opening_mode' => 'manual', 'opening_target_year' => $openingTargetYear]) }}">
            {{ __('Manual entry') }}
        </a>
    </li>
</ul>

@if ($openingMode === 'automatic')
    @include('fiscalYearClosing.partials.opening_automatic')
@else
    @include('fiscalYearClosing.partials.opening_manual')
@endif
