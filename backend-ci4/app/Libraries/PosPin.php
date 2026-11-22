<?php

namespace App\Libraries;

use App\Filters\CsrfFilter;
use App\Models\SecurityLogModel;
use CodeIgniter\HTTP\IncomingRequest;

/**
 * The personal 4-digit checkout PIN, asked for at every POS checkout and to unlock a locked POS
 * (owner and staff alike). Checked on the server only.
 *
 *  - Only a hash is stored (users.pos_pin_hash, password_hash()). The PIN itself is never logged,
 *    put in the session, flashed as old input (strip(), keepInput()), sent in a notification or
 *    shown again: every PIN input is empty when a page is drawn.
 *  - Set by the user, always confirmed with their account password: staff in My Account or on the
 *    POS page (Staff\AccountController), the owner in My Profile, Sign-in & security
 *    (Admin\SettingsController::updateSignIn). The owner can reset a staff member's PIN (it is then
 *    empty until they set a new one); never see or choose it.
 *  - Weak PINs are refused (weakReason()).
 *  - 3 wrong PINs in a row lock checkout for that USER for 60 seconds. Counted in the server-side
 *    cache (AttemptStore), per user id: signing out, another tab or clearing cookies does not reset
 *    it. When a lock starts the owner gets an in-app security alert (a staff member's has "Lock this
 *    account").
 */
final class PosPin
{
    /** The PIN typed at checkout and to unlock the POS. */
    public const FIELD = 'pos_pin';

    /** A new PIN and its repeat (setting or changing the PIN). */
    public const NEW_FIELD    = 'new_pin';
    public const REPEAT_FIELD = 'new_pin_confirm';

    /** Every posted field that can hold a PIN: never kept as old input. */
    public const FIELDS = [self::FIELD, self::NEW_FIELD, self::REPEAT_FIELD];

    public const MAX_ATTEMPTS = 3;
    public const LOCK_SECONDS = 60;
    private const WINDOW_SECONDS = 900;

    public const HINT = '4 digits. Not easy to guess (no 1234, 1111, 1212 or a year).';

    /** Common PINs that the rules below don't already catch. Public: the live checklist (pin-checklist.js) gets the same list. */
    public const COMMON = ['1004', '2580', '0852', '8520', '0258', '6969', '5683', '2468', '8642', '1357', '7531', '0007', '1110', '0110', '1379', '9731'];

    /**
     * Whether the AddPosPinToUsers migration has run. Read from the table itself each time:
     * fieldExists() keeps a per-connection list that can be out of date (tests drop and recreate users).
     */
    public static function ready(): bool
    {
        foreach (db_connect()->getFieldData('users') as $field) {
            if ($field->name === 'pos_pin_hash') {
                return true;
            }
        }

        return false;
    }

    public static function hasPin(?array $user): bool
    {
        return ! empty($user['pos_pin_hash']);
    }

    /**
     * Null when $pin can be a checkout PIN, otherwise why not. Refused: anything but exactly 4
     * digits; one digit four times (0000); a straight run up or down, also over 9-0 (1234, 4321,
     * 7890); a repeated pair (1212, 1122); a year 19xx or 20xx; a common PIN; the last 4 digits of
     * the user's own contact number.
     */
    public static function weakReason(string $pin, ?string $phone = null): ?string
    {
        if (preg_match('/^\d{4}$/', $pin) !== 1) {
            return 'The PIN must be exactly 4 digits.';
        }

        $d     = array_map('intval', str_split($pin));
        $steps = [($d[1] - $d[0] + 10) % 10, ($d[2] - $d[1] + 10) % 10, ($d[3] - $d[2] + 10) % 10];
        $easy  = count(array_unique($d)) === 1                                  // 0000, 7777
            || $steps === [1, 1, 1] || $steps === [9, 9, 9]                      // 1234, 4321, 7890, 0987
            || ($d[0] === $d[2] && $d[1] === $d[3])                              // 1212, 4545
            || ($d[0] === $d[1] && $d[2] === $d[3])                              // 1122, 7788
            || preg_match('/^(19|20)\d\d$/', $pin) === 1                         // a year
            || in_array($pin, self::COMMON, true);
        if ($easy) {
            return 'That PIN is too easy to guess. Avoid repeated or consecutive digits, years (19xx, 20xx) and common PINs.';
        }

        $phoneDigits = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($phoneDigits) >= 4 && substr($phoneDigits, -4) === $pin) {
            return "Don't use the last 4 digits of your contact number.";
        }

