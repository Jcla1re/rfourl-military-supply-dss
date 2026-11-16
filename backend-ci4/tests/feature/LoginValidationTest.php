<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * UT-03: empty login fields are refused by the server with a clear message
 * (the browser's "required" attribute is only a convenience). No database is
 * touched because the check runs before any lookup. One sign-in page for every
 * role: username and password, no role to choose.
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

    public static function blanks(): array
    {
        return [
            'both blank'        => [['username' => '', 'password' => '']],
            'no username'       => [['username' => '', 'password' => 'Something1']],
            'only spaces'       => [['username' => '   ', 'password' => 'Something1']],
            'no password'       => [['username' => 'owner1', 'password' => '']],
            'nothing posted'    => [[]],
        ];
    }

    /**
     * @dataProvider blanks
     */
    public function testBlankFieldsAreRefused(array $post): void
    {
        $result = $this->post('login', $post);

        $result->assertRedirectTo(site_url('login'));
        $result->assertSessionHas('error', 'Please fill in all fields');
    }

    public static function signInPages(): array
    {
        return [['/'], ['login']];
    }

    /**
     * @dataProvider signInPages
     */
    public function testTheSignInPageAsksForAUsernameAndAPasswordAndNoRole(string $path): void
    {
        $result = $this->get($path);

        $result->assertOK();
        $result->assertSeeElement('input[name=username]');
        $result->assertSeeElement('input[name=password]');
        $result->assertSee('Welcome');
        $result->assertSee('Sign in with your RfourL account.');
        $result->assertSee('Forgot password?');

        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('action="' . site_url('login') . '"', $body);
        $this->assertStringContainsString('href="' . site_url('login/forgot') . '"', $body);
        foreach (['role-card', 'Change role', 'portal-badge', 'SIGNING IN AS', 'Choose your role', 'OWNER PORTAL', 'STAFF PORTAL'] as $gone) {
            $this->assertStringNotContainsString($gone, $body, "No role choosing: {$gone}");
        }
        $this->assertStringContainsString('Inventory, sales, and reorder decisions in one place.', $body);
    }
}
