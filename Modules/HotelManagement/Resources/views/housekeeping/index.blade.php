@extends('layouts.admin')
@section('page-title'){{ __('Housekeeping') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item">{{ __('Housekeeping') }}</li>
@endsection
@section('action-btn')
@if($canManage ?? false)
<a href="{{ route('hotel.housekeeping.create') }}" class="btn btn-sm btn-primary"><i class="ti ti-plus"></i> {{ __('Add Task') }}</a>
@endif
@endsection
@section('content')
<div class="card"><div class="card-body table-border-style"><div class="table-responsive">
<table class="table datatable">
    <thead><tr><th>{{ __('Room') }}</th><th>{{ __('Assigned To') }}</th><th>{{ __('Status') }}</th><th>{{ __('Notes') }}</th><th>{{ __('Action') }}</th></tr></thead>
    <tbody>
    @foreach($tasks as $task)
        <tr>
            <td>{{ $task->room->room_no ?? '-' }}</td>
            <td>{{ $task->assignee->name ?? '-' }}</td>
            <td>{{ __(\Illuminate\Support\Str::title(str_replace('_', ' ', $task->status))) }}</td>
            <td>{{ $task->notes }}</td>
            <td>
                @if($canManage ?? false)
                <a class="btn btn-sm btn-info" href="{{ route('hotel.housekeeping.edit', $task->id) }}"><i class="ti ti-pencil text-white"></i></a>
                @else
                <span class="text-muted small">{{ __('View only') }}</span>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div></div></div>
@endsection
