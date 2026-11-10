<?php

namespace App\Libraries;

use App\Models\NotificationModel;
use App\Models\SecurityLogModel;
use App\Models\UserModel;

/**
 * Staff temporary passwords and resets. Three ways a staff password becomes a temporary one that
 * must be replaced at the next sign-in:
 *
 *   FORGOT  The staff member asks (Forgot password). A temporary password is emailed to them and
 *           waits beside their current password, which keeps working until the temporary one is
 *           actually used (promoteTemp()). Valid 2 hours. No owner approval.
 *   RESET   The owner types a new password in Manage Accounts (ownerReset()). It replaces the old
 *           password at once. The staff member gets an email WITHOUT the password and must ask the
 *           owner for it. Valid 24 hours, so a reset done in the evening still works next morning.
 *   INVITE  A new account (or a resent invite): a temporary password is emailed. Valid 48 hours.
 *
 * Whichever way, the first sign-in forces "Create new password" (complete()), and then the owner
 * is told (StaffPasswordAlert). A temporary password is never stored in plain text, written to a
 * log, or put in a notification; the staff member's emailed one goes only to their email.
 */
class StaffPasswordReset
{
    public const KIND_FORGOT = 'forgot';
    public const KIND_RESET  = 'reset';
    public const KIND_INVITE = 'invite';

    public const FORGOT_TTL      = 7200;   // 2 hours (the emailed Forgot-password temporary password)
    public const OWNER_RESET_TTL = 86400;  // 24 hours (the password the owner types in Manage Accounts)
    public const INVITE_TTL      = 172800; // 48 hours (a new account)

    /** At most this many Forgot-password requests per account per hour. */
    public const FORGOT_PER_HOUR = 3;

    private const ALPHABET_LETTERS = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
    private const ALPHABET_DIGITS  = '23456789';

    /** Address endings that can never receive mail (the seeded "@rfourl.local" placeholders and the like). */
    private const UNDELIVERABLE_ENDINGS = ['local', 'localhost', 'invalid'];

    private UserModel $users;

    public function __construct()
    {
        $this->users = new UserModel();
    }

