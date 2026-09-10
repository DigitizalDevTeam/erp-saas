@foreach ($nodes as $node)
    @php
        $permName = $node['permission'];
        $permId = array_search($permName, (array) $permissions, true);
        $slug = preg_replace('/[^a-z0-9_.]/i', '_', $permName);
        $path = ($parentPath ?? '') !== '' ? ($parentPath . '.' . $slug) : $slug;
        $checked = $permId !== false && in_array((int) $permId, $assignedPermissionIds ?? [], true);
        $hasChildren = ! empty($node['children']);
        $pad = (int) ($depth ?? 0) * 18;
    @endphp
    @if ($permId !== false)
        <li class="mb-1" style="padding-left: {{ $pad }}px;">
            <div class="form-check">
                <input
                    type="checkbox"
                    class="form-check-input sidebar-menu-perm {{ $hasChildren ? 'sidebar-menu-perm-parent' : '' }}"
                    name="permissions[]"
                    value="{{ $permId }}"
                    id="sidebar_perm_{{ $permId }}"
                    data-tree-id="{{ $path }}"
                    data-parent-path="{{ $parentPath ?? '' }}"
                    @checked($checked)
                >
                <label class="form-check-label" for="sidebar_perm_{{ $permId }}">
                    {{ __($node['label']) }}
                    <small class="text-muted d-block">{{ $permName }}</small>
                </label>
            </div>
            @if ($hasChildren)
                <ul class="list-unstyled mb-0">
                    @include('role.partials.sidebar_menu_permissions_nodes', [
                        'nodes' => $node['children'],
                        'permissions' => $permissions,
                        'assignedPermissionIds' => $assignedPermissionIds,
                        'depth' => ($depth ?? 0) + 1,
                        'parentPath' => $path,
                    ])
                </ul>
            @endif
        </li>
    @elseif ($hasChildren)
        {{-- Permission not in map yet; still render children if any exist in map --}}
        <li class="mb-1" style="padding-left: {{ $pad }}px;">
            <div class="text-muted small fw-semibold">{{ __($node['label']) }}</div>
            <ul class="list-unstyled mb-0">
                @include('role.partials.sidebar_menu_permissions_nodes', [
                    'nodes' => $node['children'],
                    'permissions' => $permissions,
                    'assignedPermissionIds' => $assignedPermissionIds,
                    'depth' => ($depth ?? 0) + 1,
                    'parentPath' => $path,
                ])
            </ul>
        </li>
    @endif
@endforeach
