<?php

namespace App\Controllers;

use App\Libraries\PosLock;
use App\Libraries\PosPin;
use App\Libraries\Toast;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * The checkout PIN and "Lock POS" on the Point of Sale page, the same for the Owner's and the Staff's
 * SalesController (each says which area it is in with posBase()). See App\Libraries\PosPin and
 * App\Libraries\PosLock.
 */
trait PosPinActions
{
    /** 'admin' or 'staff'. */
    abstract protected function posBase(): string;

    private function posBack(): RedirectResponse
    {
        return redirect()->to('/' . $this->posBase() . '/sales');
    }

    private function posUser(): ?array
    {
        $id = session()->get('user_id');

        return $id === null ? null : (new UserModel())->find((int) $id);
    }

    /** What the Point of Sale page needs to know about the PIN and the lock. */
    protected function posPinData(): array
    {
        $user = $this->posUser();

        return [
            'posLock'     => PosLock::current(),
            'posPinReady' => PosPin::ready(),
            'posHasPin'   => PosPin::hasPin($user),
            'posPinReset' => ! PosPin::hasPin($user) && ! empty($user['pos_pin_reset_at']),
            'posUserName' => (string) ($user['full_name'] ?? ''),
            // Staff "Set up your checkout PIN" on this page (Staff\AccountController::setPinFromPos):
            // the error and the field it is about, the username for password managers, the phone rule.
            'posPinError'      => session()->getFlashdata('pin_error'),
            'posPinErrorField' => session()->getFlashdata('pin_error_field'),
            'posUsername'      => (string) ($user['username'] ?? ''),
            'posUserPhone'     => $user['phone'] ?? null,
            // Lock POS: a refused unlock (shown under the PIN box).
            'posUnlockError'   => session()->getFlashdata('unlock_error'),
            // A refused checkout PIN: shown in the reopened checkout PIN dialog (pos-pin.js).
            'posCheckoutPinError'  => session()->getFlashdata('checkout_pin_error'),
            'posCheckoutPinLocked' => (bool) session()->getFlashdata('checkout_pin_locked'),
        ];
    }

    /**
     * Before a checkout: null when it may go ahead (POS not locked, right PIN). Otherwise the way back
     * with the reason; nothing was saved and the cart comes back (item IDs and quantities, never the PIN).
     */
    protected function checkoutGate(): ?RedirectResponse
    {
        if (PosLock::isLocked()) {
            return $this->posBack()->with('toast', Toast::error('Point of Sale is locked', 'Enter your checkout PIN to unlock it. Nothing was saved.'));
        }

        $result = PosPin::verify($this->posUser(), (string) $this->request->getPost(PosPin::FIELD));
        if ($result['ok']) {
            return null;
        }

        PosPin::keepInput($this->request);
        [$title, $message] = PosPin::refusal($result, ' Nothing was saved.');
        $code = $result['code'] ?? 'wrong';

        // No PIN set at all: a toast, as before (there is nothing to type in the dialog).
        if ($code === 'no_pin') {
            return $this->posBack()->with('toast', Toast::error($title, $message));
        }

        // A typed PIN refused (wrong, empty, or the 3 tries / 60 s lock): the same text, shown in the
        // checkout PIN dialog, which pos-pin.js reopens after this reload. While locked it opens with
        // only Cancel usable (the server still refuses until the lock ends).
        return $this->posBack()
            ->with('checkout_pin_error', $title . '. ' . $message)
            ->with('checkout_pin_locked', in_array($code, ['locked', 'just_locked'], true));
    }

    /** Pages of the Point of Sale other than the page itself (exports): refused while it is locked. */
    protected function refuseWhileLocked(): ?RedirectResponse
    {
        return PosLock::isLocked()
            ? $this->posBack()->with('toast', Toast::error('Point of Sale is locked', 'Enter your checkout PIN to unlock it.'))
            : null;
    }

    /** Lock POS: only with a PIN set, since only the PIN unlocks it. The cart on screen is kept. */
    public function lock()
    {
        if (! PosPin::hasPin($this->posUser())) {
            return $this->posBack()->with('toast', Toast::error("Couldn't lock the Point of Sale", 'Set your checkout PIN first, so you can unlock it again.'));
        }
        if (! PosLock::isLocked()) {
            PosLock::lock($this->request);
        }

        return $this->posBack();
    }

    /** Unlock with the signed-in user's own PIN (wrong tries count toward the 3 tries / 60 s lock). */
    public function unlock()
    {
        if (! PosLock::isLocked()) {
            return $this->posBack();
        }

        $result = PosPin::verify($this->posUser(), (string) $this->request->getPost(PosPin::FIELD));
        if (! $result['ok']) {
            [$title, $message] = PosPin::refusal($result);
            if (($result['code'] ?? null) === 'no_pin') {
                [$title, $message] = ['Your checkout PIN was reset', 'Sign out and sign in again to use the Point of Sale.'];
            }

            // Shown under the PIN box on the lock screen (partials/_pos_locked.php), in place of the hint.
            return $this->posBack()->with('unlock_error', $title . '. ' . $message);
        }

        // How many cart lines were kept with the lock, for the note's text (read before unlocking clears it).
        $kept = count((array) (PosLock::current()['cart'] ?? []));
        PosLock::unlock();

        // The app's usual success toast (hides itself after 4 s): "Unlocked. Your order is still here."
        // when lines were kept, "Unlocked." when the cart was empty. The key lets pos.js say just
        // "Unlocked." if none of the kept items could come back.
        return $this->posBack()->with('toast', Toast::success(
            $kept > 0 ? 'Unlocked. Your order is still here.' : 'Unlocked.', null, null, ['key' => 'pos-unlocked']
        ));
    }
}
