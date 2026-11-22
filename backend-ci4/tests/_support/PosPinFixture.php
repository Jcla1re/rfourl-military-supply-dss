<?php

namespace Tests\Support;

use App\Libraries\PosPin;

/**
 * The checkout PIN (App\Libraries\PosPin) that the fixtures' owner and staff accounts have, so a test
 * checkout can post it. It passes the weak-PIN rules. Hashed at bcrypt cost 4 so tests stay quick;
 * password_verify() reads the cost from the hash.
 */
final class PosPinFixture
{
    public const PIN = '4826';

    /** The users columns: pos_pin_hash, pos_pin_set_at, pos_pin_reset_at. */
    public const COLUMNS = ['pos_pin_hash', 'pos_pin_set_at', 'pos_pin_reset_at'];

    private static ?string $hash = null;

    public static function hash(): string
    {
        return self::$hash ??= password_hash(self::PIN, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    /** What a checkout (or unlock) form posts for the PIN. */
    public static function field(?string $pin = self::PIN): array
    {
        return [PosPin::FIELD => $pin];
    }

    /** The 3 tries / 60 s lock is kept per user id in the server cache: clears it for the ids tests use. */
    public static function clearLocks(): void
    {
        for ($id = 1; $id <= 20; $id++) {
            PosPin::clearLock($id);
        }
    }

    /** Forge field definitions for the PIN columns of a test users table. */
    public static function forgeFields(): array
    {
        return [
            'pos_pin_hash'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'pos_pin_set_at'   => ['type' => 'DATETIME', 'null' => true],
            'pos_pin_reset_at' => ['type' => 'DATETIME', 'null' => true],
        ];
    }
}
