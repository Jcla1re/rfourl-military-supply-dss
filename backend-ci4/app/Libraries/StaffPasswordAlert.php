<?php

namespace App\Libraries;

use App\Models\NotificationModel;
use App\Models\SecurityLogModel;
use App\Models\UserModel;

/**
 * The always-on security alert for "a staff member changed their own
 * password": an in-app notification plus an email to the owner, both with a
 * "Lock account" action. It is deliberately not a notification preference.
 * Never receives the password, so it can't end up in a message or log.
 */
class StaffPasswordAlert
{
    public const CATEGORY      = 'security';
    public const TTL_SECONDS   = 86400;
    public const STATUS_LOCKED = 'locked';

    public static function send(array $staff, string $device): void
    {
        try {
            $name    = $staff['full_name'];
            $when    = date('M j, Y, g:i A') . ' PH time';
            $title   = "{$name} changed their password";
            $message = "Changed on {$when} from {$device}. If this wasn't expected, lock the account.";

            self::alertOwner($staff, $title, $message);
        } catch (\Throwable $e) {
            // The password change itself must never fail because an alert did.
            log_message('error', 'Staff password alert failed: ' . $e->getMessage());
        }
    }

    /**
     * A staff member changed their login email (after proving the new address with a code). Same
     * always-on alert as a password change, with the Lock action, because temporary passwords are
     * emailed to this address.
     */
    public static function emailChanged(array $staff, ?string $oldEmail, string $newEmail): void
    {
        try {
            $title   = 'Staff email changed';
            $message = "{$staff['full_name']} changed their email from " . ($oldEmail !== null && $oldEmail !== '' ? $oldEmail : 'no email')
                . " to {$newEmail} on " . self::now() . '.';

            self::alertOwner($staff, $title, $message);
        } catch (\Throwable $e) {
            log_message('error', 'Staff email alert failed: ' . $e->getMessage());
        }
    }

    /** The in-app notification (with its Lock action) and the email to the owner. May throw; callers catch. */
    private static function alertOwner(array $staff, string $title, string $message): void
    {
        // Only the hash is stored; the raw token exists in the email link alone.
        $token = bin2hex(random_bytes(32));
        $model = new NotificationModel();
        $id    = $model->push('Admin', 'Security', $title, $message, null, self::CATEGORY, '/admin/settings?tab=accounts', hash('sha256', $token));

        // The lock action needs the columns from AddLockActionToNotifications. If
        // that migration is missing the alert still reaches the owner, just
        // without a Lock button, instead of vanishing.
        $lockUrl = null;
        try {
            $model->update($id, [
                'action_user_id'    => (int) $staff['user_id'],
                'action_expires_at' => date('Y-m-d H:i:s', time() + self::TTL_SECONDS),
            ]);
            $lockUrl = site_url("notification-action/{$id}/{$token}/lock");
        } catch (\Throwable $e) {
            log_message('error', 'Staff password alert has no Lock action (run php spark migrate): ' . $e->getMessage());
        }

        $button = $lockUrl === null ? '' : '<p style="margin:24px 0;"><a href="' . htmlspecialchars($lockUrl, ENT_QUOTES) . '" style="background:#7a2020;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block;">Lock this account</a></p>';
        $html   = '<div style="font-family:\'Poppins\',Arial,sans-serif;"><p><strong>' . esc($title, 'html') . '</strong></p>'
            . '<p>' . esc($message, 'html') . '</p>'
            . $button
            . '<p style="color:#888;font-size:12px;">' . ($lockUrl === null ? 'Log in to the owner portal and open Manage Accounts to review this account.' : 'The link works for 24 hours and opens a confirmation page first. You can also lock the account from Notifications in the admin portal.') . '</p></div>';
        $plain = "{$title}\n\n{$message}\n\n" . ($lockUrl === null ? 'Review the account under Manage Accounts in the owner portal.' : "Lock this account (valid 24 hours): {$lockUrl}");

        (new EmailNotifier())->toRoleHtml('Admin', $title, $html, $plain);
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

    /** Link validity: right token (compared as a hash), not used, not expired. */
    public static function lockableByToken(?array $notification, string $token): ?array
    {
        if (! $notification
            || empty($notification['action_token'])
            || ! hash_equals($notification['action_token'], hash('sha256', $token))
            || empty($notification['action_expires_at'])
            || strtotime($notification['action_expires_at']) < time()) {
            return null;
        }

        return self::lockableStaff($notification);
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
