@php
    use App\Services\HotelPermissionCatalog;

    $hotelGroups = HotelPermissionCatalog::groups();
    $roleModel = $role ?? null;
@endphp
<div class="tab-pane fade" id="hotel" role="tabpanel" aria-labelledby="pills-hotel-tab">
    <div class="col-md-12">
        <div class="form-group">
            @if (!empty($permissions) && HotelPermissionCatalog::hasAnyInPermissionMap($permissions))
                <h6 class="my-3">{{ __('Assign Hotel Management permissions to roles') }}</h6>
                <table class="table table-striped mb-0">
                    <thead>
                        <tr>
                            <th style="width: 40px;">
                                <input type="checkbox" class="form-check-input" name="hotel_checkall" id="hotel_checkall">
                            </th>
                            <th>{{ __('Area') }}</th>
                            <th>{{ __('Permissions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($hotelGroups as $group)
                            @php
                                $groupSlug = preg_replace('/[^a-z0-9]/i', '', $group['label']);
                            @endphp
                            <tr>
                                <td>
                                    <input type="checkbox" class="form-check-input ischeck hotel_checkall" data-id="hotel{{ $groupSlug }}">
                                </td>
                                <td>
                                    <label class="ischeck fw-semibold mb-0" data-id="hotel{{ $groupSlug }}">{{ __($group['label']) }}</label>
                                </td>
                                <td>
                                    <div class="row">
                                        @foreach ($group['permissions'] as $permission)
                                            @if (in_array($permission['name'], (array) $permissions, true))
                                                @php
                                                    $permId = array_search($permission['name'], $permissions, true);
                                                    $checked = $roleModel
                                                        ? $roleModel->hasPermissionTo($permission['name'])
                                                        : false;
                                                @endphp
                                                @if ($permId !== false)
                                                    <div class="col-md-6 col-lg-4 mb-2">
                                                        <div class="form-check">
                                                            {{ Form::checkbox(
                                                                'permissions[]',
                                                                $permId,
                                                                $checked,
                                                                [
                                                                    'class' => 'form-check-input isscheck hotel_checkall isscheck_hotel' . $groupSlug,
                                                                    'id' => 'permission' . $permId,
                                                                ]
                                                            ) }}
                                                            {{ Form::label('permission' . $permId, __($permission['label']), ['class' => 'form-check-label']) }}
                                                            <small class="text-muted d-block">{{ $permission['name'] }}</small>
                                                        </div>
                                                    </div>
                                                @endif
                                            @endif
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="text-muted mb-0">{{ __('Hotel permissions are not available. Run application migrations or contact your administrator.') }}</p>
            @endif
        </div>
    </div>
</div>
