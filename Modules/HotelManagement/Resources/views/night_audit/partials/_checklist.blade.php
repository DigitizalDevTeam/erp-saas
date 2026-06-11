@php
    $checklist = $preview['checklist'] ?? [];
    $fmt = fn ($n) => Auth::user()->priceFormat($n);
@endphp
<h6 class="fw-semibold mb-3">{{ __('Pre-audit checklist') }}</h6>
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        @if(($preview['is_closed'] ?? false))
            <div class="alert alert-success small mb-3"><i class="ti ti-lock me-1"></i>{{ __('This business day is already closed.') }}</div>
        @endif

        <div class="row g-3">
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <h6 class="small fw-semibold">{{ __('Open folios') }}</h6>
                    <p class="mb-2">
                        <span class="badge bg-{{ count($checklist['open_folios'] ?? []) > 0 ? 'warning' : 'success' }}">
                            {{ count($checklist['open_folios'] ?? []) }}
                        </span>
                        <span class="small text-muted ms-1">{{ __('Unpaid in-house bills') }}</span>
                    </p>
                    @if(!empty($checklist['open_folios']))
                        <ul class="list-unstyled small mb-0">
                            @foreach($checklist['open_folios'] as $folio)
                                <li class="mb-1">
                                    <a href="{{ $folio['url'] }}">{{ $folio['folio_no'] }}</a>
                                    — {{ $folio['guest'] }} ({{ __('Room') }} {{ $folio['room_no'] ?? '—' }})
                                    <strong>{{ $fmt($folio['balance']) }}</strong>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted small mb-0">{{ __('All in-house folios are settled.') }}</p>
                    @endif
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <h6 class="small fw-semibold">{{ __('Room status anomalies') }}</h6>
                    @if(!empty($checklist['room_anomalies']))
                        <ul class="list-unstyled small mb-0">
                            @foreach($checklist['room_anomalies'] as $a)
                                <li class="mb-1 text-danger">
                                    <strong>{{ __('Room') }} {{ $a['room_no'] }}</strong> — {{ $a['message'] }}
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-muted small mb-0">{{ __('No room status mismatches.') }}</p>
                    @endif
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3 h-100">
                    <h6 class="small fw-semibold">{{ __('Housekeeping') }}</h6>
                    <p class="small mb-1">{{ __('Pending minibar restocks') }}: <strong>{{ $checklist['pending_minibar_restocks'] ?? 0 }}</strong></p>
                    @if(($checklist['pending_minibar_restocks'] ?? 0) > 0)
                        <a href="{{ route('hotel.housekeeping.minibar') }}" class="btn btn-sm btn-outline-primary">{{ __('Daily minibar') }}</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
