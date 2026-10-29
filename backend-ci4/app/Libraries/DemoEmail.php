<?php

namespace App\Libraries;

use CodeIgniter\Email\Email;

/**
 * The one place demo mode redirects mail. Used instead of the stock mailer when
 * DEMO_MODE is true (see Config\Services::email()), so every email in the app,
 * whoever sends it, goes to DEMO_MAIL_TO and never to a real person. The
 * original recipient is added to the subject.
 */
class DemoEmail extends Email
{
    private string $originalTo = '';

    public function setTo($to)
    {
        $list = is_array($to) ? $to : array_map('trim', explode(',', (string) $to));
        $this->originalTo = implode(', ', array_filter($list));

        return parent::setTo($to);
    }

    public function setCC($cc)
    {
        return $this;
    }

    public function setBCC($bcc, $limit = '')
    {
        return $this;
    }

    public function send($autoClear = true)
    {
        $target = demo_setting('MAIL_TO');

        if ($target === null) {
            log_message('error', 'Demo mode: DEMO_MAIL_TO is not set, email not sent.');
            $this->clear();

            return false;
        }

        $subject = (string) ($this->tmpArchive['subject'] ?? '');
        $label   = $this->originalTo !== '' ? $this->originalTo : 'unknown';

        parent::setTo($target);
        $this->setSubject('[DEMO to ' . $label . '] ' . $subject);

        return parent::send($autoClear);
    }
}
