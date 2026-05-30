<div class="card shadow-sm border-0 h-100 hotel-night-audit-procedure">
    <div class="card-body p-3">
        <p class="text-uppercase text-muted small fw-semibold mb-3 mb-lg-4">{{ __('Procedure: Night Audit (Reports)') }}</p>
        <h6 class="fw-bold mb-3">{{ __('Simple steps') }}</h6>
        <ul class="list-unstyled hotel-night-audit-steps mb-4">
            @foreach([
                ['icon' => 'ti-report', 'text' => __('Access the Reports module.')],
                ['icon' => 'ti-calendar', 'text' => __('Select the audit date.')],
                ['icon' => 'ti-refresh', 'text' => __('Generate the audit.')],
                ['icon' => 'ti-chart-bar', 'text' => __('Verify the General Summary.')],
                ['icon' => 'ti-list-details', 'text' => __('Verify Report Details.')],
                ['icon' => 'ti-checkbox', 'text' => __('Check and Confirm.')],
                ['icon' => 'ti-printer', 'text' => __('Print / Export Reports.')],
            ] as $i => $step)
                <li class="d-flex gap-2 mb-3">
                    <span class="step-num">{{ $i + 1 }}</span>
                    <div>
                        <i class="ti {{ $step['icon'] }} text-primary me-1"></i>
                        <span class="small">{{ $step['text'] }}</span>
                    </div>
                </li>
            @endforeach
        </ul>
        <div class="alert alert-success border-0 small mb-0 hotel-night-audit-rules">
            <strong class="d-block mb-2">{{ __('Important rules') }}</strong>
            <ul class="mb-0 ps-3">
                <li>{{ __('Run the night audit daily before opening the next business day.') }}</li>
                <li>{{ __('Verify all departures and payments before confirming.') }}</li>
                <li>{{ __('After confirmation, the audit date is locked for editing.') }}</li>
            </ul>
        </div>
    </div>
</div>
