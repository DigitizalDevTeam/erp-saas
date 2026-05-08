{{ Form::open(['url' => 'users', 'method' => 'post', 'class'=>'needs-validation', 'novalidate']) }}
<div class="modal-body">
    <div class="row">
        @if (\Auth::user()->type == 'super admin')
            <div class="col-md-6">
                <div class="form-group">
                    {{ Form::label('name', __('Name'), ['class' => 'form-label']) }}<x-required></x-required>
                    {{ Form::text('name', null, ['class' => 'form-control', 'placeholder' => __('Enter Company Name'), 'required' => 'required']) }}
                    @error('name')
                        <small class="invalid-name" role="alert">
                            <strong class="text-danger">{{ $message }}</strong>
                        </small>
                    @enderror
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    {{ Form::label('email', __('Email'), ['class' => 'form-label']) }}<x-required></x-required>
                    {{ Form::email('email', null, ['class' => 'form-control', 'placeholder' => __('Enter Company Email'), 'required' => 'required']) }}
                    @error('email')
                        <small class="invalid-email" role="alert">
                            <strong class="text-danger">{{ $message }}</strong>
                        </small>
                    @enderror
                </div>
            </div>


            {!! Form::hidden('role', 'company', null, ['class' => 'form-control select2', 'required' => 'required']) !!}
            <div class="col-md-6 mb-3 form-group mt-4">
                <label for="password_switch">{{ __('Login is enable') }}</label>
                <div class="form-check form-switch custom-switch-v1 float-end">
                    <input type="checkbox" name="password_switch" class="form-check-input input-primary pointer" value="on" id="password_switch">
                    <label class="form-check-label" for="password_switch"></label>
                </div>
            </div>
            <div class="col-md-6 ps_div d-none">
                <div class="form-group">
                    {{ Form::label('password', __('Password'), ['class' => 'form-label']) }}<x-required></x-required>
                    {{ Form::password('password', ['class' => 'form-control', 'placeholder' => __('Enter Company Password'), 'minlength' => '6']) }}
                    @error('password')
                        <small class="invalid-password" role="alert">
                            <strong class="text-danger">{{ $message }}</strong>
                        </small>
                    @enderror
                </div>
            </div>
        @else
            <div class="col-md-6">
                <div class="form-group">
                    {{ Form::label('name', __('Name'), ['class' => 'form-label']) }}<x-required></x-required>
                    {{ Form::text('name', null, ['class' => 'form-control', 'placeholder' => __('Enter User Name'), 'required' => 'required']) }}
                    @error('name')
                        <small class="invalid-name" role="alert">
                            <strong class="text-danger">{{ $message }}</strong>
                        </small>
                    @enderror
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    {{ Form::label('email', __('Email'), ['class' => 'form-label']) }}<x-required></x-required>
                    {{ Form::email('email', null, ['class' => 'form-control', 'placeholder' => __('Enter User Email'), 'required' => 'required', 'id' => 'user-create-email']) }}
                    @error('email')
                        <small class="invalid-email" role="alert">
                            <strong class="text-danger">{{ $message }}</strong>
                        </small>
                    @enderror
                    <small id="existing-user-message" class="text-warning d-none mt-1 d-block"></small>
                    <small id="existing-user-error" class="text-danger d-none mt-1 d-block"></small>
                </div>
            </div>
            <div class="form-group col-md-6">
                {{ Form::label('role', __('User Role'), ['class' => 'form-label']) }}<x-required></x-required>
                {!! Form::select('role', $roles, null, ['class' => 'form-control select', 'required' => 'required', 'id' => 'user-create-role']) !!}
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
                {!! Form::select('pos_branch_id', $branches ?? [], null, ['class' => 'form-control select', 'placeholder' => __('Select Branch')]) !!}
                <small class="text-muted">{{ __('This user will only use this branch in POS; they cannot change branch.') }}</small>
                @error('pos_branch_id')
                    <small class="invalid-feedback d-block"><strong class="text-danger">{{ $message }}</strong></small>
                @enderror
            </div>
            <div class="form-group col-md-6 pos-pin-div d-none">
                {{ Form::label('pos_pin', __('Restaurant POS PIN'), ['class' => 'form-label']) }}
                {{ Form::text('pos_pin', null, ['class' => 'form-control', 'placeholder' => __('4-8 digits'), 'maxlength' => 8, 'autocomplete' => 'off']) }}
                <small class="text-muted">{{ __('Used to log in on shared Restaurant POS; leave empty to skip PIN login.') }}</small>
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
                            <input class="form-check-input" type="checkbox" name="branch_ids[]" value="{{ $tb->id }}" id="cb-branch-create-{{ $tb->id }}">
                            <label class="form-check-label" for="cb-branch-create-{{ $tb->id }}">{{ $tb->name }}</label>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif
            <div class="col-md-5 mb-3 form-group mt-4 login-enable-wrap">
                <label for="password_switch">{{ __('Login is enable') }}</label>
                <div class="form-check form-switch custom-switch-v1 float-end">
                    <input type="checkbox" name="password_switch" class="form-check-input input-primary pointer" value="on" id="password_switch">
                    <label class="form-check-label" for="password_switch"></label>
                </div>
            </div>
            <div class="col-md-6 ps_div d-none">
                <div class="form-group">
                    {{ Form::label('password', __('Password'), ['class' => 'form-label']) }}<x-required></x-required>
                    {{ Form::password('password', ['class' => 'form-control', 'placeholder' => __('Enter Company Password'), 'minlength' => '6']) }}
                    @error('password')
                        <small class="invalid-password" role="alert">
                            <strong class="text-danger">{{ $message }}</strong>
                        </small>
                    @enderror
                </div>
            </div>
        @endif
        @if (!$customFields->isEmpty())
                    @include('customFields.formBuilder')
        @endif
    </div>

