@php
    use App\Services\RolePermissionUiCatalog;
    use App\Services\HotelPermissionCatalog;

    $userPlan = \App\Models\Plan::getPlan(\Auth::user()->show_dashboard());
@endphp
{{ Form::model($role, ['route' => ['roles.update', $role->id], 'method' => 'PUT', 'class' => 'needs-validation', 'novalidate']) }}
<div class="modal-body">
    <div class="row">
        <div class="col-lg-12 col-md-12 col-sm-12">
            <div class="form-group">
                {{ Form::label('name', __('Name'), ['class' => 'form-label']) }}<x-required></x-required>
                @php
                    $nameAttrs = [
                        'class' => 'form-control',
                        'placeholder' => __('Enter Role Name'),
                        'required' => 'required',
                    ];
                    if (isset($role->name) && $role->name == 'Employee') {
                        $nameAttrs['readonly'] = 'readonly';
                    }
                @endphp
                {{ Form::text('name', null, $nameAttrs) }}
                @error('name')
                    <small class="invalid-name" role="alert">
                        <strong class="text-danger">{{ $message }}</strong>
                    </small>
                @enderror
            </div>
            <ul class="nav nav-pills mb-0" id="pills-tab" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="pills-sidebar-tab" data-bs-toggle="pill" href="#sidebar-menu" role="tab" aria-controls="sidebar-menu" aria-selected="true">{{ __('Sidebar') }}</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="pills-staff-tab" data-bs-toggle="pill" href="#staff" role="tab" aria-controls="staff" aria-selected="false">{{ __('Staff') }}</a>
                </li>
                @if (!empty($userPlan) && $userPlan->crm == 1)
                    <li class="nav-item">
                        <a class="nav-link" id="pills-crm-tab" data-bs-toggle="pill" href="#crm" role="tab" aria-controls="crm" aria-selected="false">{{ __('CRM') }}</a>
                    </li>
                @endif
                @if (!empty($userPlan) && $userPlan->project == 1)
                    <li class="nav-item">
                        <a class="nav-link" id="pills-project-tab" data-bs-toggle="pill" href="#project" role="tab" aria-controls="project" aria-selected="false">{{ __('Project') }}</a>
                    </li>
                @endif
                @if (!empty($userPlan) && $userPlan->hrm == 1)
                    <li class="nav-item">
                        <a class="nav-link" id="pills-hrmpermission-tab" data-bs-toggle="pill" href="#hrmpermission" role="tab" aria-controls="hrmpermission" aria-selected="false">{{ __('HRM') }}</a>
                    </li>
                @endif
                @if (!empty($userPlan) && $userPlan->account == 1)
                    <li class="nav-item">
                        <a class="nav-link" id="pills-account-tab" data-bs-toggle="pill" href="#account" role="tab" aria-controls="account" aria-selected="false">{{ __('Account') }}</a>
                    </li>
                @endif
                @if (!empty($userPlan) && ($userPlan->pos == 1 || ($userPlan->stock ?? 0) == 1))
                    <li class="nav-item">
                        <a class="nav-link" id="pills-pos-tab" data-bs-toggle="pill" href="#pos" role="tab" aria-controls="pos" aria-selected="false">{{ __('POS') }}</a>
                    </li>
                @endif
                @if (HotelPermissionCatalog::hasAnyInPermissionMap($permissions ?? []))
                    <li class="nav-item">
                        <a class="nav-link" id="pills-hotel-tab" data-bs-toggle="pill" href="#hotel" role="tab" aria-controls="hotel" aria-selected="false">{{ __('Hotel') }}</a>
                    </li>
                @endif
            </ul>
            <div class="tab-content" id="pills-tabContent">
                <div class="tab-pane fade show active" id="sidebar-menu" role="tabpanel" aria-labelledby="pills-sidebar-tab">
                    @include('role.partials.sidebar_menu_permissions', [
                        'permissions' => $permissions,
                        'role' => $role,
                    ])
                </div>
                <div class="tab-pane fade" id="staff" role="tabpanel" aria-labelledby="pills-staff-tab">
                    @include('role.partials.module_permissions', [
                        'modules' => RolePermissionUiCatalog::staffModules(\Auth::user()->type),
                        'permissions' => $permissions,
                        'role' => $role,
                        'checkallClass' => 'staff_checkall',
                        'sectionTitle' => __('Assign General Permission to Roles'),
                    ])
                </div>
                @if (!empty($userPlan) && $userPlan->crm == 1)
                    <div class="tab-pane fade" id="crm" role="tabpanel" aria-labelledby="pills-crm-tab">
                        @include('role.partials.module_permissions', [
                            'modules' => RolePermissionUiCatalog::crmModules(),
                            'permissions' => $permissions,
                            'role' => $role,
                            'checkallClass' => 'crm_checkall',
                            'sectionTitle' => __('Assign CRM related Permission to Roles'),
                        ])
                    </div>
                @endif
                @if (!empty($userPlan) && $userPlan->project == 1)
                    <div class="tab-pane fade" id="project" role="tabpanel" aria-labelledby="pills-project-tab">
                        @include('role.partials.module_permissions', [
                            'modules' => RolePermissionUiCatalog::projectModules(),
                            'permissions' => $permissions,
                            'role' => $role,
                            'checkallClass' => 'project_checkall',
                            'sectionTitle' => __('Assign Project related Permission to Roles'),
                        ])
                    </div>
                @endif
                @if (!empty($userPlan) && $userPlan->hrm == 1)
                    <div class="tab-pane fade" id="hrmpermission" role="tabpanel" aria-labelledby="pills-hrmpermission-tab">
                        @include('role.partials.module_permissions', [
                            'modules' => RolePermissionUiCatalog::hrmModules(),
                            'permissions' => $permissions,
                            'role' => $role,
                            'checkallClass' => 'hrm_checkall',
                            'sectionTitle' => __('Assign HRM related Permission to Roles'),
                        ])
                    </div>
                @endif
                @if (!empty($userPlan) && $userPlan->account == 1)
                    <div class="tab-pane fade" id="account" role="tabpanel" aria-labelledby="pills-account-tab">
                        @include('role.partials.module_permissions', [
                            'modules' => RolePermissionUiCatalog::accountModules(),
                            'permissions' => $permissions,
                            'role' => $role,
                            'checkallClass' => 'account_checkall',
                            'sectionTitle' => __('Assign Account related Permission to Roles'),
                            'specialsTab' => 'account',
                        ])
                    </div>
                @endif
                @if (!empty($userPlan) && ($userPlan->pos == 1 || ($userPlan->stock ?? 0) == 1))
                    <div class="tab-pane fade" id="pos" role="tabpanel" aria-labelledby="pills-pos-tab">
                        @include('role.partials.module_permissions', [
                            'modules' => ($userPlan->pos == 1)
                                ? RolePermissionUiCatalog::posModules()
                                : ['warehouse'],
                            'permissions' => $permissions,
                            'role' => $role,
                            'checkallClass' => 'pos_checkall',
                            'sectionTitle' => __('Assign POS related Permission to Roles'),
                            'specialsTab' => $userPlan->pos == 1 ? 'pos' : null,
                        ])
                    </div>
                @endif
                @include('role.partials.hotel_permissions', ['permissions' => $permissions, 'role' => $role])
            </div>
        </div>
    </div>
</div>
<div class="modal-footer">
    <input type="button" value="{{ __('Cancel') }}" class="btn btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{ __('Update') }}" class="btn btn-primary">
</div>
{{ Form::close() }}

<script>
    $(document).ready(function () {
        $("#staff_checkall").click(function () {
            $('.staff_checkall').not(this).prop('checked', this.checked);
        });
        $("#crm_checkall").click(function () {
            $('.crm_checkall').not(this).prop('checked', this.checked);
        });
        $("#project_checkall").click(function () {
            $('.project_checkall').not(this).prop('checked', this.checked);
        });
        $("#hrm_checkall").click(function () {
            $('.hrm_checkall').not(this).prop('checked', this.checked);
        });
        $("#account_checkall").click(function () {
            $('.account_checkall').not(this).prop('checked', this.checked);
        });
        $("#pos_checkall").click(function () {
            $('.pos_checkall').not(this).prop('checked', this.checked);
        });
        $("#hotel_checkall").click(function () {
            $('.hotel_checkall').not(this).prop('checked', this.checked);
        });
        $(".ischeck").click(function () {
            var ischeck = $(this).data('id');
            $('.isscheck_' + ischeck).prop('checked', this.checked);
        });
    });
</script>
