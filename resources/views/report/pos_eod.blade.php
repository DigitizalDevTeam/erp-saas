@extends('layouts.admin')
@section('page-title')
    {{ __('End of Day (EOD) Report') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('pos-shift.index') }}">{{ __('POS Shifts') }}</a></li>
    <li class="breadcrumb-item">{{ __('End of Day Report') }}</li>
@endsection

@section('content')
    @if(session('success'))
        <div class="alert alert-success mb-3">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger mb-3">{{ session('error') }}</div>
    @endif
    @if(!empty($openShiftsForDate) && $openShiftsForDate->count() > 0)
        <div class="alert alert-warning mb-3">
            <strong>{{ __('Shift(s) still open') }}</strong>: {{ __('Please close all shifts before closing the day.') }}
            <ul class="mb-0 mt-1">
                @foreach($openShiftsForDate ?? [] as $s)
                    <li>{{ $s->branch ? $s->branch->name : '-' }} — {{ $s->user ? $s->user->name : '-' }} ({{ __('opened') }} {{ $s->opened_at ? $s->opened_at->format('H:i') : '-' }})</li>
                @endforeach
            </ul>
        </div>
    @endif
    @if(!empty($isDayClosed))
        <div class="alert alert-info mb-3">{{ __('Day is closed. No new shifts can be opened for this date until an administrator reopens.') }}</div>
    @endif
    <div class="row mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="row g-2 align-items-end">
                        {{ Form::open(['route' => 'report.pos.eod', 'method' => 'get', 'class' => 'col-auto d-flex flex-wrap g-2 align-items-end gap-2']) }}
                        <div>
                            {{ Form::label('date', __('Date'), ['class' => 'form-label']) }}
                            {{ Form::date('date', $date, ['class' => 'form-control']) }}
                        </div>
                        <div class="align-self-end">
                            <button type="submit" class="btn btn-primary">{{ __('Show') }}</button>
                        </div>
                        {{ Form::close() }}
                        <div class="col-auto ms-auto d-flex flex-wrap gap-2 align-items-end">
                            <a href="{{ route('report.pos.eod.export', ['date' => $date]) }}" class="btn btn-success"><i class="ti ti-file-spreadsheet"></i> {{ __('Export Excel') }}</a>
                            <a href="{{ route('report.pos.eod', ['date' => $date, 'print' => 'a4']) }}" target="_blank" rel="noopener" class="btn btn-secondary"><i class="ti ti-file-text"></i> {{ __('Print A4') }}</a>
                            <a href="{{ route('report.pos.eod', ['date' => $date, 'print' => '80']) }}" target="_blank" rel="noopener" class="btn btn-outline-secondary"><i class="ti ti-receipt"></i> {{ __('Print 80mm Slip') }}</a>
                            <a href="{{ route('report.pos.eod.download', ['date' => $date]) }}" class="btn btn-outline-primary"><i class="ti ti-file-download"></i> {{ __('Download PDF') }}</a>
                            {{ Form::open(['route' => 'report.pos.eod.send', 'method' => 'post', 'class' => 'd-inline', 'id' => 'eod-send-email-form']) }}
                            {{ Form::hidden('date', $date) }}
                            @csrf
                            <button type="submit" class="btn btn-primary" id="eod-send-email-btn">
                                <i class="ti ti-mail"></i> {{ __('Send Email') }}
                            </button>
                            {{ Form::close() }}
                            @if(!empty($canCloseDay))
                                @if(empty($isDayClosed))
                                    @if(empty($openShiftsForDate) || $openShiftsForDate->count() === 0)
                                        {{ Form::open(['route' => 'report.pos.close.day', 'method' => 'post', 'class' => 'd-inline']) }}
                                        {{ Form::hidden('date', $date) }}
                                        @csrf
                                        <button type="submit" class="btn btn-danger"><i class="ti ti-lock"></i> {{ __('Close day') }}</button>
                                        {{ Form::close() }}
                                    @endif
                                @else
                                    {{ Form::open(['route' => 'report.pos.reopen.day', 'method' => 'post', 'class' => 'd-inline']) }}
                                    {{ Form::hidden('date', $date) }}
                                    @csrf
                                    <button type="submit" class="btn btn-outline-success"><i class="ti ti-lock-open"></i> {{ __('Reopen day') }}</button>
                                    {{ Form::close() }}
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row no-print">
        <div class="col-12">
            <p class="text-muted small">{{ __('Use Print A4 for the professional report or Print 80mm Slip for the thermal receipt. Use Export Excel to download as spreadsheet. Use Send Email to email the full EOD report PDF to Report Received At recipients.') }}</p>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body eod-a4-preview">
                    @include('report.partials.pos_eod_a4_content')
                </div>
            </div>
        </div>
    </div>

@endsection

@push('script-page')
<script>
document.addEventListener('DOMContentLoaded', function() {
    var sendForm = document.getElementById('eod-send-email-form');
    var sendBtn = document.getElementById('eod-send-email-btn');
    if (sendForm && sendBtn) {
        sendForm.addEventListener('submit', function () {
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="ti ti-loader"></i> ' + @json(__('Sending...'));
        });
    }
});
</script>
@endpush

@push('css-page')
@include('report.partials.pos_eod_a4_styles')
<style>
    .eod-a4-preview {
        max-width: 920px;
        margin: 0 auto;
        background: #fff;
    }
    .eod-a4-preview .eod-a4-page {
        padding: 10px 4px;
    }
</style>
@endpush
