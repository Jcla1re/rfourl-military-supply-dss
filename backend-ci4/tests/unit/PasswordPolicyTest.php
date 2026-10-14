<?php

use App\Libraries\PasswordPolicy;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * UT-06 (new-password rules incl. the 8-character boundary) and UT-05 (hashing).
 *
 * @internal
 */
final class PasswordPolicyTest extends CIUnitTestCase
{
    public function testSevenCharactersAreRejected(): void
    {
        $this->assertNotNull(PasswordPolicy::check('Abcde12'));
    }

    public function testEightCharactersWithLetterAndNumberAreAccepted(): void
    {
        $this->assertNull(PasswordPolicy::check('Abcdef12'));
    }

    public function testLettersOnlyOrNumbersOnlyAreRejected(): void
    {
        $this->assertNotNull(PasswordPolicy::check('abcdefgh'));
        $this->assertNotNull(PasswordPolicy::check('12345678'));
    }

    public function testEmptyAndNullAreRejected(): void
    {
        $this->assertNotNull(PasswordPolicy::check(''));
        $this->assertNotNull(PasswordPolicy::check(null));
    }

    /** UT-05: the app stores password_hash() output and checks it with password_verify(). */
    public function testPasswordIsHashedAndVerifiable(): void
    {
        $hash = password_hash('Rfourl2026!', PASSWORD_DEFAULT);

        $this->assertStringStartsWith('$2y$', $hash);
        $this->assertTrue(password_verify('Rfourl2026!', $hash));
        $this->assertFalse(password_verify('wrong-password', $hash));
    }
}