        return null;
    }

    /** [field => message] for a new PIN and its repeat; empty when both are fine. */
    public static function newPinErrors(string $pin, string $repeat, ?string $phone = null): array
    {
        if ($error = self::weakReason($pin, $phone)) {
            return [self::NEW_FIELD => $error];
        }
        if ($repeat !== $pin) {
            return [self::REPEAT_FIELD => "The two PINs don't match."];
        }

        return [];
    }

    /** The users columns to save for a new PIN (the PIN itself only as a hash). */
    public static function columnsFor(string $pin): array
    {
        return [
            'pos_pin_hash'     => password_hash($pin, PASSWORD_DEFAULT),
            'pos_pin_set_at'   => date('Y-m-d H:i:s'),
            'pos_pin_reset_at' => null,
        ];
    }

    /** The owner's reset: no PIN until the staff member sets a new one. */
    public static function resetColumns(): array
    {
        return ['pos_pin_hash' => null, 'pos_pin_set_at' => null, 'pos_pin_reset_at' => date('Y-m-d H:i:s')];
    }

    // ------------------------------------------------------------------ the 3 tries / 60 s lock

    private static function attemptsKey(int $userId): string
    {
        return 'pos_pin_attempts_' . $userId;
    }

    private static function lockKey(int $userId): string
    {
        return 'pos_pin_locked_' . $userId;
    }

    /** Seconds before this user may try a PIN again; 0 when free. */
    public static function lockedFor(int $userId): int
    {
        return AttemptStore::lockedFor(self::lockKey($userId));
    }

    /** After a new PIN (confirmed with the account password) or the owner's reset: the count and lock start again. */
    public static function clearLock(int $userId): void
    {
        AttemptStore::forget(self::attemptsKey($userId));
        AttemptStore::forget(self::lockKey($userId));
    }

    /**
     * Checks the PIN typed by this signed-in user.
     *
     * @return array{ok: bool, code?: string, seconds?: int, left?: int}
     *   code: 'locked' (wait 'seconds'), 'just_locked' (this try started the lock), 'no_pin',
     *   'empty' (nothing typed: not counted), 'wrong' ('left' tries before the lock)
     */
    public static function verify(?array $user, string $typed): array
    {
        $id = (int) ($user['user_id'] ?? 0);
        if ($id === 0) {
            return ['ok' => false, 'code' => 'no_pin'];
        }
        if (($seconds = self::lockedFor($id)) > 0) {
            return ['ok' => false, 'code' => 'locked', 'seconds' => $seconds];
        }
        if (! self::hasPin($user)) {
            return ['ok' => false, 'code' => 'no_pin'];
        }
        if ($typed === '') {
            return ['ok' => false, 'code' => 'empty'];
        }
        if (preg_match('/^\d{4}$/', $typed) === 1 && password_verify($typed, (string) $user['pos_pin_hash'])) {
            AttemptStore::forget(self::attemptsKey($id));

            return ['ok' => true];
        }

        SecurityLogModel::log('pos_pin_failed', self::actor($user), 'wrong checkout PIN');
        $count = AttemptStore::hit(self::attemptsKey($id), self::WINDOW_SECONDS);
        if ($count >= self::MAX_ATTEMPTS) {
            AttemptStore::lock(self::lockKey($id), self::LOCK_SECONDS);
            AttemptStore::forget(self::attemptsKey($id));
            SecurityLogModel::log('pos_pin_locked', self::actor($user), 'checkout locked for ' . self::LOCK_SECONDS . ' s after ' . self::MAX_ATTEMPTS . ' wrong PINs');
            self::notifyOwner($user);

            return ['ok' => false, 'code' => 'just_locked', 'seconds' => self::LOCK_SECONDS];
        }

        return ['ok' => false, 'code' => 'wrong', 'left' => self::MAX_ATTEMPTS - $count];
    }

    /**
     * Toast title and message for a refused verify(). $after ends the message, e.g.
     * " Nothing was saved." at checkout.
     *
     * @return array{0: string, 1: string}
     */
    public static function refusal(array $result, string $after = ''): array
    {
        $role = (string) session()->get('role');

        return match ($result['code'] ?? 'wrong') {
            'locked'      => ['Checkout locked', "Too many wrong PINs. Try again in {$result['seconds']} seconds." . $after],
            'just_locked' => ['Checkout locked', self::MAX_ATTEMPTS . ' wrong PINs. Checkout is locked for ' . self::LOCK_SECONDS . ' seconds'
                . ($role === 'Admin' ? '.' : ' and the owner was notified.') . $after],
            'no_pin'      => ['Set your checkout PIN first', 'You need a 4-digit checkout PIN before you can check out.' . $after],
            'empty'       => ['Enter your checkout PIN', 'Type your 4-digit checkout PIN, then try again.' . $after],
            default       => ['Wrong PIN', $result['left'] . ' ' . ($result['left'] === 1 ? 'try' : 'tries') . ' left before checkout locks for ' . self::LOCK_SECONDS . ' seconds.' . $after],
        };
    }

    /** The owner's in-app alert when a checkout lock starts (never the PIN). */
    private static function notifyOwner(array $user): void
    {
        if (($user['role'] ?? null) === 'Staff') {
            StaffPasswordAlert::checkoutLocked($user);

            return;
        }

        StaffPasswordAlert::ownerNotice(
            'Checkout locked on your account on ' . StaffPasswordAlert::now(),
            self::MAX_ATTEMPTS . ' wrong checkout PINs from ' . StaffPasswordAlert::deviceName() . '. Checkout was locked for '
                . self::LOCK_SECONDS . " seconds. If this wasn't you, change your PIN and password in My Profile."
        );
    }

    private static function actor(array $user): array
    {
        return ['user_id' => $user['user_id'] ?? null, 'username' => $user['username'] ?? null, 'role' => $user['role'] ?? null];
    }

    // ------------------------------------------------------------------ never kept as old input

    /** $post without any PIN field. */
    public static function strip(array $post): array
    {
        return array_diff_key($post, array_flip(self::FIELDS));
    }

    /**
     * Old input for the next page (like withInput()) without any PIN field, the CSRF token, the
     * one-time key or a password.
     */
    public static function keepInput(IncomingRequest $request): void
    {
        $post = self::strip((array) $request->getPost());
        foreach (array_keys($post) as $key) {
            if ($key === config('Security')->tokenName || $key === OnceToken::FIELD || $key === CsrfFilter::LABEL_FIELD || stripos((string) $key, 'pass') !== false) {
                unset($post[$key]);
            }
        }
        session()->setFlashdata('_ci_old_input', ['get' => [], 'post' => $post]);
    }
}