    public static function ttlFor(string $kind): int
    {
        return match ($kind) {
            self::KIND_INVITE => self::INVITE_TTL,
            self::KIND_RESET  => self::OWNER_RESET_TTL,
            default           => self::FORGOT_TTL,
        };
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

    /** A well-formed address that could receive mail (not a seeded "@rfourl.local"-style placeholder). */
    public static function usableEmail(?string $email): bool
    {
        $email = trim((string) $email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $domain = strtolower(substr(strrchr($email, '@'), 1));

        return str_contains($domain, '.') && ! in_array(substr(strrchr($domain, '.'), 1), self::UNDELIVERABLE_ENDINGS, true);
    }

    /** Signed in with a temporary password and must now create their own. */
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

    /** A Forgot-password temporary password has been emailed and not used yet. */
    public static function hasWaitingTemp(array $user): bool
    {
        return ! empty($user['temp_password_hash']);
    }

    /** This password is the emailed temporary one that is still waiting to be used. */
    public static function isWaitingTemp(array $user, string $password): bool
    {
        return self::hasWaitingTemp($user) && password_verify($password, $user['temp_password_hash']);
    }

    /** The waiting temporary password ran out before it was used. */
    public static function waitingTempExpired(array $user): bool
    {
        return self::hasWaitingTemp($user)
            && ! empty($user['temp_password_expires_at'])
            && strtotime($user['temp_password_expires_at']) < time();
    }

    // ------------------------------------------------------------------ Flow 1: Forgot password

    /**
     * Emails a temporary password to the staff member, valid 2 hours. Their current password is
     * not touched and keeps working until the temporary one is used. All or nothing: if the email
     * can't be sent, nothing is stored. Returns the plain password on success (for demo mode's
     * on-screen box only), null on failure.
     */
    public function requestForgot(array $staff): ?string
    {
        $db = db_connect();
        $db->transStart();

        $password = $this->uniquePassword((int) $staff['user_id']);
        $this->users->update((int) $staff['user_id'], [
            'temp_password_hash'       => password_hash($password, PASSWORD_DEFAULT),
            'temp_password_kind'       => self::KIND_FORGOT,
            'temp_password_expires_at' => date('Y-m-d H:i:s', time() + self::FORGOT_TTL),
        ]);

        if (! $this->emailPassword($staff, $password, self::KIND_FORGOT)) {
            $db->transRollback();

            return null;
        }

        $db->transComplete();

        return $password;
    }

    /**
     * First sign-in with the emailed temporary password: it becomes the account's password, the
     * old one stops working (and is remembered so it can't be reused), and the staff member must
     * create their own before anything else. Returns the updated account.
     */
    public function promoteTemp(array $user): array
    {
        $id = (int) $user['user_id'];

        if (! self::isPending($user)) {
            PasswordHistory::record($id, $user['password_hash'] ?? null);
        }

        $this->users->update($id, [
            'password_hash'        => $user['temp_password_hash'],
            'temp_password_hash'   => null,
            'must_change_password' => 1,
            'temp_password_kind'   => self::KIND_FORGOT,
        ]);
        SecurityLogModel::log('forgot_temp_used', $user, 'signed in with the emailed temporary password');

        return $this->users->find($id);
    }

    // ------------------------------------------------------------------ Flow 2: the owner resets it

    /**
     * The owner sets a new password for the staff member (also unlocking a locked account). The OLD
     * password stops working at that very moment (the owner may be resetting a compromised account),
     * unlike Forgot password, where the old one keeps working beside the emailed temporary one. It works
     * for 24 hours, only to get to "Create new password": the staff member must replace it, so the
     * owner doesn't keep knowing their password. The password the person chose before is remembered.
     */
    public function ownerReset(array $staff, string $newPassword): void
    {
        $id = (int) $staff['user_id'];

        if (! self::isPending($staff)) {
            PasswordHistory::record($id, $staff['password_hash'] ?? null);
        }

        $changes = [
            'password_hash'            => password_hash($newPassword, PASSWORD_DEFAULT),
            'must_change_password'     => 1,
            'temp_password_kind'       => self::KIND_RESET,
            'temp_password_expires_at' => date('Y-m-d H:i:s', time() + self::OWNER_RESET_TTL),
            'is_active'                => 1,
        ];
        if (! empty($staff['temp_password_hash'])) {
            $changes['temp_password_hash'] = null; // an emailed temporary password no longer applies
        }

        $this->users->update($id, $changes);
    }

    /** Tells the staff member the owner reset their password. The email never contains the password. */
    public function emailOwnerReset(array $staff): void
    {
        if (! self::usableEmail($staff['email'] ?? null)) {
            return;
        }

        (new EmailNotifier())->toUser(
            $staff,
            'Your password was reset by the owner',
            'The owner reset your RfourL account password on ' . StaffPasswordAlert::now() . '. Please contact the owner to get your new password.'
        );
    }

    // ------------------------------------------------------------------ new accounts

    /** Sets a fresh temporary password for a new account (invite) and returns it (plain text, once). */
    public function issue(array $staff, string $kind): string
    {
        $password = $this->uniquePassword((int) $staff['user_id']);

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

    public function emailPassword(array $staff, string $password, string $kind): bool
    {
        if (empty($staff['email'])) {
            return false;
        }

        $hours    = (int) (self::ttlFor($kind) / 3600);
        $name     = esc($staff['full_name'], 'html');
        $loginUrl = site_url('login/staff');
        $intro    = $kind === self::KIND_INVITE
            ? 'Your RfourL Military Supply staff account is ready.'
            : 'You asked to reset your RfourL Military Supply password.';

        // Which account it is for (staff sign in by password alone), and that only the newest one works.
        $account  = 'Account: ' . $staff['full_name'] . ' (' . ($staff['username'] ?? '') . ')';
        $replaces = 'This replaces any earlier temporary password. Only the newest one works.';

        $html = "<div style=\"font-family:'Poppins',Arial,sans-serif;\"><p>Hi {$name},</p><p>{$intro}</p>"
            . '<p>' . esc($account, 'html') . '</p>'
            . '<p>Your temporary password is:</p>'
            . '<p style="font-size:22px;font-weight:bold;letter-spacing:2px;">' . esc($password, 'html') . '</p>'
            . "<p>It works for {$hours} hours. Sign in with it, then you will be asked to set your own new password.</p>"
            . '<p>' . esc($replaces, 'html') . '</p>'
            . '<p style="margin:24px 0;"><a href="' . htmlspecialchars($loginUrl, ENT_QUOTES) . '" style="background:#2f6431;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block;">Sign in</a></p>'
            . "<p style=\"color:#888;font-size:12px;\">If you did not ask for this, you can ignore this email: your current password still works. Tell the owner if you are worried.</p></div>";
        $plain = "Hi {$staff['full_name']},\n\n{$intro}\n\n{$account}\n\nYour temporary password is: {$password}\n\n"
            . "It works for {$hours} hours. Sign in with it, then you will be asked to set your own new password.\n{$replaces}\n{$loginUrl}\n\n"
            . 'If you did not ask for this, you can ignore this email: your current password still works. Tell the owner if you are worried.';

        return (new EmailNotifier())->sendHtml($staff, $kind === self::KIND_INVITE ? 'Your staff account is ready' : 'Your temporary password', $html, $plain);
    }

    // ------------------------------------------------------------------ creating their own password

    /**
     * The staff member picks their own password after a temporary one. Clears the flags, then tells
     * the owner in the way that fits how the temporary password came about.
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

        SecurityLogModel::log('password_set_after_temp', $staff, match ($kind) {
            self::KIND_INVITE => 'first sign-in: set own password',
            self::KIND_FORGOT => 'set own password after Forgot password',
            default           => 'set own password after the owner reset it',
        });

        $fresh = $this->users->find((int) $staff['user_id']) ?? $staff;

        if ($kind === self::KIND_FORGOT) {
            StaffPasswordAlert::forgotReset($fresh);        // one email and one in-app alert: Accept / Lock

            return;
        }
        if ($kind === self::KIND_RESET) {
            StaffPasswordAlert::afterOwnerReset($fresh);    // in-app only, with Lock

            return;
        }

        // A new account's first sign-in.
        try {
            $name  = $staff['full_name'];
            $title = "{$name} signed in and set their password";
            $when  = date('M j, Y, g:i A') . ' PH time';
            $html  = '<div style="font-family:\'Poppins\',Arial,sans-serif;"><p><strong>' . esc($title, 'html') . '</strong></p><p>Done on ' . esc($when, 'html') . '. Nothing else to do.</p></div>';

            (new EmailNotifier())->toRoleHtml('Admin', $title, $html, "{$title}\n\nDone on {$when}. Nothing else to do.");
            (new NotificationModel())->push('Admin', 'Security', $title, "Done on {$when}.", null, StaffPasswordAlert::CATEGORY, '/admin/settings?tab=accounts');
        } catch (\Throwable $e) {
            log_message('error', 'Staff password done notice failed: ' . $e->getMessage());
        }
    }

    /** A random temporary password that no other staff account uses (staff sign in by password alone). */
    private function uniquePassword(int $userId): string
    {
        do {
            $password = self::generate();
        } while ($this->users->staffPasswordInUse($password, $userId));

        return $password;
    }
}
