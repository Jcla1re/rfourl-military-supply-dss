<?php

namespace Tests\Support;

use CodeIgniter\Email\Email;

/**
 * A real CodeIgniter mailer whose delivery only records what would have gone out. Nothing is sent.
 * $fail = true makes every send fail.
 */
final class RecordingMailer extends Email
{
    public array $out = [];
    public bool $fail = false;

    protected function spoolEmail()
    {
        if ($this->fail) {
            return false;
        }
        $this->out[] = [
            'to'      => $this->recipients,
            'subject' => $this->headers['Subject'] ?? '',
            'body'    => $this->finalBody,
        ];

        return true;
    }
}
