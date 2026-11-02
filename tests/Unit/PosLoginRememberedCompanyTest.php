<?php

namespace Tests\Unit;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Requests\Auth\PosLoginRequest;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * POS login with a remembered company.
 *
 * First login (or after Change company): email + PIN, no user list.
 * Next visit on the same browser: localStorage holds company id + name;
 * the login screen shows the company name in a read-only text input and
 * keeps the company id in a hidden input (no company dropdown), then
 * loads only that company's users for picking + PIN. The server always
 * re-validates user + PIN + company, so a tampered hidden company_id
 * cannot log anyone into another company.
 */
class PosLoginRememberedCompanyTest extends TestCase
{
    public function test_email_mode_rules_require_email_and_pin(): void
    {
        $request = new PosLoginRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('email', $rules);
        $this->assertArrayHasKey('pos_pin', $rules);
        $this->assertArrayNotHasKey('user_id', $rules);
        $this->assertArrayNotHasKey('company_id', $rules);
        $this->assertFalse($request->isRememberedCompanyMode());
        $this->assertFalse(Validator::make(
            ['email' => 'waiter@example.com', 'pos_pin' => '1234'], $rules
        )->fails());
        $this->assertTrue(Validator::make(
            ['email' => 'waiter@example.com', 'pos_pin' => '12'], $rules
        )->fails());
    }

    public function test_remembered_mode_rules_require_company_user_and_pin(): void
    {
        $request = new PosLoginRequest;
        $request->merge(['company_id' => 60, 'user_id' => 72, 'pos_pin' => '1234']);

        $this->assertTrue($request->isRememberedCompanyMode());

        $rules = $request->rules();
        $this->assertArrayHasKey('company_id', $rules);
        $this->assertArrayHasKey('user_id', $rules);
        $this->assertArrayHasKey('pos_pin', $rules);
        $this->assertArrayNotHasKey('email', $rules);
        // Note: the user_id exists:users rule needs a users table, which
        // this unit test does not create, so only the required-rule
        // failures are asserted here; the full pass is covered by the
        // rule structure above plus manual route verification.
        $this->assertTrue(Validator::make(
            ['company_id' => 60, 'pos_pin' => '1234'], $rules
        )->fails());
    }

    public function test_throttle_keys_differ_between_modes(): void
    {
        $email = new PosLoginRequest;
        $email->merge(['email' => 'waiter@example.com', 'pos_pin' => '1234']);
        $email->server->set('REMOTE_ADDR', '203.0.113.9');

        $remembered = new PosLoginRequest;
        $remembered->merge(['company_id' => 60, 'user_id' => 72, 'pos_pin' => '1234']);
        $remembered->server->set('REMOTE_ADDR', '203.0.113.9');

        $this->assertStringContainsString('email', $email->throttleKey());
        $this->assertStringContainsString('remembered', $remembered->throttleKey());
        $this->assertNotSame($email->throttleKey(), $remembered->throttleKey());
        $this->assertSame('email', $email->throttleErrorKey());
        $this->assertSame('user_id', $remembered->throttleErrorKey());
    }

    public function test_user_belongs_to_company_union(): void
    {
        $controller = new AuthenticatedSessionController;
        $method = new \ReflectionMethod($controller, 'userBelongsToCompany');
        $method->setAccessible(true);

        // id is not fillable on User, so set attributes directly.
        $owner = new User;
        $owner->id = 60;
        $this->assertTrue($method->invoke($controller, $owner, 60));

        $createdBy = new User(['created_by' => 60]);
        $createdBy->id = 72;
        $this->assertTrue($method->invoke($controller, $createdBy, 60));

        $byDefault = new User(['created_by' => 77, 'default_company_id' => 60]);
        $byDefault->id = 73;
        $this->assertTrue($method->invoke($controller, $byDefault, 60));

        $other = new User(['created_by' => 77]);
        $other->id = 74;
        $this->assertFalse($method->invoke($controller, $other, 60));
        $this->assertFalse($method->invoke($controller, $other, 0));
    }

    public function test_login_blade_has_remembered_company_inputs_not_dropdown_or_global_list(): void
    {
        $blade = file_get_contents(base_path('resources/views/auth/login.blade.php'));

        // Remembered company: read-only text input for the name, hidden
        // input for the id, change-company button, localStorage key.
        $this->assertStringContainsString('pos-remembered-company-name', $blade);
        $this->assertStringContainsString('readonly', $blade);
        $this->assertStringContainsString('name="company_id"', $blade);
        $this->assertStringContainsString('pos-change-company', $blade);
        $this->assertStringContainsString('pos_remembered_company', $blade);
        $this->assertStringContainsString("route('login.pos-users')", $blade);
        $this->assertStringContainsString('pos-login-form-email', $blade);
        $this->assertStringContainsString('pos-login-form-remembered', $blade);

        // No global user list, no old global user select.
        $this->assertStringNotContainsString('posLoginUsers', $blade);
        $this->assertStringNotContainsString('pos-login-user', $blade);
    }

    public function test_pos_page_stores_only_company_id_and_name(): void
    {
        $blade = file_get_contents(base_path('resources/views/pos/index-restaurant.blade.php'));

        $this->assertStringContainsString("localStorage.setItem('pos_remembered_company'", $blade);
        $this->assertStringContainsString('pos_remember_company', $blade);
    }
}