</div>

<div class="modal-footer">
    <input type="button" value="{{ __('Cancel') }}" class="btn  btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{ __('Create') }}" class="btn  btn-primary">
</div>

{{ Form::close() }}
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
        var sel = document.getElementById('user-create-role');
        var branchDiv = document.querySelector('.pos-branch-div');
        var pinDiv = document.querySelector('.pos-pin-div');
        if (!sel) return;
        var show = posRoleIds.indexOf(String(sel.value)) !== -1;
        if (branchDiv) {
            if (show) { branchDiv.classList.remove('d-none'); branchDiv.querySelector('select').setAttribute('required', 'required'); }
            else { branchDiv.classList.add('d-none'); branchDiv.querySelector('select').removeAttribute('required'); branchDiv.querySelector('select').value = ''; }
        }
        if (pinDiv) { if (show) pinDiv.classList.remove('d-none'); else { pinDiv.classList.add('d-none'); pinDiv.querySelector('input[name=pos_pin]').value = ''; } }
    }
    document.getElementById('user-create-role').addEventListener('change', togglePosBranch);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', togglePosBranch);
    else togglePosBranch();
})();
</script>
@endif
<script>
(function() {
    var emailInput = document.getElementById('user-create-email');
    if (!emailInput) return;
    var passwordSwitch = document.getElementById('password_switch');
    var passwordWrap = document.querySelector('.ps_div');
    var loginEnableWrap = document.querySelector('.login-enable-wrap');
    var msg = document.getElementById('existing-user-message');
    var err = document.getElementById('existing-user-error');
    var checkUrl = @json(route('user.exists'));
    var timer = null;

    function showAsExisting(message) {
        if (msg) { msg.textContent = message; msg.classList.remove('d-none'); }
        if (err) { err.classList.add('d-none'); err.textContent = ''; }
        if (passwordSwitch) {
            passwordSwitch.checked = false;
            passwordSwitch.setAttribute('disabled', 'disabled');
        }
        if (loginEnableWrap) loginEnableWrap.classList.add('d-none');
        if (passwordWrap) {
            passwordWrap.classList.add('d-none');
            var p = passwordWrap.querySelector('input[name=password]');
            if (p) p.value = '';
        }
    }

    function showAsNewUser() {
        if (msg) { msg.classList.add('d-none'); msg.textContent = ''; }
        if (err) { err.classList.add('d-none'); err.textContent = ''; }
        if (passwordSwitch) passwordSwitch.removeAttribute('disabled');
        if (loginEnableWrap) loginEnableWrap.classList.remove('d-none');
    }

    function showAsBlocked(message) {
        if (msg) { msg.classList.add('d-none'); msg.textContent = ''; }
        if (err) { err.textContent = message; err.classList.remove('d-none'); }
        if (passwordSwitch) {
            passwordSwitch.checked = false;
            passwordSwitch.setAttribute('disabled', 'disabled');
        }
        if (loginEnableWrap) loginEnableWrap.classList.add('d-none');
        if (passwordWrap) {
            passwordWrap.classList.add('d-none');
            var p = passwordWrap.querySelector('input[name=password]');
            if (p) p.value = '';
        }
    }

    async function checkExisting() {
        var email = (emailInput.value || '').trim();
        if (!email || email.indexOf('@') === -1) {
            showAsNewUser();
            return;
        }
        try {
            var res = await fetch(checkUrl + '?email=' + encodeURIComponent(email), {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            if (!res.ok) {
                showAsNewUser();
                return;
            }
            var data = await res.json();
            if (data.exists && data.allowed === false) {
                showAsBlocked(data.message || '{{ __('This email cannot be used here.') }}');
                return;
            }
            if (data.exists) {
                showAsExisting(data.message || '{{ __('User already exists. Password will not be changed.') }}');
                return;
            }
            showAsNewUser();
        } catch (e) {
            showAsNewUser();
        }
    }

    emailInput.addEventListener('input', function() {
        clearTimeout(timer);
        timer = setTimeout(checkExisting, 350);
    });
    emailInput.addEventListener('blur', checkExisting);
})();
</script>
