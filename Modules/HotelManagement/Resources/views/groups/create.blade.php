@extends('layouts.admin')
@section('page-title'){{ __('Create group reservation') }}@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hotel.dashboard') }}">{{ __('Hotel Management') }}</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hotel.groups.index') }}">{{ __('Groups') }}</a></li>
    <li class="breadcrumb-item">{{ __('Create') }}</li>
@endsection
@section('content')
<div class="card shadow-sm"><div class="card-body">
{!! Form::open(['route' => 'hotel.groups.store', 'id' => 'group_form']) !!}
<div class="row g-2 mb-3">
    <div class="col-md-4">{!! Form::label('name', __('Group name')) !!}{!! Form::text('name', null, ['class' => 'form-control', 'required']) !!}</div>
    <div class="col-md-4">{!! Form::label('company_name', __('Company')) !!}{!! Form::text('company_name', null, ['class' => 'form-control']) !!}</div>
    <div class="col-md-4">{!! Form::label('contact_name', __('Contact person')) !!}{!! Form::text('contact_name', null, ['class' => 'form-control']) !!}</div>
    <div class="col-12">{!! Form::label('notes', __('Group notes')) !!}{!! Form::textarea('notes', null, ['class' => 'form-control', 'rows' => 2]) !!}</div>
</div>
<h6>{{ __('Members & rooms') }}</h6>
<p class="text-muted small">{{ __('Each row creates one guest and one reservation linked to this group. Leave room empty to assign later.') }}</p>
<table class="table table-sm" id="members_table">
    <thead><tr>
        <th>{{ __('First name') }}</th><th>{{ __('Last name') }}</th><th>{{ __('Phone') }}</th>
        <th>{{ __('Room type') }}</th><th>{{ __('Check-in') }}</th><th>{{ __('Check-out') }}</th>
        <th>{{ __('Rate/night') }}</th><th>{{ __('Role') }}</th><th></th>
    </tr></thead>
    <tbody id="members_tbody"></tbody>
</table>
<button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="add_member_row">{{ __('Add row') }}</button>
<button type="submit" class="btn btn-primary">{{ __('Create group') }}</button>
<a href="{{ route('hotel.groups.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
{!! Form::close() !!}

<template id="member_row_tpl">
    <tr class="member-row">
        <td><input type="text" name="members[__I__][first_name]" class="form-control form-control-sm" required></td>
        <td><input type="text" name="members[__I__][last_name]" class="form-control form-control-sm"></td>
        <td><input type="text" name="members[__I__][phone]" class="form-control form-control-sm"></td>
        <td>
            <select name="members[__I__][hotel_room_type_id]" class="form-select form-select-sm" required>
                @foreach($roomTypes as $id => $label)
                    <option value="{{ $id }}">{{ $label }}</option>
                @endforeach
            </select>
        </td>
        <td><input type="datetime-local" name="members[__I__][check_in_at]" class="form-control form-control-sm" required></td>
        <td><input type="datetime-local" name="members[__I__][check_out_at]" class="form-control form-control-sm" required></td>
        <td><input type="number" step="0.01" name="members[__I__][rate_per_night]" class="form-control form-control-sm" required></td>
        <td>
            <select name="members[__I__][role]" class="form-select form-select-sm">
                <option value="adult">{{ __('Adult') }}</option>
                <option value="child">{{ __('Child') }}</option>
            </select>
        </td>
        <td><button type="button" class="btn btn-sm btn-link text-danger rm-row">{{ __('Remove') }}</button></td>
    </tr>
</template>
</div></div>
@endsection
@push('script-page')
<script>
(function () {
    var tbody = document.getElementById('members_tbody');
    var tpl = document.getElementById('member_row_tpl');
    var i = 0;
    function addRow() {
        var html = tpl.innerHTML.replace(/__I__/g, String(i++));
        var wrap = document.createElement('tbody');
        wrap.innerHTML = html.trim();
        tbody.appendChild(wrap.firstChild);
    }
    document.getElementById('add_member_row').addEventListener('click', addRow);
    tbody.addEventListener('click', function (e) {
        if (e.target.classList.contains('rm-row')) {
            e.target.closest('tr').remove();
        }
    });
    addRow();
})();
</script>
@endpush
