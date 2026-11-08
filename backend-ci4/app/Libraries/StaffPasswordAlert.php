<?php

namespace App\Libraries;

use App\Models\NotificationModel;
use App\Models\SecurityLogModel;
use App\Models\UserModel;

/**
 * The always-on security alerts about a staff member's password or email. They are deliberately
 * not notification preferences. None of them ever receives a password, so no password can end up
 * in a message or log.
 *
 *   send()             a staff member changed their own password (My Account): in-app only, with Lock.
 *   afterOwnerReset()  they set a new password after the owner reset theirs: in-app only, with Lock.
 *   forgotReset()      they set a new password after Forgot password: in-app AND one email, with
 *                      Accept and Lock this account. Only one of the two can be used.
 *   emailChanged()     they changed their login email: in-app and email, with Lock.
 */
class StaffPasswordAlert
{
    public const CATEGORY         = 'security';
    public const TTL_SECONDS      = 86400;
    public const STATUS_LOCKED    = 'locked';
    public const STATUS_CONFIRMED = 'confirmed';

    /** The type of the Accept / Lock alert after Forgot password (shown as its label). */
    public const TYPE_FORGOT = 'Password Reset';

    /** My Account: the owner is told in the app only, with a Lock button. */
    public static function send(array $staff, string $device): void
    {
        try {
            self::pushLockable(
                $staff,
                "{$staff['full_name']} set a new password on " . self::now(),
                "Changed from {$device}. If this wasn't expected, lock the account.",
                'Security',
                false
            );
        } catch (\Throwable $e) {
            // The password change itself must never fail because an alert did.
            log_message('error', 'Staff password alert failed: ' . $e->getMessage());
        }
    }

    /** After the owner reset the password and the staff member chose their own: in-app only, with Lock. */
    public static function afterOwnerReset(array $staff): void
    {
        try {
            self::pushLockable(
                $staff,
                "{$staff['full_name']} set a new password on " . self::now(),
                "They chose it after you reset their password. If this wasn't expected, lock the account.",
                'Security',
                false
            );
        } catch (\Throwable $e) {
            log_message('error', 'Staff password alert failed: ' . $e->getMessage());
        }
    }

    /**
     * After Forgot password: the staff member used the emailed temporary password and set a new one.
     * One in-app alert and one email, both with Accept and Lock this account.
     */
    public static function forgotReset(array $staff): void
    {
        try {
            $name    = $staff['full_name'];
            $when    = self::now();
            $title   = "{$name} set a new password on {$when}";
            $message = "{$name} asked for a temporary password with Forgot password and then set a new one. Did you know about this? "
                . "If it was really {$name}, click Accept. If not, click Lock this account.";

            [$id, $token] = self::pushLockable($staff, $title, $message, self::TYPE_FORGOT, true);

            $acceptUrl = $token === null ? null : site_url("notification-action/{$id}/{$token}/accept");
            $lockUrl   = $token === null ? null : site_url("notification-action/{$id}/{$token}/lock");

            $subject = "{$name} reset their password using Forgot Password";
            $body    = "{$name} requested a temporary password and set a new password on {$when}. Did you know about this? "
                . "If it was really {$name}, click Accept. If not, click Lock this account.";

            $buttons = '';
            if ($acceptUrl !== null) {
                $buttons = '<p style="margin:24px 0;">'
                    . '<a href="' . htmlspecialchars($acceptUrl, ENT_QUOTES) . '" style="background:#2f6431;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;margin-right:12px;display:inline-block;">Accept</a>'
                    . '<a href="' . htmlspecialchars($lockUrl, ENT_QUOTES) . '" style="background:#7a2020;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block;">Lock this account</a>'
                    . '</p>';
            }
            $html = '<div style="font-family:\'Poppins\',Arial,sans-serif;"><p><strong>' . esc($subject, 'html') . '</strong></p>'
                . '<p>' . esc($body, 'html') . '</p>' . $buttons
                . '<p style="color:#888;font-size:12px;">' . ($acceptUrl === null
                    ? 'Log in to the owner portal and open Notifications or Manage Accounts to review this account.'
                    : 'The links work for 24 hours and open a confirmation page first. Only one of them can be used. You can also choose in Notifications in the admin portal.') . '</p></div>';
            $plain = "{$subject}\n\n{$body}\n\n" . ($acceptUrl === null
                ? 'Review the account in the owner portal.'
                : "Accept (valid 24 hours): {$acceptUrl}\nLock this account: {$lockUrl}");

            (new EmailNotifier())->toRoleHtml('Admin', $subject, $html, $plain);
        } catch (\Throwable $e) {
            log_message('error', 'Staff forgot-password alert failed: ' . $e->getMessage());
        }
    }

