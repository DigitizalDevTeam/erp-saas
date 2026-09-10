@php
    use App\Services\SidebarMenuPermissionCatalog;

    $permissionMap = $permissions ?? [];
    $roleModel = $role ?? null;
    $assignedPermissionIds = $roleModel
        ? $roleModel->permissions->pluck('id')->map(fn ($id) => (int) $id)->all()
        : [];
    $tree = SidebarMenuPermissionCatalog::tree();
@endphp

<div class="col-md-12">
    <div class="form-group">
        <h6 class="my-3">{{ __('Sidebar menu access') }}</h6>
        <p class="text-muted small mb-2">{{ __('Grant visibility for each sidebar item. Action permissions (create/edit/delete) remain on the other tabs.') }}</p>
        <div class="mb-2">
            <input type="checkbox" class="form-check-input" id="sidebar_menu_checkall">
            <label for="sidebar_menu_checkall" class="form-check-label ms-1">{{ __('Check all sidebar items') }}</label>
        </div>
        <div class="border rounded p-3" style="max-height: 420px; overflow-y: auto;">
            <ul class="list-unstyled mb-0 sidebar-menu-perm-tree">
                @include('role.partials.sidebar_menu_permissions_nodes', [
                    'nodes' => $tree,
                    'permissions' => $permissionMap,
                    'assignedPermissionIds' => $assignedPermissionIds,
                    'depth' => 0,
                ])
            </ul>
        </div>
    </div>
</div>

<script>
    $(document).ready(function () {
        $('#sidebar_menu_checkall').on('click', function () {
            $('.sidebar-menu-perm').prop('checked', this.checked);
        });
        $(document).on('change', '.sidebar-menu-perm-parent', function () {
            var id = $(this).data('tree-id');
            var checked = this.checked;
            $('.sidebar-menu-perm[data-parent-path^="' + id + '."]').prop('checked', checked);
            $('.sidebar-menu-perm[data-tree-id="' + id + '"]').prop('checked', checked);
        });
    });
</script>
