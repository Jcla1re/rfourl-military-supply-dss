<?php
/**
 * Top of the three login pages (Owner, Staff, Supplier).
 *  - After an idle sign-out only (signed_out_reason 'idle', set by RoleAuthFilter / SessionController;
 *    never after the logout button, which ends the whole session): the amber notice.
 *  - In test mode (localhost: .env auth.idleTestSeconds, development only): a small reminder.
 */
$authConfig = config(\Config\Auth::class);
$idleMin    = max(1, (int) round($authConfig->idleLimit() / 60));
?>
<?php if (session()->get('signed_out_reason') === 'idle'): ?>
    <div class="auth-notice" role="status">You were signed out after <?= $idleMin ?> minute<?= $idleMin === 1 ? '' : 's' ?> of inactivity. Sign in to continue where you left off.</div>
<?php endif; ?>
<?= view('partials/_idle_test_mode') ?>
