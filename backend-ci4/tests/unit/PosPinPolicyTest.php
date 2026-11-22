<?php

use App\Libraries\DemoSetup;
use App\Libraries\PosPin;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\PosPinFixture;

/**
 * B1: which checkout PINs are refused (App\Libraries\PosPin::weakReason()), and that the PIN fields
 * are never kept as old input.
 *
 * @internal
 */
final class PosPinPolicyTest extends CIUnitTestCase
{
    public static function weakPins(): array
    {
        $cases = [];
        foreach ([
            // not exactly 4 digits
            '', '123', '12345', 'abcd', '12a4', ' 482', '48 6',
            // one digit four times
            '0000', '1111', '7777', '9999',
            // straight runs up or down, also over 9-0
            '0123', '1234', '3456', '6789', '7890', '8901', '9012', '4321', '9876', '3210', '0987', '1098',
            // repeated pairs
            '1212', '4545', '0909', '1122', '7788', '0011',
            // years
            '1900', '1985', '1999', '2000', '2010', '2026', '2099',
            // common PINs
            '1004', '2580', '0852', '6969', '5683', '2468', '1357', '0007', '1110',
        ] as $pin) {
            $cases["'{$pin}'"] = [$pin];
        }

        return $cases;
    }

    /** @dataProvider weakPins */
    public function testWeakPinsAreRefused(string $pin): void
    {
        $this->assertNotNull(PosPin::weakReason($pin));
    }

    public function testOrdinaryPinsAreAccepted(): void
    {
        foreach (['4826', '5739', '3091', '8153', '6402', '1795', '2817', '7361'] as $pin) {
            $this->assertNull(PosPin::weakReason($pin), $pin);
        }
    }

    public function testTheMessages(): void
    {
        $this->assertSame('The PIN must be exactly 4 digits.', PosPin::weakReason('123'));
        $this->assertSame('That PIN is too easy to guess. Avoid repeated or consecutive digits, years (19xx, 20xx) and common PINs.', PosPin::weakReason('1234'));
    }

    public function testTheLastFourDigitsOfTheOwnContactNumberAreRefused(): void
    {
        $this->assertSame("Don't use the last 4 digits of your contact number.", PosPin::weakReason('5866', '0985 214 5866'));
        $this->assertNull(PosPin::weakReason('5866', '0917 123 4567'));
        $this->assertNull(PosPin::weakReason('5866', null));
    }

    public function testTheDemoAndTestPinsPassTheRules(): void
    {
        $this->assertNull(PosPin::weakReason(DemoSetup::DEMO_PIN), 'The demo accounts\' known PIN.');
        $this->assertNull(PosPin::weakReason(PosPinFixture::PIN));
    }

    public function testANewPinAndItsRepeat(): void
    {
        $this->assertSame([], PosPin::newPinErrors('5739', '5739'));
        $this->assertSame([PosPin::REPEAT_FIELD => "The two PINs don't match."], PosPin::newPinErrors('5739', '5793'));
        $this->assertArrayHasKey(PosPin::NEW_FIELD, PosPin::newPinErrors('1111', '1111'));
    }

    public function testOnlyAHashIsSaved(): void
    {
        $columns = PosPin::columnsFor('5739');
        $this->assertNotSame('5739', $columns['pos_pin_hash']);
        $this->assertTrue(password_verify('5739', $columns['pos_pin_hash']));
        $this->assertNull($columns['pos_pin_reset_at']);
        $this->assertSame(['pos_pin_hash' => null, 'pos_pin_set_at' => null], array_slice(PosPin::resetColumns(), 0, 2, true));
    }

    public function testThePinFieldsAreNeverKeptAsOldInput(): void
    {
        $post = ['item_id' => ['P-1'], 'pos_pin' => '5739', 'new_pin' => '5739', 'new_pin_confirm' => '5739', 'or_number' => 'A-1'];
        $this->assertSame(['item_id' => ['P-1'], 'or_number' => 'A-1'], PosPin::strip($post));
        $this->assertSame(['pos_pin', 'new_pin', 'new_pin_confirm'], PosPin::FIELDS);
    }

    public function testThePinIsNeverInALogMessageOrView(): void
    {
        // No PIN value is ever passed to log_message(); every log call in PosPin names only the event.
        $source = file_get_contents(APPPATH . 'Libraries/PosPin.php');
        $this->assertDoesNotMatchRegularExpression('/log_message\([^)]*\$(typed|pin)/', $source);
        $this->assertDoesNotMatchRegularExpression('/SecurityLogModel::log\([^;]*\$(typed|pin)\b/', $source);
    }
}
