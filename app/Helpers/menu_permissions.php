<?php

use App\Services\SidebarMenuPermissionCatalog;

if (! function_exists('menu_can')) {
    /**
     * Check a single sidebar menu.* permission (OR legacy for BC).
     */
    function menu_can(string $permission): bool
    {
        return SidebarMenuPermissionCatalog::userCanPermission($permission);
    }
}

if (! function_exists('menu_can_node')) {
    /**
     * Check a sidebar folder/leaf including descendant visibility.
     */
    function menu_can_node(string $permission): bool
    {
        $node = SidebarMenuPermissionCatalog::findNode($permission);
        if ($node === null) {
            return SidebarMenuPermissionCatalog::userCanPermission($permission);
        }

        return SidebarMenuPermissionCatalog::userCanSeeNode($node);
    }
}
