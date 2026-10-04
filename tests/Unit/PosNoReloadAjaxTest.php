<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Restaurant POS page-reload rules:
 * - Send to Production refreshes cart + table grid via AJAX
 * - Reset clears the cart/screen via empty-cart AJAX
 * - Clear cart (restaurant + retail) clears the DOM in place
 * - Opening a shift from the POS RELOADS the restaurant page, so the
 *   server-rendered top bar (opening balance, closing balance button)
 *   and no-shift overlays reflect the new shift; that is the only reload
 *   left in the restaurant view (retail POS still refreshes via event)
 * Line money prefers the server subtotal so screen totals stay aligned.
 */
class PosNoReloadAjaxTest extends TestCase
{
    public function test_restaurant_pos_reloads_only_for_open_shift(): void
    {
        $view = file_get_contents(resource_path('views/pos/index-restaurant.blade.php'));
        $this->assertSame(1, substr_count($view, 'location.reload'));
        $this->assertStringContainsString('window.location.reload();', $view);
        $this->assertStringNotContainsString('pos-shift-opened', $view);
    }

    public function test_retail_pos_has_no_page_reloads(): void
    {
        $view = file_get_contents(resource_path('views/pos/index.blade.php'));
        $this->assertStringNotContainsString('window.location.reload', $view);
        $this->assertStringNotContainsString('location.reload()', $view);
    }

    public function test_send_to_production_refreshes_without_reload(): void
    {
        $view = file_get_contents(resource_path('views/pos/index-restaurant.blade.php'));
        $this->assertStringContainsString('function posRestaurantRefreshAfterSend', $view);
        $this->assertStringContainsString('posRestaurantRefreshAfterSend(payload, res)', $view);
        $this->assertStringContainsString('loadRestaurantTables(branchId)', $view);
    }

    public function test_reset_and_clear_cart_use_ajax(): void
    {
        $view = file_get_contents(resource_path('views/pos/index-restaurant.blade.php'));
        $this->assertStringContainsString('function posRestaurantResetScreen', $view);
        $this->assertStringContainsString("posRestaurantResetScreen($(this))", $view);
        $this->assertStringContainsString('function posRestaurantClearCartDom', $view);
        $this->assertStringContainsString('posClearCartDom()', $view);
    }

    public function test_open_shift_reloads_restaurant_but_retail_uses_event(): void
    {
        $restaurant = file_get_contents(resource_path('views/pos/index-restaurant.blade.php'));
        $this->assertStringContainsString('window.location.reload();', $restaurant);
        $this->assertStringNotContainsString("$(document).trigger('pos-shift-opened'", $restaurant);

        $retail = file_get_contents(resource_path('views/pos/index.blade.php'));
        $this->assertStringContainsString("$(document).on('pos-shift-opened'", $retail);
        $this->assertStringContainsString("$(document).trigger('pos-shift-opened'", $retail);
    }

    public function test_line_money_trusts_server_subtotal(): void
    {
        $view = file_get_contents(resource_path('views/pos/index-restaurant.blade.php'));
        $this->assertStringContainsString('function posRestaurantServerSubtotal', $view);

        $ctl = file_get_contents(app_path('Http/Controllers/PosController.php'));
        $this->assertStringContainsString("'subtotal' => \$money['total']", $ctl);
        $this->assertStringContainsString("'unit_subtotal' => \$money['unit']", $ctl);
    }
}
