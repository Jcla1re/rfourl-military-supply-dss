<?php

namespace App\Libraries;

use App\Models\NotificationModel;
use App\Models\SecurityLogModel;
use App\Models\UserModel;

/**
 * Staff temporary passwords: one place for "reset" (2 hours) and "invite"
 * (a new account, 48 hours).
 *
 *   issue()          writes a random temporary password hash, flags the account
 *                    must_change_password and sets the expiry. Returns the password.
 *   issueAndEmail()  does that and emails it to the staff member in one database
 *                    transaction. If the email fails, nothing changes.
 *   complete()       the staff member's own new password: clears the flags and
 *                    tells the owner by email.
 *
 * The temporary password goes only to the staff member's email (or, for an
 * owner reset of an account with no email, is shown to the owner once). It is
 * never stored in plain text, written to a log, or put in a notification.
 */
class StaffPasswordReset
{
    public const KIND_RESET  = 'reset';
    public const KIND_INVITE = 'invite';

    public const RESET_TTL  = 7200;   // 2 hours
    public const INVITE_TTL = 172800; // 48 hours

    private const ALPHABET_LETTERS = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
    private const ALPHABET_DIGITS  = '23456789';

    private UserModel $users;

    public function __construct()
    {
        $this->users = new UserModel();
    }

    public static function ttlFor(string $kind): int
    {
        return $kind === self::KIND_INVITE ? self::INVITE_TTL : self::RESET_TTL;
    }

    /** 12 characters, easy to read out (no 0/O, 1/l/I), always with letters and digits. */
    public static function generate(): string
    {
        $all = self::ALPHABET_LETTERS . self::ALPHABET_DIGITS;
        $out = [
            self::ALPHABET_LETTERS[random_int(0, strlen(self::ALPHABET_LETTERS) - 1)],
            self::ALPHABET_DIGITS[random_int(0, strlen(self::ALPHABET_DIGITS) - 1)],
        ];
        while (count($out) < 12) {
            $out[] = $all[random_int(0, strlen($all) - 1)];
        }
        shuffle($out);

        return implode('', $out);
    }

