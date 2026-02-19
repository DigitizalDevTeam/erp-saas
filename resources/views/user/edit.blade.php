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
<script>
(function() {
    var posRoleNames = @json($posBranchRoleNames);
    function togglePosBranch() {
        var sel = document.getElementById('user-edit-role');
        var div = document.querySelector('.pos-branch-div');
        if (!sel || !div) return;
        var text = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '';
        if (posRoleNames.indexOf(text) !== -1) {
            div.classList.remove('d-none');
            div.querySelector('select').setAttribute('required', 'required');
        } else {
            div.classList.add('d-none');
            div.querySelector('select').removeAttribute('required');
            div.querySelector('select').value = '';
        }
    }
    document.getElementById('user-edit-role').addEventListener('change', togglePosBranch);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', togglePosBranch);
    else togglePosBranch();
})();
</script>
@endif
