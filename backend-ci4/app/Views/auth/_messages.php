<?php
// The message box above the fields: red for an error, amber for a notice (flashdata 'error' / 'notice').
$__error  = session()->getFlashdata('error');
$__notice = session()->getFlashdata('notice');
?>
<?php if ($__error): ?>
                <div class="auth-alert is-error" role="alert"><?= esc((string) $__error) ?></div>
<?php endif; ?>
<?php if ($__notice): ?>
                <div class="auth-notice" role="status"><?= esc((string) $__notice) ?></div>
<?php endif; ?>
