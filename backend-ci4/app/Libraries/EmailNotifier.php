<?php

namespace App\Libraries;

use App\Models\UserModel;

/**
 * Best-effort email side-channel for notifications that matter enough to
 * reach someone off-platform (order status changes, staff access requests)
 * — on top of, not instead of, the in-app notification bell. Reuses the
 * same SMTP config as OTP delivery and fails silently (logged, not thrown)
 * since a missed email must never block the action that triggered it.
 */
class EmailNotifier
{
    public function toUser(?array $user, string $subject, string $message): void
    {
        if (empty($user['email'])) {
            return;
        }

        try {
            $email = service('email');
            $email->setTo($user['email']);
            $email->setSubject($subject);
            $email->setMessage($message);
            $email->send();
        } catch (\Throwable $e) {
            log_message('error', 'Email notification failed: ' . $e->getMessage());
        }
    }

    /**
     * Emails every active user in the given role (in practice, the single
     * Owner/Admin account) — mirrors how in-app notifications already
     * broadcast to a role rather than one specific recipient_id.
     */
    public function toRole(string $role, string $subject, string $message): void
    {
        $users = (new UserModel())->where('role', $role)->where('is_active', 1)->findAll();

        foreach ($users as $user) {
            $this->toUser($user, $subject, $message);
        }
    }

    /**
     * HTML variant for emails that need real styled buttons (e.g. an
     * Approve/Decline link), with a plain-text fallback for clients that
     * don't render HTML mail.
     */
    public function toUserHtml(?array $user, string $subject, string $htmlMessage, string $plainTextFallback): void
    {
        if (empty($user['email'])) {
            return;
        }

        try {
            $email = service('email');
            $email->setTo($user['email']);
            $email->setSubject($subject);
            $email->setMailType('html');
            $email->setMessage($htmlMessage);
            $email->setAltMessage($plainTextFallback);
            $email->send();
        } catch (\Throwable $e) {
            log_message('error', 'Email notification failed: ' . $e->getMessage());
        }
    }

    public function toRoleHtml(string $role, string $subject, string $htmlMessage, string $plainTextFallback): void
    {
        $users = (new UserModel())->where('role', $role)->where('is_active', 1)->findAll();

        foreach ($users as $user) {
            $this->toUserHtml($user, $subject, $htmlMessage, $plainTextFallback);
        }
    }
}
