<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regression: GET /login/pos-users must reach posUsersForCompany,
 * not showLoginForm. /login/{lang?} is a two-segment wildcard, so
 * when it was registered first it swallowed /login/pos-users
 * (lang = "pos-users"), the JS fetch got login HTML instead of
 * JSON, and the remembered-company user dropdown stayed empty.
 */
class PosUsersRouteTest extends TestCase
{
    public function test_pos_users_route_is_not_shadowed_by_login_lang_route(): void
    {
        $matched = Route::getRoutes()->match(
            Request::create('/login/pos-users?company_id=79', 'GET')
        );

        $this->assertSame('login.pos-users', $matched->getName());
        $this->assertSame(
            'App\Http\Controllers\Auth\AuthenticatedSessionController@posUsersForCompany',
            $matched->getActionName()
        );
    }

    public function test_login_lang_route_still_works(): void
    {
        $this->assertSame(
            'login',
            Route::getRoutes()->match(Request::create('/login/en', 'GET'))->getName()
        );
        $this->assertSame(
            'login',
            Route::getRoutes()->match(Request::create('/login', 'GET'))->getName()
        );
    }
}
