<?php
// app/Views/partials/_pen_icon.php
// The Edit pen: Inventory's row Edit buttons and My Profile's Edit buttons. Decorative (the button has the label).
// $penIconSize: width/height in px (default 18). A name of its own on purpose: a view sees its parent
// view's data, and Inventory's pages carry a 'size' (the size filter, often ''), which once made this 0x0.
$penIconSize = isset($penIconSize) && (int) $penIconSize > 0 ? (int) $penIconSize : 18;
?>
<svg width="<?= $penIconSize ?>" height="<?= $penIconSize ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>