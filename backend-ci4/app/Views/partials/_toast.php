<?php
// app/Views/partials/_toast.php
// Shared toast (rendered by the admin/staff/supplier layouts from the
// 'toast' flash set with App\Libraries\Toast). Behaviour — countdown,
// pause on hover/focus, × and Esc to dismiss — is in assets/js/toast-timer.js.
//
// Expects $toast: ['type' => 'success'|'error', 'title', 'message'?, 'name'?, 'undo'?]

if (! is_array($toast ?? null) || empty($toast['title'])) {
    return;
}
$isError = ($toast['type'] ?? 'success') === 'error';
$undoUrl = ! $isError ? ($toast['undo'] ?? null) : null;
// Success: 4 s, or 6 s when there's an Undo to reach. Errors stay (0).
$autohide = $isError ? 0 : ($undoUrl ? 6000 : 4000);
$query    = service('request')->getUri()->getQuery();
?>
<div class="app-toast <?= $isError ? 'is-error' : 'is-success' ?>" data-autohide="<?= $autohide ?>"
     role="<?= $isError ? 'alert' : 'status' ?>" aria-live="<?= $isError ? 'assertive' : 'polite' ?>">
    <div class="toast-icon <?= $isError ? 'error' : 'success' ?>" aria-hidden="true">
        <i class="bi <?= $isError ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill' ?>"></i>
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
    <button type="button" class="app-toast-close" aria-label="Dismiss"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
</div>
