{{Form::model($user,array('route' => array('users.update', $user->id), 'method' => 'PUT', 'class'=>'needs-validation','novalidate')) }}
<div class="modal-body">
    <div class="row">
        <div class="col-md-6">
            <div class="form-group ">
                {{Form::label('name',__('Name'),['class'=>'form-label']) }}<x-required></x-required>
                {{Form::text('name',null,array('class'=>'form-control font-style','placeholder'=>__('Enter User Name'), 'required' => 'required'))}}
                @error('name')
                <small class="invalid-name" role="alert">
                    <strong class="text-danger">{{ $message }}</strong>
                </small>
                @enderror
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {{Form::label('email',__('Email'),['class'=>'form-label'])}}<x-required></x-required>
                {{Form::text('email',null,array('class'=>'form-control','placeholder'=>__('Enter User Email'), 'required' => 'required'))}}
                @error('email')
                <small class="invalid-email" role="alert">
                    <strong class="text-danger">{{ $message }}</strong>
                </small>
                @enderror
            </div>
        </div>
        @if(\Auth::user()->type != 'super admin')
            <div class="form-group col-md-6">
                {{ Form::label('role', __('User Role'),['class'=>'form-label']) }}<x-required></x-required>
                {!! Form::select('role', $roles, $user->roles->first()->id ?? null, array('class' => 'form-control select','required'=>'required', 'id' => 'user-edit-role')) !!}
                <div class="text-xs mt-1">
                    {{ __('Create role here.') }} <a href="{{ route('roles.index') }}"><b>{{ __('Create role') }}</b></a>
                </div>
                @error('role')
                <small class="invalid-role" role="alert">
                    <strong class="text-danger">{{ $message }}</strong>
                </small>
                @enderror
            </div>
            <div class="form-group col-md-6 pos-branch-div d-none">
                {{ Form::label('pos_branch_id', __('POS Branch (assigned branch for Waiter/Cashier/Manager/Kitchen/Bar)'), ['class' => 'form-label']) }}<x-required></x-required>
                {!! Form::select('pos_branch_id', $branches ?? [], $user->pos_branch_id, ['class' => 'form-control select', 'placeholder' => __('Select Branch')]) !!}
                <small class="text-muted">{{ __('This user will only use this branch in POS.') }}</small>
                @error('pos_branch_id')
                <small class="invalid-feedback d-block"><strong class="text-danger">{{ $message }}</strong></small>
                @enderror
            </div>
            <div class="form-group col-md-6 pos-pin-div d-none">
                {{ Form::label('pos_pin', __('Restaurant POS PIN'), ['class' => 'form-label']) }}
                {{ Form::text('pos_pin', $user->pos_pin ?? null, ['class' => 'form-control', 'placeholder' => __('4-8 digits'), 'maxlength' => 8, 'autocomplete' => 'off']) }}
                <small class="text-muted">{{ __('Used to log in on shared Restaurant POS.') }}</small>
                @error('pos_pin')
                <small class="invalid-feedback d-block"><strong class="text-danger">{{ $message }}</strong></small>
                @enderror
            </div>
            @if(!empty($tenantBranches) && $tenantBranches->count())
            <div class="form-group col-12">
                <label class="form-label">{{ __('Allowed branches') }}</label>
                <small class="text-muted d-block mb-2">{{ __('Leave all unchecked to allow every branch. Select branches to restrict this user to those locations.') }}</small>
                <div class="row">
                    @foreach($tenantBranches as $tb)
                    <div class="col-md-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="branch_ids[]" value="{{ $tb->id }}" id="cb-branch-edit-{{ $tb->id }}" {{ $user->branches->contains('id', $tb->id) ? 'checked' : '' }}>
                            <label class="form-check-label" for="cb-branch-edit-{{ $tb->id }}">{{ $tb->name }}</label>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
        @endif
        @if(!$customFields->isEmpty())
                    @include('customFields.formBuilder')
        @endif
    </div>

</div>

<div class="modal-footer">
    <input type="button" value="{{__('Cancel')}}" class="btn  btn-secondary"data-bs-dismiss="modal">
    <input type="submit" value="{{__('Update')}}" class="btn  btn-primary">
</div>

{{Form::close()}}
@if(!empty($posBranchRoleNames))
@php
    $posBranchRoleIds = collect($roles ?? [])
        ->filter(fn ($name) => in_array($name, $posBranchRoleNames, true))
        ->keys()
        ->map(fn ($id) => (string) $id)
        ->values()
        ->all();
@endphp
<script>
(function() {
    var posRoleIds = @json($posBranchRoleIds);
    function togglePosBranch() {
        var sel = document.getElementById('user-edit-role');
        var branchDiv = document.querySelector('.pos-branch-div');
        var pinDiv = document.querySelector('.pos-pin-div');
        if (!sel) return;
        var branchSelect = branchDiv ? branchDiv.querySelector('select[name="pos_branch_id"]') : null;
        var showByRole = posRoleIds.indexOf(String(sel.value)) !== -1;
        var showByExistingValue = !!(branchSelect && branchSelect.value);
        var show = showByRole || showByExistingValue;
        if (branchDiv) {
            if (show) { branchDiv.classList.remove('d-none'); branchDiv.querySelector('select').setAttribute('required', 'required'); }
            else { branchDiv.classList.add('d-none'); branchDiv.querySelector('select').removeAttribute('required'); }
        }
        if (pinDiv) { if (showByRole) pinDiv.classList.remove('d-none'); else pinDiv.classList.add('d-none'); }
    }
    document.getElementById('user-edit-role').addEventListener('change', togglePosBranch);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', togglePosBranch);
    else togglePosBranch();
})();
</script>
@endif
