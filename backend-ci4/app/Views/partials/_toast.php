<?php
// app/Views/partials/_toast.php
// Shared toast (rendered by the admin/staff/supplier layouts from the
// 'toast' flash set with App\Libraries\Toast). Behaviour — countdown,
// pause on hover/focus, × and Esc to dismiss — is in assets/js/toast-timer.js.
//
// Expects $toast: ['type' => 'success'|'info'|'error', 'title', 'message'?, 'name'?, 'undo'?, 'action'?, 'key'?, 'sticky'?, 'busy'?]

if (! is_array($toast ?? null) || empty($toast['title'])) {
    return;
}
$icons   = ['success' => 'bi-check-circle-fill', 'info' => 'bi-info-circle-fill', 'error' => 'bi-exclamation-circle-fill'];
$type    = isset($icons[$toast['type'] ?? '']) ? $toast['type'] : 'success';
$isError = $type === 'error';
$undoUrl = ! $isError ? ($toast['undo'] ?? null) : null;
$action  = ! $isError && is_array($toast['action'] ?? null) ? $toast['action'] : null; // ['label' => ..., 'url' => ...], a plain link
$isBusy  = ! empty($toast['busy']); // in progress: spinner instead of the icon, no countdown
// Success/info: 4 s, or 6 s when there's an Undo to reach. Errors, sticky and busy toasts stay (0).
$autohide = $isError || $isBusy || ! empty($toast['sticky']) ? 0 : ($undoUrl || $action ? 8000 : 4000);
$query    = service('request')->getUri()->getQuery();
?>
<div class="app-toast is-<?= $type ?><?= $isBusy ? ' is-busy' : '' ?>"<?= $isBusy ? ' aria-busy="true"' : '' ?> data-autohide="<?= $autohide ?>"<?= ! empty($toast['key']) ? ' data-toast-key="' . esc($toast['key'], 'attr') . '"' : '' ?>
     role="<?= $isError ? 'alert' : 'status' ?>" aria-live="<?= $isError ? 'assertive' : 'polite' ?>">
    <div class="toast-icon <?= $type ?>" aria-hidden="true">
        <?php if ($isBusy): ?><span class="toast-spinner"></span><i class="bi bi-hourglass-split toast-busy-static"></i><?php else: ?><i class="bi <?= $icons[$type] ?>"></i><?php endif; ?>
    </div>
    <div class="app-toast-body">
        <div class="toast-title"><?= esc($toast['title']) ?></div>
        <?php if (! empty($toast['name']) || ! empty($toast['message'])): ?>
            <div class="toast-msg">
                <?php if (! empty($toast['name'])): ?>&ldquo;<strong><?= esc($toast['name']) ?></strong>&rdquo; <?php endif; ?><?= esc($toast['message'] ?? '') ?>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($undoUrl): ?>
        <form method="post" action="<?= esc($undoUrl, 'attr') ?>" class="app-toast-undo-form">
            <?= csrf_field() ?>
            <input type="hidden" name="return_query" value="<?= esc($query !== '' ? '?' . $query : '', 'attr') ?>">
            <button type="submit" class="app-toast-undo">Undo</button>
        </form>
    <?php endif; ?>
    <?php if ($action): ?>
        <a href="<?= esc($action['url'], 'attr') ?>" class="app-toast-undo app-toast-link"><?= esc($action['label']) ?></a>
    <?php endif; ?>
    <button type="button" class="app-toast-close" aria-label="Dismiss"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
</div>