    /**
     * A staff member changed their login email (after proving the new address with a code). Same
     * always-on alert, with the Lock action, because temporary passwords are emailed to this address.
     */
    public static function emailChanged(array $staff, ?string $oldEmail, string $newEmail): void
    {
        try {
            $title   = 'Staff email changed';
            $message = "{$staff['full_name']} changed their email from " . ($oldEmail !== null && $oldEmail !== '' ? $oldEmail : 'no email')
                . " to {$newEmail} on " . self::now() . '.';

            [$id, $token] = self::pushLockable($staff, $title, $message, 'Security', true);
            $lockUrl      = $token === null ? null : site_url("notification-action/{$id}/{$token}/lock");

            $button = $lockUrl === null ? '' : '<p style="margin:24px 0;"><a href="' . htmlspecialchars($lockUrl, ENT_QUOTES) . '" style="background:#7a2020;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block;">Lock this account</a></p>';
            $html   = '<div style="font-family:\'Poppins\',Arial,sans-serif;"><p><strong>' . esc($title, 'html') . '</strong></p>'
                . '<p>' . esc($message, 'html') . '</p>'
                . $button
                . '<p style="color:#888;font-size:12px;">' . ($lockUrl === null ? 'Log in to the owner portal and open Manage Accounts to review this account.' : 'The link works for 24 hours and opens a confirmation page first. You can also lock the account from Notifications in the admin portal.') . '</p></div>';
            $plain = "{$title}\n\n{$message}\n\n" . ($lockUrl === null ? 'Review the account under Manage Accounts in the owner portal.' : "Lock this account (valid 24 hours): {$lockUrl}");

            (new EmailNotifier())->toRoleHtml('Admin', $title, $html, $plain);
        } catch (\Throwable $e) {
            log_message('error', 'Staff email alert failed: ' . $e->getMessage());
        }
    }

