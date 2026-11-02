<?php

namespace Tests\Unit;

use App\Http\Controllers\PosController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * POS Switch User must fully log out the current user.
 *
 * Old behaviour: Switch User only cleared the PIN user + cart and
 * sent the next waiter to PIN entry, while the first user's Laravel
 * login stayed alive underneath. New behaviour: the web guard is
 * logged out, the session is invalidated, and the terminal lands on
 * the POS login screen — where a remembered company shows read-only
 * and the next waiter picks their own name + PIN, with no email
 * typing. Browser localStorage (the remembered company) is NOT
 * cleared by this server-side logout; it belongs to the terminal.
 */
class PosSwitchUserFullLogoutTest extends TestCase
{
    public function test_switch_user_logs_out_auth_clears_pin_session_and_redirects_to_pos_login(): void
    {
        $user = new User;
        $user->id = 72;
        $user->type = 'company';
        $this->actingAs($user);

        app('session.store')->start();
        session([
            'pos_pin_user_id' => 72,
            'pos_login_via_tab' => true,
            'active_company_id' => 60,
            'pos_restaurant_cart' => ['lines' => []],
        ]);
        $this->assertTrue(Auth::check());
        $this->assertSame(72, session('pos_pin_user_id'));

        $request = Request::create('/pos-restaurant/switch-user', 'GET');
        $request->setLaravelSession(app('session.store'));

        $response = (new PosController)->logoutRestaurantPin($request);

        // Full logout, not a PIN swap.
        $this->assertFalse(Auth::check());
        $this->assertNull(session('pos_pin_user_id'));
        $this->assertNull(session('pos_login_via_tab'));
        $this->assertNull(session('active_company_id'));
        $this->assertNull(session('pos_restaurant_cart'));

        // Next user starts at the POS login screen, not PIN entry
        // and not back inside POS.
        $this->assertSame(route('login', ['tab' => 'pos']), $response->getTargetUrl());
        $this->assertSame('Signed out. Log in as the next user.', $response->getSession()->get('status'));
    }

    public function test_switch_user_controller_does_not_use_old_pin_only_behaviour(): void
    {
        $source = file_get_contents(base_path('app/Http/Controllers/PosController.php'));
        $start = strpos($source, 'public function logoutRestaurantPin');
        $method = substr($source, $start, 2600);

        $this->assertStringContainsString("Auth::guard('web')->logout()", $method);
        $this->assertStringContainsString('->invalidate()', $method);
        $this->assertStringContainsString('regenerateToken()', $method);
        $this->assertStringContainsString("route('login', ['tab' => 'pos'])", $method);
        $this->assertStringNotContainsString('requireRestaurantPinEntry', $method);
        $this->assertStringNotContainsString('pos.restaurant.index', $method);
    }

    public function test_login_blade_honours_tab_pos_query(): void
    {
        $blade = file_get_contents(base_path('resources/views/auth/login.blade.php'));

        $this->assertStringContainsString("request()->query('tab')", $blade);
        $this->assertStringContainsString("['erp', 'pos']", $blade);
    }

    public function test_switch_user_routes_point_to_full_logout_method(): void
    {
        $this->assertTrue(Route::has('pos.restaurant.switch-user'));
        $this->assertTrue(Route::has('pos.restaurant.logout-pin'));
        $this->assertSame(
            url('pos-restaurant/switch-user'),
            route('pos.restaurant.switch-user')
        );
        $this->assertStringContainsString('tab=pos', route('login', ['tab' => 'pos']));
    }
}
