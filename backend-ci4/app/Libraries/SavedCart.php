<?php

namespace App\Libraries;

/**
 * When the POS page may bring back the cart the browser saved (pos.js, localStorage
 * "rfl.pos.cart": item IDs and quantities only). Only after signing back in from an idle sign-out:
 *
 *  - AuthController marks it at sign-in, only when the sign-in follows an idle sign-out;
 *  - the first POS page after that (within 10 minutes) takes the mark, once, and pos.js restores
 *    the saved cart; every other POS page load does not restore and clears what was saved.
 *
 * The 10 minutes cover signing in on another tab first (e.g. Inventory) and then opening the POS.
 * The browser side also throws the saved cart away when it is another user's or older than 2 hours.
 */
final class SavedCart
{
    public const SESSION_KEY = 'cart_restore_at';

    /** How long after the sign-in the POS page may still restore (seconds). */
    public const WINDOW = 600;

    /** At sign-in: restore allowed only when this sign-in follows an idle sign-out. */
    public static function markSignIn(?string $signedOutReason): void
    {
        if ($signedOutReason === 'idle') {
            session()->set(self::SESSION_KEY, time());
        } else {
            session()->remove(self::SESSION_KEY);
        }
    }

    /** True once, for the first POS page within WINDOW of an idle sign-in; the mark is then gone. */
    public static function take(): bool
    {
        $at = session()->get(self::SESSION_KEY);
        if ($at === null) {
            return false;
        }
        session()->remove(self::SESSION_KEY);

        return (time() - (int) $at) <= self::WINDOW;
    }
}
