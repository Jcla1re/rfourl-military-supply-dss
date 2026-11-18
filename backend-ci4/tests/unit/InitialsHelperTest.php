<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * initials(): the letters in the round avatars (My Profile, the Owner's sidebar, Manage Accounts).
 *
 * @internal
 */
final class InitialsHelperTest extends CIUnitTestCase
{
    public function testFirstAndLastWord(): void
    {
        $this->assertSame('RO', initials('Real Owner'));
        $this->assertSame('JD', initials('juan dela cruz Diaz'), 'first and last word only, upper case');
        $this->assertSame('RO', initials('  Real   Owner  '), 'extra spaces');
    }

    public function testSingleLetterMiddleWordsAreSkipped(): void
    {
        $this->assertSame('RP', initials('Richelle D. Paras'));
        $this->assertSame('AC', initials('Ana D Cruz'));
    }

    public function testOneWordGivesOneLetter(): void
    {
        $this->assertSame('M', initials('Maria'));
        $this->assertSame('J', initials('J.'));
    }

    public function testMultibyteLetters(): void
    {
        $this->assertSame('ÑR', initials('Ñoño Reyes'));
        $this->assertSame('ÉÁ', initials('élan ávila'));
    }

    public function testMarksAroundAWordAreIgnored(): void
    {
        $this->assertSame('JO', initials("Jean-Paul O'Brien"));
        $this->assertSame('AB', initials('(Ana) "Bea"'));
    }

    public function testEmptyNames(): void
    {
        $this->assertSame('', initials(''));
        $this->assertSame('', initials(null));
        $this->assertSame('', initials(' . - '));
    }
}
