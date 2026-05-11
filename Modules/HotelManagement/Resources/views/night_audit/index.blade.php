@extends('layouts.admin')
@section('page-title'){{ __('Night Audit') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Night Audit') }}</li>
@endsection
@section('action-btn')
@if($canManage ?? false)
{!! Form::open(['route' => 'hotel.night-audit.run', 'method' => 'POST', 'class' => 'd-flex align-items-center gap-2']) !!}
<input type="date" name="audit_date" class="form-control form-control-sm" value="{{ date('Y-m-d') }}" required>
<button class="btn btn-sm btn-primary">{{ __('Close Day') }}</button>
{!! Form::close() !!}
@endif
@endsection
@section('content')
<div class="card"><div class="card-body table-border-style"><div class="table-responsive">
<table class="table datatable">
    <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Room Revenue') }}</th><th>{{ __('Service Revenue') }}</th><th>{{ __('Total Revenue') }}</th><th>{{ __('Status') }}</th></tr></thead>
    <tbody>
    @foreach($audits as $audit)
        <tr>
            <td>{{ $audit->audit_date }}</td>
            <td>{{ Auth::user()->priceFormat($audit->room_revenue) }}</td>
            <td>{{ Auth::user()->priceFormat($audit->service_revenue) }}</td>
            <td>{{ Auth::user()->priceFormat($audit->total_revenue) }}</td>
            <td>{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $audit->status))) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</div></div></div>
@endsection
