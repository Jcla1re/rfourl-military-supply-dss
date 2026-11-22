<?php
// app/Views/partials/_lock_icon.php
// The padlock (approved PosLockV1 board): an outline lock drawn in the text colour (currentColor).
// Decorative: the button or field next to it carries the label.
//
// Names of their own on purpose (like $penIconSize in _pen_icon.php): a view sees its parent view's
// data, and Inventory's pages carry a 'size' (the size filter, often '').
//   $lockIconSize   width/height in px (default 16)
//   $lockIconOpen   true: the shackle open on one side (default false)
//   $lockIconStroke line weight (default 1.8; 1.5 inside input boxes, to match the bi-person icon)
$lockIconSize   = isset($lockIconSize) && (int) $lockIconSize > 0 ? (int) $lockIconSize : 16;
$lockIconOpen   = ! empty($lockIconOpen);
$lockIconStroke = isset($lockIconStroke) && (float) $lockIconStroke > 0 ? (float) $lockIconStroke : 1.8;
?>
<svg class="lock-icon" width="<?= $lockIconSize ?>" height="<?= $lockIconSize ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="<?= $lockIconStroke ?>" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="<?= $lockIconOpen ? 'M8 10V7a4 4 0 0 1 7.5-2' : 'M8 10V7a4 4 0 0 1 8 0v3' ?>"/></svg>
