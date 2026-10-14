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
}
