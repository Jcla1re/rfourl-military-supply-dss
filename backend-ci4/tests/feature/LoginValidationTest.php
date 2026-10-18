<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * UT-03: empty login fields are refused by the server with a clear message
 * (the browser's "required" attribute is only a convenience). No database is
 * touched because the check runs before any lookup.
 *
 * @internal
 */
final class LoginValidationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        // CSRF is covered by the Newman run (ST-15); switch it off here so the
        // form-validation message can be asserted without a token round trip.
        $filters = config('Filters');
        $filters->globals['before'] = array_values(array_diff($filters->globals['before'], ['csrf']));
    }

    public static function loginRoles(): array
    {
        return [['admin'], ['staff'], ['supplier']];
    }

    /**
     * @dataProvider loginRoles
     */
    public function testBlankFieldsAreRefused(string $role): void
    {
        $result = $this->post("login/{$role}", ['username' => '', 'password' => '']);

        $result->assertRedirect();
        $result->assertSessionHas('error', 'Please fill in all fields');
    }

    /**
     * Staff now sign in with a username AND password, so either one missing
     * is refused (the staff form used to be password-only).
     */
    public static function staffHalfFilled(): array
    {
        return [
            'username only' => ['cashier1', ''],
            'password only' => ['', 'whatever1'],
        ];
    }

    /**
     * @dataProvider staffHalfFilled
     */
    public function testStaffNeedsBothUsernameAndPassword(string $username, string $password): void
    {
        $result = $this->post('login/staff', ['username' => $username, 'password' => $password]);

        $result->assertRedirect();
        $result->assertSessionHas('error', 'Please fill in all fields');
    }

    public function testStaffLoginPageAsksForUsername(): void
    {
        $result = $this->get('login/staff');

        $result->assertOK();
        $result->assertSeeElement('input[name=username]');
        $result->assertSeeElement('input[name=password]');
    }
}