    /** "juan@gmail.com" -> "j***@gmail.com" */
    public static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return $domain === '' ? $email : mb_substr($name, 0, 1) . '***@' . $domain;
    }

    public static function isPending(array $user): bool
    {
        return ! empty($user['must_change_password']);
    }

    public static function isExpired(array $user): bool
    {
        return self::isPending($user)
            && ! empty($user['temp_password_expires_at'])
            && strtotime($user['temp_password_expires_at']) < time();
    }

    /** Sets a fresh temporary password for the account and returns it (plain text, once). */
    public function issue(array $staff, string $kind): string
    {
        // Staff sign in by password alone, so it must not match any other staff password.
        do {
            $password = self::generate();
        } while ($this->users->staffPasswordInUse($password, (int) $staff['user_id']));

        $this->users->update((int) $staff['user_id'], [
            'password_hash'            => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password'     => 1,
            'temp_password_kind'       => $kind,
            'temp_password_expires_at' => date('Y-m-d H:i:s', time() + self::ttlFor($kind)),
        ]);

        return $password;
    }

    /**
     * Issues a temporary password and emails it to the staff member. All or
     * nothing: if the email can't be sent the account is left exactly as it was.
     * Returns the plain password on success, null on failure.
     */
    public function issueAndEmail(array $staff, string $kind): ?string
    {
        $db = db_connect();
        $db->transStart();

        $password = $this->issue($staff, $kind);
        $sent     = $this->emailPassword($staff, $password, $kind);

        if (! $sent) {
            $db->transRollback();

            return null;
        }

        $db->transComplete();

        return $password;
    }

    /**
     * The owner approves a "Can't access your account?" request: the matched staff
     * member gets a temporary password by email (the owner never sees it) and the
     * request is closed. Used by both the email link and the Notifications page.
     *
     * @return array{ok: bool, staff?: array, message?: string}
     */
    public function approveRequest(array $notification, string $actorName): array
    {
        $staff = ! empty($notification['action_user_id'])
            ? $this->users->activeStaff()->where('is_active', 1)->find((int) $notification['action_user_id'])
            : null;

        if (! $staff) {
            return ['ok' => false, 'message' => "We couldn't match this request to a staff account. Use Reset Password in Settings > Manage Accounts instead."];
        }
        if (empty($staff['email'])) {
            return ['ok' => false, 'message' => "{$staff['full_name']} has no email on file. Use Reset Password in Settings > Manage Accounts instead."];
        }
        if ($this->issueAndEmail($staff, self::KIND_RESET) === null) {
            return ['ok' => false, 'message' => "We couldn't send the email, so nothing was changed. Please try again."];
        }

        (new NotificationModel())->approveAccessRequest((int) $notification['notification_id']);
        SecurityLogModel::log('password_reset_approved', ['user_id' => null, 'username' => $actorName, 'role' => 'Admin'], 'staff: ' . $staff['username'] . ', temporary password emailed');

        return ['ok' => true, 'staff' => $staff];
    }

    public function emailPassword(array $staff, string $password, string $kind): bool
    {
        if (empty($staff['email'])) {
            return false;
        }

        $hours   = (int) (self::ttlFor($kind) / 3600);
        $name    = esc($staff['full_name'], 'html');
        $loginUrl = site_url('login/staff');
        $intro   = $kind === self::KIND_INVITE
            ? 'Your RfourL Military Supply staff account is ready.'
            : 'The owner approved your password reset.';

        $html = "<div style=\"font-family:'Poppins',Arial,sans-serif;\"><p>Hi {$name},</p><p>{$intro}</p>"
            . '<p>Your temporary password is:</p>'
            . '<p style="font-size:22px;font-weight:bold;letter-spacing:2px;">' . esc($password, 'html') . '</p>'
            . "<p>It works for {$hours} hours. Sign in with it, then you will be asked to set your own new password.</p>"
            . '<p style="margin:24px 0;"><a href="' . htmlspecialchars($loginUrl, ENT_QUOTES) . '" style="background:#2f6431;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block;">Sign in</a></p>'
            . "<p style=\"color:#888;font-size:12px;\">If you did not ask for this, tell the owner.</p></div>";
        $plain = "Hi {$staff['full_name']},\n\n{$intro}\n\nYour temporary password is: {$password}\n\n"
            . "It works for {$hours} hours. Sign in with it, then you will be asked to set your own new password.\n{$loginUrl}\n\n"
            . 'If you did not ask for this, tell the owner.';

        return (new EmailNotifier())->sendHtml($staff, $kind === self::KIND_INVITE ? 'Your staff account is ready' : 'Your temporary password', $html, $plain);
    }

    /**
     * The staff member picks their own password after a temporary one. Clears
     * the flags and emails the owner that it is done.
     */
    public function complete(array $staff, string $newPassword): void
    {
        $kind = $staff['temp_password_kind'] ?? self::KIND_RESET;

        $this->users->update((int) $staff['user_id'], [
            'password_hash'            => password_hash($newPassword, PASSWORD_DEFAULT),
            'must_change_password'     => 0,
            'temp_password_kind'       => null,
            'temp_password_expires_at' => null,
        ]);

        SecurityLogModel::log('password_set_after_temp', $staff, $kind === self::KIND_INVITE ? 'first sign-in: set own password' : 'set own password after reset');

        try {
            $name  = $staff['full_name'];
            $title = $kind === self::KIND_INVITE ? "{$name} signed in and set their password" : "{$name} set a new password";
            $when  = date('M j, Y, g:i A') . ' PH time';
            $html  = '<div style="font-family:\'Poppins\',Arial,sans-serif;"><p><strong>' . esc($title, 'html') . '</strong></p><p>Done on ' . esc($when, 'html') . '. Nothing else to do.</p></div>';

            (new EmailNotifier())->toRoleHtml('Admin', $title, $html, "{$title}\n\nDone on {$when}. Nothing else to do.");
            (new NotificationModel())->push('Admin', 'Security', $title, "Done on {$when}.", null, StaffPasswordAlert::CATEGORY, '/admin/settings?tab=accounts');
        } catch (\Throwable $e) {
            log_message('error', 'Staff password done notice failed: ' . $e->getMessage());
        }
    }
}
