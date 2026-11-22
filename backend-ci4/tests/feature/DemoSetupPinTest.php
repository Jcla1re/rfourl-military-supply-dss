<?php

use App\Libraries\DemoSetup;
use Config\Services;
use Tests\Support\DemoTestCase;
use Tests\Support\PosPinFixture;

/**
 * demo:setup on a database that has the checkout PIN columns (AddPosPinToUsers): the demo owner and
 * staff get the known checkout PIN DemoSetup::DEMO_PIN (shown with the demo logins), saved as a hash.
 *
 * @internal
 */
final class DemoSetupPinTest extends DemoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('email', $this->mailer);
        $this->demoOn();
        unset($_SERVER['DEMO_POS_PIN'], $_ENV['DEMO_POS_PIN']); // the default PIN, not an override
    }

    public function testTheDemoOwnerAndStaffGetTheKnownCheckoutPin(): void
    {
        // The test schema with the migration's PIN columns.
        \Config\Database::forge()->addColumn('users', PosPinFixture::forgeFields());

        (new DemoSetup())->run();

        foreach (['demo_owner', 'demo_staff'] as $username) {
            $u = $this->db->table('users')->where('username', $username)->get()->getRowArray();
            $this->assertNotEmpty($u['pos_pin_hash'], "{$username} has a checkout PIN");
            $this->assertNotSame(DemoSetup::DEMO_PIN, $u['pos_pin_hash'], "{$username}: only a hash is saved");
            $this->assertTrue(password_verify(DemoSetup::DEMO_PIN, $u['pos_pin_hash']), "{$username}: the PIN is " . DemoSetup::DEMO_PIN);
            $this->assertNotEmpty($u['pos_pin_set_at'], "{$username}: pos_pin_set_at is filled");
            $this->assertNull($u['pos_pin_reset_at'], "{$username}: not marked as reset by the owner");
        }
        $this->assertSame('4826', DemoSetup::DEMO_PIN);
        $this->assertNull($this->db->table('users')->where('username', 'demo_supplier')->get()->getRow()->pos_pin_hash, 'The supplier has no POS, so no PIN.');
    }
}
