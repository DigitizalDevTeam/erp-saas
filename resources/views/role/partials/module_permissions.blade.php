@php
    use App\Services\RolePermissionUiCatalog;

    $moduleList = $modules ?? [];
    $permissionMap = $permissions ?? [];
    $roleModel = $role ?? null;
    $checkall = $checkallClass ?? 'staff_checkall';
    $title = $sectionTitle ?? __('Assign Permission to Roles');
    $specialsForTab = $specialsTab ?? null;
    $prefixes = RolePermissionUiCatalog::prefixes();
    $specialPermissions = RolePermissionUiCatalog::specialPermissions();
    $specialTabs = RolePermissionUiCatalog::specialPermissionTabs();
    $assignedPermissionIds = $roleModel
        ? $roleModel->permissions->pluck('id')->map(fn ($id) => (int) $id)->all()
        : [];
@endphp

@if (!empty($permissionMap))
    <div class="col-md-12">
        <div class="form-group">
            <h6 class="my-3">{{ $title }}</h6>
            <table class="table table-striped mb-0">
                <thead>
                    <tr>
                        <th>
                            <input type="checkbox" class="form-check-input align-middle custom_align_middle" name="{{ $checkall }}" id="{{ $checkall }}">
                        </th>
                        <th>{{ __('Module') }}</th>
                        <th>{{ __('Permissions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($moduleList as $module)
                        @php
                            $slug = str_replace([' ', '&'], '', $module);
                        @endphp
                        <tr>
                            <td>
                                <input type="checkbox" class="form-check-input align-middle ischeck {{ $checkall }}" data-id="{{ $slug }}">
                            </td>
                            <td>
                                <label class="ischeck {{ $checkall }}" data-id="{{ $slug }}">{{ ucfirst($module) }}</label>
                            </td>
                            <td>
                                <div class="row">
                                    @foreach ($prefixes as [$prefix, $label])
                                        @php
                                            $permName = $prefix . $module;
                                        @endphp
                                        @if (in_array($permName, (array) $permissionMap, true))
                                            @php
                                                $key = array_search($permName, $permissionMap, true);
                                                $checked = $key !== false && in_array((int) $key, $assignedPermissionIds, true);
                                            @endphp
                                            @if ($key !== false)
                                                <div class="col-md-3 custom-control custom-checkbox">
                                                    {{ Form::checkbox(
                                                        'permissions[]',
                                                        $key,
                                                        $checked,
                                                        [
                                                            'class' => 'form-check-input ' . $checkall . ' isscheck_' . $slug,
                                                            'id' => 'permission' . $key,
                                                        ]
                                                    ) }}
                                                    {{ Form::label('permission' . $key, $label, ['class' => 'custom-control-label']) }}
                                                    <br>
                                                </div>
                                            @endif
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                        </tr>
                    @endforeach

                    @if ($specialsForTab)
                        @foreach ($specialPermissions as $permName => $permLabel)
                            @if (($specialTabs[$permName] ?? null) === $specialsForTab && in_array($permName, (array) $permissionMap, true))
                                @php
                                    $specialKey = array_search($permName, $permissionMap, true);
                                    $specialSlug = preg_replace('/[^a-z0-9]/i', '', $permName);
                                    $specialChecked = $specialKey !== false && in_array((int) $specialKey, $assignedPermissionIds, true);
                                @endphp
                                @if ($specialKey !== false)
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="form-check-input align-middle ischeck {{ $checkall }}" data-id="{{ $specialSlug }}">
                                        </td>
                                        <td>
                                            <label class="ischeck {{ $checkall }}" data-id="{{ $specialSlug }}">{{ __($permLabel) }}</label>
                                        </td>
                                        <td>
                                            <div class="row">
                                                <div class="col-md-6 custom-control custom-checkbox">
                                                    {{ Form::checkbox(
                                                        'permissions[]',
                                                        $specialKey,
                                                        $specialChecked,
                                                        [
                                                            'class' => 'form-check-input isscheck ' . $checkall . ' isscheck_' . $specialSlug,
                                                            'id' => 'permission' . $specialKey,
                                                        ]
                                                    ) }}
                                                    {{ Form::label('permission' . $specialKey, __($permLabel), ['class' => 'custom-control-label']) }}
                                                    <br>
                                                    <small class="text-muted">{{ $permName }}</small>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endif
                        @endforeach
                    @endif
                </tbody>
            </table>
        </div>
    </div>
@endif
