<?php

namespace App\Exceptions;

/** Thrown by the UserModel backstop when code tries to change a locked demo account. */
class DemoLockedException extends \RuntimeException
{
    public function __construct(string $message = 'Disabled in the demo version.')
    {
        parent::__construct($message);
    }
}
