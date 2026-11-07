<?php

namespace App\Libraries;

use App\Models\UserModel;

/**
 * The last passwords a staff member chose for themselves, so they can't go straight back to one.
 * Only hashes are stored, and only passwords the person picked: an owner-issued temporary password
 * is never recorded (it is not an "old password" for this rule).
 *
 * If the password_history table isn't there yet (migration not run) everything here quietly does
 * nothing extra, so a password change never fails because of it.
 */
class PasswordHistory
{
    /** How many earlier passwords are remembered per person. */
    public const KEEP = 3;

    private const WORDS = [
        'Mango', 'Tiger', 'River', 'Cloud', 'Pearl', 'Maple', 'Eagle', 'Coral', 'Lemon', 'Panda', 'Cedar', 'Falcon',
        'Amber', 'Bamboo', 'Comet', 'Daisy', 'Ember', 'Fjord', 'Grape', 'Hazel', 'Ivory', 'Jade', 'Koala', 'Lotus',
        'Meadow', 'Nutmeg', 'Olive', 'Pebble', 'Quartz', 'Robin', 'Sunny', 'Tulip', 'Walnut', 'Zebra',
    ];

    private const SYMBOLS = ['!', '#', '$', '%', '&', '*'];

    public static function available(): bool
    {
        $db = db_connect();

        // Not cached: the list is read fresh, so a table added by the migration is seen at once.
        return in_array($db->getPrefix() . 'password_history', $db->listTables(false, false), true);
    }

    /** Remembers a password hash the person is replacing, and forgets all but the last KEEP. */
    public static function record(int $userId, ?string $hash): void
    {
        if ($hash === null || $hash === '' || ! self::available()) {
            return;
        }

        $db    = db_connect();
        $table = $db->table('password_history');
        $table->insert(['user_id' => $userId, 'password_hash' => $hash, 'created_at' => date('Y-m-d H:i:s')]);

        $keep = array_column(
            $db->table('password_history')->select('id')->where('user_id', $userId)->orderBy('id', 'DESC')->limit(self::KEEP)->get()->getResultArray(),
            'id'
        );
        if ($keep !== []) {
            $db->table('password_history')->where('user_id', $userId)->whereNotIn('id', $keep)->delete();
        }
    }

    /** True if $password is the person's current password (when asked) or one of their last KEEP earlier ones. */
    public static function reuses(array $user, string $password, bool $includeCurrent = true): bool
    {
        if ($includeCurrent && ! empty($user['password_hash']) && password_verify($password, $user['password_hash'])) {
            return true;
        }
        if (! self::available()) {
            return false;
        }

        $rows = db_connect()->table('password_history')->select('password_hash')->where('user_id', (int) $user['user_id'])
            ->orderBy('id', 'DESC')->limit(self::KEEP)->get()->getResultArray();

        foreach ($rows as $row) {
            if (password_verify($password, $row['password_hash'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * An easy-to-type password such as "Mango-Tiger47!" that passes the password rules and is not
     * in use by another staff account. Never log or store the result.
     */
    public static function suggest(?int $userId = null): string
    {
        $users = new UserModel();

        for ($i = 0; $i < 50; $i++) {
            $first  = self::WORDS[random_int(0, count(self::WORDS) - 1)];
            $second = self::WORDS[random_int(0, count(self::WORDS) - 1)];
            if ($first === $second) {
                continue;
            }

            $candidate = $first . '-' . $second . random_int(10, 99) . self::SYMBOLS[random_int(0, count(self::SYMBOLS) - 1)];

            if (PasswordPolicy::check($candidate) === null && ! $users->staffPasswordInUse($candidate, $userId)) {
                return $candidate;
            }
        }

        // Practically unreachable; fall back to a longer random one that still follows the rules.
        return 'Pw-' . bin2hex(random_bytes(5)) . '!';
    }
}
