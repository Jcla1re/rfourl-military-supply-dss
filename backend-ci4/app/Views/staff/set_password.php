<?php
// Shown after signing in with a temporary password: the only page a staff member can open until they
// set their own. Same split layout as the sign-in page.
$errorMsg   = session()->getFlashdata('error');
$suggestion = session()->getFlashdata('password_suggestion');
?>
<?= $this->extend('auth/_layout') ?>

<?= $this->section('title') ?>Set Your Password<?= $this->endSection() ?>

<?= $this->section('content') ?>
                <h2 class="split-title">Set your password</h2>
                <p class="split-sub">You signed in with a temporary password. Choose your own to continue.</p>

                <?php if (! empty($fullName)): ?>
                    <div class="auth-signed-in">Signed in as <strong><?= esc($fullName) ?></strong> (@<?= esc($username ?? '') ?>)</div>
                <?php endif; ?>

                <?php if ($errorMsg): ?>
                    <div class="auth-alert is-error" role="alert">
                        <?= esc((string) $errorMsg) ?>
                        <?php if (! empty($suggestion)): ?>
                            <button type="button" class="auth-use-suggestion" id="useSuggestion" data-value="<?= esc($suggestion, 'attr') ?>">Use this</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form action="<?= site_url('staff/set-password') ?>" method="post" class="split-fields">
                    <?= csrf_field() ?>
<?= view('auth/_password_fields', ['serverRule' => 'Not the temporary password or one of your last 3 (checked when you save)']) ?>
                    <button type="submit" class="btn-login">
                        <span>Save and continue</span> <i class="bi bi-arrow-right btn-login-arrow" aria-hidden="true"></i>
                    </button>
                </form>

                <form method="post" action="<?= site_url('logout') ?>" class="split-signout" data-logout>
                    <?= csrf_field() ?>
                    <button type="submit"><i class="bi bi-arrow-left" aria-hidden="true"></i> Sign out</button>
                </form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
    <script src="/assets/js/auth-forms.js"></script>
    <script>
    // "Use this": fills the suggested password into both fields (no reload).
    (function () {
        var btn = document.getElementById('useSuggestion');
        if (!btn) return;
        btn.addEventListener('click', function () {
            document.querySelectorAll('input[name=new_password], input[name=confirm_password]').forEach(function (i) {
                i.value = btn.dataset.value;
                i.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
    })();
    </script>
<?= $this->endSection() ?>
