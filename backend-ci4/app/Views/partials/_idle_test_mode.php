<?php
// Shown only while test mode is on (localhost: .env auth.idleTestSeconds, development only), so a
// short idle limit can never be mistaken for the real one. Login pages and the three layouts.
$authConfig = config(\Config\Auth::class);
if (! $authConfig->testModeActive()) {
    return;
}
?>
<div class="idle-test-mode" role="note" style="margin:8px auto;padding:4px 10px;max-width:420px;border:1px dashed #b07d00;border-radius:6px;background:#fff8e5;color:#6b4e00;font-size:12px;text-align:center;">Test mode: idle sign-out after <?= (int) $authConfig->idleLimit() ?> s</div>
