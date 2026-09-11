@extends('layouts.admin')
@section('page-title')
    {{ __('Production') }} ({{ $zone === 'kitchen' ? __('Kitchen') : __('Bar') }})
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos.report') }}">{{ __('POS') }}</a></li>
    <li class="breadcrumb-item">{{ __('Production') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    @if(!empty($showBranchSelector) || !empty($showZoneSelector))
                    <form method="get" class="row g-3 align-items-end mb-4">
                        @if(!empty($showBranchSelector))
                        <div class="col-auto">
                            <label class="form-label">{{ __('Branch') }}</label>
                            <select name="branch_id" class="form-select form-select-sm" style="min-width:180px;">
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}" {{ (string)$b->id === (string)$branchId ? 'selected' : '' }}>{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        @else
                            <input type="hidden" name="branch_id" value="{{ $branchId }}">
                        @endif
                        @if(!empty($showZoneSelector))
                        <div class="col-auto">
                            <label class="form-label">{{ __('Zone') }}</label>
                            <select name="zone" class="form-select form-select-sm" style="min-width:140px;">
                                <option value="kitchen" {{ $zone === 'kitchen' ? 'selected' : '' }}>{{ __('Kitchen') }}</option>
                                <option value="bar" {{ $zone === 'bar' ? 'selected' : '' }}>{{ __('Bar') }}</option>
                            </select>
                        </div>
                        @else
                            <input type="hidden" name="zone" value="{{ $zone }}">
                        @endif
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-refresh"></i> {{ __('Apply') }}</button>
                        </div>
                    </form>
                    @endif

                    <div class="alert alert-info py-2 mb-3 small">
                        <i class="ti ti-info-circle me-1"></i> {{ __('pos.kitchen_display_info') }}
                    </div>
                    <div id="pos-prep-overdue-banner" class="alert alert-danger py-2 mb-3 small d-none">
                        <i class="ti ti-alert-triangle me-1"></i>
                        <span id="pos-prep-overdue-text"></span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="pos-production-table">
                            <thead>
                                <tr>
                                    <th>{{ __('Table') }}</th>
                                    <th>{{ __('Product') }}</th>
                                    <th>{{ __('Qty') }}</th>
                                    <th>{{ __('Notes') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th>{{ __('Sent') }}</th>
                                    <th>{{ __('Prep time') }}</th>
                                    <th width="200">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($lines as $line)
                                    @php
                                        $isOverdue = $line->isPrepOverdue();
                                        $remaining = $line->prepRemainingMinutes();
                                        $elapsed = $line->prepElapsedMinutes();
                                        $target = (int) ($line->prep_minutes ?? 0);
                                    @endphp
                                    <tr data-line-id="{{ $line->id }}"
                                        data-sent-at="{{ $line->sent_at ? $line->sent_at->toIso8601String() : '' }}"
                                        data-prep-minutes="{{ $target }}"
                                        data-ready-at="{{ $line->ready_at ? $line->ready_at->toIso8601String() : '' }}"
                                        data-status="{{ $line->status }}"
                                        class="{{ $isOverdue ? 'table-danger' : '' }}">
                                        <td>{{ $line->pos && $line->pos->table ? $line->pos->table->name : '—' }}</td>
                                        <td>{{ $line->product ? $line->product->name : ($line->product_id ?? '—') }}</td>
                                        <td>{{ $line->quantity }}</td>
                                        <td><small class="text-muted">{{ $line->description ?: '—' }}</small></td>
                                        <td>
                                            @if($line->status === \App\Models\PosProduct::STATUS_PENDING)
                                                <span class="badge bg-warning text-dark">{{ __('Pending') }}</span>
                                            @elseif($line->status === \App\Models\PosProduct::STATUS_IN_PREPARATION)
                                                <span class="badge bg-info">{{ __('In Preparation') }}</span>
                                            @else
                                                <span class="badge bg-success">{{ __('Ready') }}</span>
                                            @endif
                                            @if($isOverdue)
                                                <span class="badge bg-danger ms-1 pos-prep-overdue-badge">{{ __('Overtime') }}</span>
                                            @endif
                                        </td>
                                        <td><small>{{ $line->sent_at ? $line->sent_at->format('H:i') : '—' }}</small></td>
                                        <td class="pos-prep-cell">
                                            @if($line->ready_at && $line->sent_at)
                                                <small>{{ $elapsed }} min
                                                    @if($target > 0)
                                                        / {{ $target }} min
                                                    @endif
                                                </small>
                                            @elseif($target > 0 && $line->sent_at)
                                                <small class="pos-prep-live" data-remaining="{{ $remaining }}">
                                                    @if($isOverdue)
                                                        <span class="text-danger fw-semibold">+{{ abs((int)$remaining) }} min {{ __('over') }}</span>
                                                    @else
                                                        {{ max(0, (int)$remaining) }} min {{ __('left') }}
                                                    @endif
                                                    <span class="text-muted">({{ $target }} min)</span>
                                                </small>
                                            @else
                                                <small>—</small>
                                            @endif
                                        </td>
                                        <td>
                                            @if($line->status === \App\Models\PosProduct::STATUS_PENDING)
                                                <button type="button" class="btn btn-sm btn-outline-info pos-production-set-status" data-id="{{ $line->id }}" data-status="in_preparation"><i class="ti ti-chef-hat"></i> {{ __('In preparation') }}</button>
                                            @endif
                                            @if(in_array($line->status, [\App\Models\PosProduct::STATUS_PENDING, \App\Models\PosProduct::STATUS_IN_PREPARATION]))
                                                <button type="button" class="btn btn-sm btn-success pos-production-set-status" data-id="{{ $line->id }}" data-status="ready"><i class="ti ti-check"></i> {{ __('Ready') }}</button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">{{ __('No orders for this zone.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script-page')
<script>
$(function() {
    var updateLineUrl = "{{ route('pos.production.line.update', ['line' => '__ID__']) }}";
    var csrf = "{{ csrf_token() }}";
    var overdueMsg = @json(__('Preparation overtime: :count order(s) exceeded prep time before Ready.'));
    var alertedIds = {};

    function refreshPrepTimers() {
        var overdueCount = 0;
        var newlyOverdue = [];
        $('#pos-production-table tbody tr[data-line-id]').each(function () {
            var $tr = $(this);
            var sentAt = $tr.attr('data-sent-at');
            var prepMinutes = parseInt($tr.attr('data-prep-minutes'), 10) || 0;
            var readyAt = $tr.attr('data-ready-at');
            var status = $tr.attr('data-status');
            var lineId = $tr.attr('data-line-id');
            if (!sentAt || prepMinutes < 1 || readyAt || status === 'ready' || status === 'served' || status === 'cancelled') {
                return;
            }
            var due = new Date(sentAt).getTime() + (prepMinutes * 60 * 1000);
            var remaining = Math.round((due - Date.now()) / 60000);
            var $cell = $tr.find('.pos-prep-live');
            if ($cell.length) {
                if (remaining < 0) {
                    $cell.html('<span class="text-danger fw-semibold">+' + Math.abs(remaining) + ' min {{ __("over") }}</span> <span class="text-muted">(' + prepMinutes + ' min)</span>');
                    $tr.addClass('table-danger');
                    if ($tr.find('.pos-prep-overdue-badge').length === 0) {
                        $tr.find('td').eq(4).append(' <span class="badge bg-danger ms-1 pos-prep-overdue-badge">{{ __("Overtime") }}</span>');
                    }
                    overdueCount++;
                    if (!alertedIds[lineId]) {
                        alertedIds[lineId] = true;
                        newlyOverdue.push(lineId);
                    }
                } else {
                    $cell.html(remaining + ' min {{ __("left") }} <span class="text-muted">(' + prepMinutes + ' min)</span>');
                    $tr.removeClass('table-danger');
                    $tr.find('.pos-prep-overdue-badge').remove();
                }
            } else if (remaining < 0) {
                overdueCount++;
            }
        });
        var $banner = $('#pos-prep-overdue-banner');
        if (overdueCount > 0) {
            $('#pos-prep-overdue-text').text(overdueMsg.replace(':count', overdueCount));
            $banner.removeClass('d-none');
            if (newlyOverdue.length) {
                show_toastr('{{ __("Alert") }}', overdueMsg.replace(':count', newlyOverdue.length), 'error');
            }
        } else {
            $banner.addClass('d-none');
        }
    }

    refreshPrepTimers();
    setInterval(refreshPrepTimers, 15000);
    setInterval(function () { location.reload(); }, 60000);

    $(document).on('click', '.pos-production-set-status', function() {
        var btn = $(this);
        var id = btn.data('id');
        var status = btn.data('status');
        $.ajax({
            url: updateLineUrl.replace('__ID__', id),
            type: 'PATCH',
            data: { _token: csrf, status: status, _method: 'PATCH' },
            success: function(res) {
                if (res.success) {
                    location.reload();
                }
            },
            error: function(xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.error) ? xhr.responseJSON.error : '{{ __("Error") }}';
                show_toastr('{{ __("Error") }}', msg, 'error');
            }
        });
    });
});
</script>
@endpush
