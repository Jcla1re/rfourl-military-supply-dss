<?php
// app/Views/partials/_username_hint.php
// For password managers only (never read by the server): the account a password or PIN box in the
// same <form> belongs to. Browsers (Edge, Chrome) pick the text box just before a password box as
// its "username" and fill the saved username there. With this box first in the form, that is this
// one, not the item search or another real field.
//
// It must stay in the layout: browsers skip boxes that are display:none, type="hidden" or have the
// hidden attribute. So it is only visually hidden (1px, clipped), read-only, out of the tab order
// and hidden from screen readers. top/left 0: placed at the page's corner, so this 1px box (whose
// position is relative to the page) can never make the page taller than the window.
//
// Expects $username (the signed-in user's). Optional: $name (default 'account_hint').
?>
<input type="text" name="<?= esc($name ?? 'account_hint', 'attr') ?>" value="<?= esc((string) ($username ?? ''), 'attr') ?>" autocomplete="username" readonly tabindex="-1" aria-hidden="true" class="username-hint" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;top:0;left:0;">
