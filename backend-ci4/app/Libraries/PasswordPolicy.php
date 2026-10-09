<?php
// app/Libraries/PasswordPolicy.php

namespace App\Libraries;

/**
 * The one password rule used by every create/change/reset form:
 * at least 8 characters, containing at least one letter and one number.
 */
class PasswordPolicy
{
    public const MIN_LENGTH = 8;
    public const HINT       = 'At least 8 characters, with a letter and a number';

    /** Returns an error message, or null when the password is acceptable. */
    public static function check(?string $password): ?string
    {
        $password = (string) $password;

        if (strlen($password) < self::MIN_LENGTH
            || ! preg_match('/[A-Za-z]/', $password)
            || ! preg_match('/\d/', $password)) {
            return 'Password must be at least 8 characters and include a letter and a number.';
        }

        return null;
    }
}
