<?php

namespace App\Libraries;

use CodeIgniter\HTTP\IncomingRequest;

/**
 * "Lock POS": the cashier steps away and locks the Point of Sale page (both tabs, checkout and the
 * sales exports) for this session. Other pages stay open. It unlocks only with the signed-in user's
 * own checkout PIN (PosPin::verify(), so wrong tries count toward the 3 tries / 60 s lock), or by
 * signing out.
 *
 * The cart on screen when it was locked is kept in the session with the lock (item IDs and
 * quantities only, never a price) and comes back after unlocking, as the old input the POS page
 * already restores from (partials/_pos_tab.php, pos.js).
 */
final class PosLock
{
    public const SESSION_KEY = 'pos_locked';

    private const MAX_LINES = 200;

    /** The lock (['at' => time, 'cart' => [[id, qty]...]]), or null when the POS is open. */
    public static function current(): ?array
    {
        $lock = session()->get(self::SESSION_KEY);

        return is_array($lock) ? $lock : null;
    }

    public static function isLocked(): bool
    {
        return self::current() !== null;
    }

    /** Locks the POS, keeping the posted cart (item IDs and quantities). */
    public static function lock(IncomingRequest $request): void
    {
        $ids   = array_values((array) ($request->getPost('item_id') ?? []));
        $qtys  = array_values((array) ($request->getPost('quantity') ?? []));
        $lines = [];
        foreach ($ids as $i => $id) {
            $id  = is_scalar($id) ? (string) $id : '';
            $qty = (int) ($qtys[$i] ?? 0);
            if ($id !== '' && strlen($id) <= 15 && $qty > 0 && count($lines) < self::MAX_LINES) {
                $lines[] = [$id, min($qty, 9999)];
            }
        }

        session()->set(self::SESSION_KEY, ['at' => time(), 'cart' => $lines]);
    }

    /** Unlocks, and hands the kept cart to the next POS page as old input. */
    public static function unlock(): void
    {
        $lock = self::current();
        session()->remove(self::SESSION_KEY);

        $cart = (array) ($lock['cart'] ?? []);
        if ($cart !== []) {
            session()->setFlashdata('_ci_old_input', ['get' => [], 'post' => [
                'item_id'  => array_map(static fn ($l) => (string) $l[0], $cart),
                'quantity' => array_map(static fn ($l) => (string) $l[1], $cart),
            ]]);
        }
    }
}