    /**
     * Adds the in-app alert for the owner, linked to the staff member so the Lock (and Accept)
     * action works. With $withToken it also stores a token (hash only) for the email links and
     * returns the raw token, which exists in the email alone.
     *
     * @return array{0: int|string, 1: ?string} [notification id, raw token or null when there is none]
     */
    private static function pushLockable(array $staff, string $title, string $message, string $type, bool $withToken): array
    {
        $token = $withToken ? bin2hex(random_bytes(32)) : null;
        $model = new NotificationModel();
        $id    = $model->push('Admin', $type, $title, $message, null, self::CATEGORY, '/admin/settings?tab=accounts', $token === null ? null : hash('sha256', $token));

        // The lock action needs the columns from AddLockActionToNotifications. If that migration is
        // missing the alert still reaches the owner, just without buttons, instead of vanishing.
        try {
            $model->update($id, [
                'action_user_id'    => (int) $staff['user_id'],
                'action_expires_at' => date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'Staff alert has no Lock action (run php spark migrate): ' . $e->getMessage());
            $token = null;
        }

        return [$id, $token];
    }

    /** "Chrome on Windows" from the request's user agent, or a plain fallback. */
    public static function deviceName(): string
    {
        $agent = service('request')->getUserAgent();

        if ($agent->isRobot() || ! $agent->isBrowser()) {
            return 'an unknown device';
        }

        $browser  = $agent->getBrowser();
        $platform = $agent->getPlatform();

        return $platform !== '' && $platform !== 'Unknown Platform' ? "{$browser} on {$platform}" : $browser;
    }

    /**
     * An open lock request for this notification: a security alert not yet
     * used, pointing at a staff account that still exists. Null otherwise.
     */
    public static function lockableStaff(?array $notification): ?array
    {
        if (! $notification
            || ($notification['category'] ?? null) !== self::CATEGORY
            || ! empty($notification['status'])
            || empty($notification['action_user_id'])) {
            return null;
        }

        return (new UserModel())->activeStaff()->find((int) $notification['action_user_id']);
    }

    /** An open Accept request: the Forgot-password alert, not yet accepted or locked. Returns the staff member. */
    public static function acceptableStaff(?array $notification): ?array
    {
        if (! $notification || ($notification['type'] ?? null) !== self::TYPE_FORGOT) {
            return null;
        }

        return self::lockableStaff($notification);
    }

    /** The email link's token (compared as a hash) matches and has not run out. */
    public static function validToken(?array $notification, string $token): bool
    {
        return $notification
            && ! empty($notification['action_token'])
            && hash_equals($notification['action_token'], hash('sha256', $token))
            && ! empty($notification['action_expires_at'])
            && strtotime($notification['action_expires_at']) >= time();
    }

    /** Link validity for Lock: right token, not used, not expired. */
    public static function lockableByToken(?array $notification, string $token): ?array
    {
        return self::validToken($notification, $token) ? self::lockableStaff($notification) : null;
    }

    /** Link validity for Accept: right token, still open. */
    public static function acceptableByToken(?array $notification, string $token): ?array
    {
        return self::validToken($notification, $token) ? self::acceptableStaff($notification) : null;
    }

    /**
     * Deactivates the account and spends the alert. RoleAuthFilter re-checks
     * is_active on every request, so the staff member's open sessions end on
     * their next click.
     */
    public static function lock(array $notification, array $staff, string $event, string $actor): void
    {
        (new UserModel())->update((int) $staff['user_id'], ['is_active' => 0]);

        (new NotificationModel())->update((int) $notification['notification_id'], [
            'status'       => self::STATUS_LOCKED,
            'is_read'      => 1,
            'action_token' => null,
        ]);

        SecurityLogModel::log($event, ['user_id' => null, 'username' => $actor, 'role' => 'Admin'], 'staff: ' . $staff['username']);

        // Both the in-system button and the email link end up here.
        self::ownerNotice('Account locked', "You locked the account of {$staff['full_name']} on " . self::now() . '.');
    }

    /**
     * The owner says the Forgot-password reset was really the staff member: the alert is closed
     * (so Lock stops working) and the owner gets a note. The token stays, so the email link can
     * say "Already confirmed".
     */
    public static function accept(array $notification, array $staff, string $event, string $actor): void
    {
        (new NotificationModel())->update((int) $notification['notification_id'], [
            'status'  => self::STATUS_CONFIRMED,
            'is_read' => 1,
        ]);

        SecurityLogModel::log($event, ['user_id' => null, 'username' => $actor, 'role' => 'Admin'], 'staff: ' . $staff['username']);

        self::ownerNotice('Password reset confirmed', "You confirmed {$staff['full_name']}'s password reset on " . self::now() . '.');
    }

    /**
     * An informational in-app note for the owner (no email, no action). Never lets a
     * failed note undo the lock or reset that triggered it.
     */
    public static function ownerNotice(string $title, string $message): void
    {
        try {
            (new NotificationModel())->push('Admin', 'Security', $title, $message, null, self::CATEGORY, '/admin/settings?tab=accounts');
        } catch (\Throwable $e) {
            log_message('error', 'Owner notice failed: ' . $e->getMessage());
        }
    }

    /** Now in the app's timezone (Asia/Manila), e.g. "Nov 7, 2026, 3:21 PM". */
    public static function now(): string
    {
        return date('M j, Y, g:i A');
    }
}
