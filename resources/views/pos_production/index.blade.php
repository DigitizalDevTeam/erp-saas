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
                    <form method="get" class="row g-3 align-items-end mb-4">
                        <div class="col-auto">
                            <label class="form-label">{{ __('Branch') }}</label>
                            <select name="branch_id" class="form-select form-select-sm" style="min-width:180px;">
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}" {{ (string)$b->id === (string)$branchId ? 'selected' : '' }}>{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-auto">
                            <label class="form-label">{{ __('Zone') }}</label>
                            <select name="zone" class="form-select form-select-sm" style="min-width:140px;">
                                <option value="kitchen" {{ $zone === 'kitchen' ? 'selected' : '' }}>{{ __('Kitchen') }}</option>
                                <option value="bar" {{ $zone === 'bar' ? 'selected' : '' }}>{{ __('Bar') }}</option>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-primary"><i class="ti ti-refresh"></i> {{ __('Apply') }}</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
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
                                    <tr data-line-id="{{ $line->id }}">
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
                                        </td>
                                        <td><small>{{ $line->sent_at ? $line->sent_at->format('H:i') : '—' }}</small></td>
                                        <td><small>@if($line->ready_at && $line->sent_at){{ $line->sent_at->diffInMinutes($line->ready_at) }} min @else — @endif</small></td>
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
