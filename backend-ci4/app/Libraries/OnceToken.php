<?php

namespace App\Libraries;

use CodeIgniter\HTTP\IncomingRequest;

/**
 * One-time submission keys: the server-side guard against the same form being saved twice
 * (double click, Enter + click, a slow response, a resubmit, two devices).
 *
 *   In the form:       <?= OnceToken::field('pos_checkout') ?>       (a hidden random key)
 *   In the controller: $key = OnceToken::key($this->request);
 *                      if ($earlier = OnceToken::used($key)) { ...show the first result... }
 *                      $db->transStart();
 *                      if ($earlier = OnceToken::claim($key, 'pos_checkout', $userId)) { ...same... }
 *                      ...save...
 *                      OnceToken::complete($key, 'sale', $salesId);
 *                      $db->transComplete();
 *
 * claim() inserts the key into form_submissions inside the caller's transaction. The UNIQUE
 * index refuses a key that was already used, also when two requests race (the second waits for
 * the first to commit, then fails). When that happens claim() rolls the caller's transaction
 * back and returns the first submission's row, so nothing is saved twice. If the caller's
 * transaction rolls back for another reason (e.g. not enough stock), the key is released with
 * it and the same form can be sent again.
 *
 * A request without a key (an old page, an API client) is not protected by this, but still gets
 * every other check (stock, status rules). Before the form_submissions migration has run, keys
 * are ignored, so a deploy in the wrong order never blocks a sale.
 */
class OnceToken
{
    public const FIELD = 'submission_key';

    private const TABLE = 'form_submissions';

    /** Hidden input with a fresh random key (32 hex characters). $action is for readers of the view. */
    public static function field(string $action): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . bin2hex(random_bytes(16)) . '" data-once-action="' . esc($action, 'attr') . '">';
    }

    /** The posted key, or null when there is none or it isn't one of ours. */
    public static function key(IncomingRequest $request): ?string
    {
        $key = (string) $request->getPost(self::FIELD);

        return preg_match('/^[0-9a-f]{32}$/', $key) === 1 ? $key : null;
    }

    /** The first submission's row (result_type, result_id...) when $key was already used, else null. */
    public static function used(?string $key): ?array
    {
        if ($key === null || ! self::ready()) {
            return null;
        }

        return db_connect()->table(self::TABLE)->where('submission_key', $key)->get()->getRowArray() ?: null;
    }

    /**
     * Inside the caller's transaction: takes the key. Returns null when it is now ours (carry
     * on), or the first submission's row when it was already used; the caller's transaction has
     * then been rolled back and the caller must stop.
     */
    public static function claim(?string $key, string $action, ?int $userId): ?array
    {
        if ($key === null || ! self::ready()) {
            return null;
        }

        if ($earlier = self::used($key)) {
            self::rollBack();

            return $earlier;
        }

        $db = db_connect();
        $ok = $db->table(self::TABLE)->insert([
            'submission_key' => $key,
            'action'         => $action,
            'user_id'        => $userId,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        if ($ok) {
            return null;
        }

        // Refused by the UNIQUE index: another request took the key a moment ago. Undo ours,
        // then read its row (outside our transaction, so its commit is visible).
        self::rollBack();

        return self::used($key) ?? ['submission_key' => $key, 'action' => $action, 'result_type' => null, 'result_id' => null];
    }

    /** What the submission created, so a repeat can show it (e.g. 'sale', 737). */
    public static function complete(?string $key, string $type, string|int $id): void
    {
        if ($key === null || ! self::ready()) {
            return;
        }

        db_connect()->table(self::TABLE)->where('submission_key', $key)->update(['result_type' => $type, 'result_id' => (string) $id]);
    }

    /** Deletes keys older than $days days; returns how many. Safe to run at any time. */
    public static function purgeOlderThan(int $days = 7): int
    {
        if (! self::ready()) {
            return 0;
        }

        $db = db_connect();
        $db->table(self::TABLE)->where('created_at <', date('Y-m-d H:i:s', strtotime("-{$days} days")))->delete();

        return $db->affectedRows();
    }

    /** Whether the form_submissions migration has run (CI caches the table list per connection). */
    private static function ready(): bool
    {
        return db_connect()->tableExists(self::TABLE);
    }

    /** Rolls the caller's whole transaction back and clears the failed flag for the rest of the request. */
    private static function rollBack(): void
    {
        $db = db_connect();
        while ($db->transDepth > 0) {
            $db->transStatus() === false ? $db->transComplete() : $db->transRollback();
        }
        $db->resetTransStatus();
    }
}
