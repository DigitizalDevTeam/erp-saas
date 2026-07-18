<div class="row">
    <div class="col-xl-12">
        <div class="card">
            <div class="card-header pb-0">
                <ul class="nav nav-tabs card-header-tabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link {{ $activeTab === 'opening' ? 'active' : '' }}"
                           href="{{ route('fiscal-year-closing.index', ['tab' => 'opening', 'opening_mode' => $openingMode, 'opening_target_year' => $openingTargetYear]) }}">
                            {{ __('Opening balances (Period 0)') }}
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ $activeTab === 'year-end' ? 'active' : '' }}"
                           href="{{ route('fiscal-year-closing.index', ['tab' => 'year-end']) }}">
                            {{ __('Year-end closing') }}
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ $activeTab === 'vat' ? 'active' : '' }}"
                           href="{{ route('fiscal-year-closing.index', ['tab' => 'vat']) }}">
                            {{ __('VAT settlement') }}
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ $activeTab === 'reference' ? 'active' : '' }}"
                           href="{{ route('fiscal-year-closing.index', ['tab' => 'reference']) }}">
                            {{ __('Reference') }}
                        </a>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                @if ($activeTab === 'opening')
                    @include('fiscalYearClosing.partials.opening')
                @elseif ($activeTab === 'year-end')
                    @include('fiscalYearClosing.partials.year_end')
                @elseif ($activeTab === 'vat')
                    @include('fiscalYearClosing.partials.vat')
                @else
                    @include('fiscalYearClosing.partials.reference')
                @endif
            </div>
        </div>
    </div>
</div>
