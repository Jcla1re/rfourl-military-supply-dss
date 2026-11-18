
<?= $this->extend('layouts/admin_layout') ?>
<?= $this->section('content') ?>

<?php
$dss = $dss ?? [];
$admin = $admin ?? [];
$staffAccounts = $staffAccounts ?? [];
$fieldErrors = is_array($fieldErrors ?? null) ? $fieldErrors : [];
$tab = $tab ?? 'profile';
$tabs = [
    'profile'  => 'My Profile',
    'accounts' => 'Manage Accounts',
    'notif'    => 'Notifications',
    'dss'      => 'DSS Parameter',
    'records'  => 'Data & Records',
];
?>

<style>

/* Tabs row (bottom of header) */
.set-tabs {
    display: flex;
    gap: 2px;
    background: #4B6B42;
    padding: 0;
    margin: 0;
}
.set-tabs a {
    flex: 1;
    height: 44px;
    box-sizing: border-box;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    font-size: 15px;
    font-weight: 400;
    color: #fff;
    background: #4B6B42;
    border: 1px solid rgba(255, 255, 255, 0.45);
    border-bottom: none;
    border-radius: var(--radius-control) var(--radius-control) 0 0;
    transition: background-color 0.15s ease;
}
.set-tabs a:hover:not(.active) {
    background: rgba(255, 255, 255, 0.08);
}
.set-tabs a.active {
    background: #F3F1EB;
    color: #1E2616;
    font-weight: 500;
    border: none;
}

.set-body { padding: 24px; }
.set-body h4 { font-weight: 800; }
.set-hint { color: #666; margin-bottom: 20px; }
/* Data & Records tab (exact design spec). Poppins like the rest of the app. */
.rec-tab { font-family: 'Poppins', sans-serif; }
.rec-tab h4, .rec-tab h5, .rec-tab label { font-family: inherit; }
.set-body .rec-page-title { font-size: 24px; font-weight: 800; color: #1F2617; margin: 0; }
.rec-page-sub { font-size: 14px; color: #5C6152; margin: 4px 0 20px; }
.rec-card { background: #FFFFFF; border: 1px solid #E8E5DC; border-radius: 14px; padding: 24px 28px; margin-bottom: 20px; box-shadow: none; }
.rec-title { font-size: 19px; font-weight: 600; color: #1F2617; margin: 0; line-height: 1.3; }
.rec-desc { font-size: 14px; color: #5C6152; line-height: 1.55; margin: 4px 0 0; }
.rec-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px 32px; }
.rec-text { flex: 1 1 320px; min-width: 0; max-width: 560px; }
.rec-controls { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.rec-select {
    width: 290px; height: 46px; padding: 0 40px 0 14px; font-size: 15px; /* fits "Keep 10 years (recommended)" */
    border: 1px solid #D6D2C6; border-radius: 10px; color: #1F2617;
    background: #fff url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'><path d='M1 1l5 5 5-5' stroke='%235C6152' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>") no-repeat right 14px center;
    font-family: 'Poppins', sans-serif; appearance: none; -webkit-appearance: none; -moz-appearance: none; cursor: pointer;
}
.rec-select:focus-visible { outline: 3px solid var(--focus-ring); outline-offset: 2px; }
.rec-btn {
    display: inline-flex; align-items: center; justify-content: center; height: 44px; padding: 0 18px;
    border: 0; border-radius: 10px; background: #71AA60; color: #fff; font-family: 'Poppins', sans-serif;
    font-size: 15px; font-weight: 600; text-decoration: none; white-space: nowrap; cursor: pointer;
    transition: background-color .15s ease;
}
.rec-btn:hover { background: #5F9850; color: #fff; }
.rec-btn:active { background: #4F8542; }
.rec-btn:focus-visible { outline: 3px solid var(--focus-ring); outline-offset: 2px; }
.rec-btn:disabled { background: #E8E5DC; color: #8A8D7E; cursor: not-allowed; }
/* Old sales records: yellow box when some are due, neutral box when none. */
.rec-box { display: flex; flex-wrap: wrap; align-items: center; gap: 14px 16px; margin-top: 16px; padding: 18px 20px; border-radius: 12px; }
.rec-box.is-due { background: #FFF8E6; border: 1px solid #F3E2B3; }
.rec-box.is-clear { background: #F5F3EE; }
.rec-box-ic { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.rec-box.is-due .rec-box-ic { background: #FFF0C7; color: #7A5300; }
.rec-box.is-clear .rec-box-ic { background: #EAF1E3; color: #3B5434; }
.rec-box-ic i { line-height: 1; }
.rec-box-ic i::before { vertical-align: 0; }
.rec-box-text { flex: 1 1 260px; min-width: 0; }
.rec-box-title { font-size: 16px; font-weight: 600; color: #1F2617; margin: 0; }
.rec-box-sub { font-size: 14px; color: #5C6152; margin: 2px 0 0; line-height: 1.5; }

.set-card { background: #fff; border-radius: var(--radius-card); padding: 22px 26px; box-shadow: 0 1px 3px rgba(0,0,0,.06); margin-bottom: 20px; }
.set-field label { font-weight: 600; margin-bottom: 6px; display: block; }
.set-field input, .set-field select { width: 100%; padding: 11px; border: 1px solid #ccc; border-radius: var(--radius-control); }

/* ===== My Profile (Owner) =====
   Left: who you are (avatar, name, role, account facts). Right: Personal information and
   Sign-in & security, each with a view and an inline form opened by its Edit button.
   Titles 600 and sentence case; no 700 here. */
.pf-tab { font-family: 'Poppins', sans-serif; }
.pf-tab :is(button, input, h2) { font-family: inherit; }
.pf-layout { display: grid; grid-template-columns: 300px minmax(0, 1fr); gap: 20px; align-items: stretch; }
@media (max-width: 900px) { .pf-layout { grid-template-columns: minmax(0, 1fr); } }
/* While a card on the right is in edit mode the left card doesn't change: .is-locked keeps the
   height it had in view mode (inline height, set by the script below); .is-natural (a refused save
   reopened a form, so no view-mode height to measure) uses its own height. One column: no locking. */
.pf-who.is-locked,
.pf-who.is-natural { align-self: start; }
@media (max-width: 900px) { .pf-who.is-locked { height: auto !important; } }
.pf-layout .set-card { margin-bottom: 0; }
.pf-main { display: flex; flex-direction: column; gap: 20px; min-width: 0; }

/* Left card */
.pf-who { display: flex; flex-direction: column; align-items: center; text-align: center; min-width: 0; }
.pf-avatar {
    width: 76px; height: 76px; flex-shrink: 0; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: #EAF1E3; color: #2F5A27; font-size: 26px; font-weight: 600; line-height: 1;
    user-select: none;
}
.pf-who-name { margin: 14px 0 8px; max-width: 100%; font-size: 18px; font-weight: 600; line-height: 1.35; color: #1F2617; overflow-wrap: anywhere; }
.pf-who-divider { align-self: stretch; height: 1px; margin: 20px 0 16px; border: 0; background: #F0EDE6; opacity: 1; }
/* The facts: left-aligned, label above value, the full card width (the avatar, name and pill stay centred). */
.pf-facts { align-self: stretch; display: flex; flex-direction: column; gap: 14px; margin: 0; text-align: left; }
.pf-facts dt { margin: 0 0 3px; font-size: 12px; font-weight: 400; color: #5C6152; }
.pf-facts dd { margin: 0; font-size: 14px; color: #1F2617; overflow-wrap: anywhere; }

/* Right cards: title (+ gray line) on the left, Edit on the right */
.pf-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 8px; }
.pf-tab .pf-title { margin: 0; font-size: 16px; font-weight: 600; line-height: 36px; color: #1F2617; }
.pf-sub { margin: -6px 0 0; font-size: 13px; line-height: 1.5; color: #5C6152; }
/* Edit: the app's white secondary button, with Inventory's pen. Keyboard focus: the shared ring. */
.pf-edit {
    flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px;
    height: 36px; padding: 0 14px; border: 1px solid #D6D2C6; border-radius: var(--radius-control);
    background: #fff; color: #1F2617; font-size: 14px; font-weight: 500; line-height: 1; cursor: pointer;
    transition: background-color .15s ease;
}
.pf-edit:hover:not(:disabled) { background: #F7F5F0; }
.pf-edit svg { width: 16px; height: 16px; flex: none; }
.pf-edit:disabled { color: var(--btn-disabled-text); cursor: not-allowed; }
.pf-edit[hidden], .pf-view[hidden], .pf-form[hidden] { display: none !important; }

/* View rows: label column 180px, the value right next to it */
.pf-rows { margin: 0; }
.pf-rows > div { display: grid; grid-template-columns: 180px minmax(0, 1fr); gap: 16px; align-items: baseline; padding: 12px 0; border-bottom: 1px solid #F0EDE6; }
.pf-rows > div:last-child { border-bottom: 0; padding-bottom: 2px; }
.pf-rows dt { margin: 0; font-size: 14px; font-weight: 400; color: #5C6152; }
.pf-rows dd { margin: 0; font-size: 14.5px; color: #1F2617; overflow-wrap: anywhere; }
.sec-dots { letter-spacing: 3px; }

/* Edit forms: the same 180px label column, the input next to it */
.pf-form { margin-top: 8px; }
.pf-form .pf-field { display: grid; grid-template-columns: 180px minmax(0, 1fr); gap: 6px 16px; align-items: start; margin-bottom: 16px; }
.pf-form .pf-field[hidden] { display: none; }
.pf-form .pf-field > label,
.pf-form .im-label-row { padding-top: 13px; }
.pf-form label { margin: 0; font-size: 14px; font-weight: 500; color: #1F2617; }
.pf-control { min-width: 0; }
.pf-form .pf-field input {
    width: 100%; height: 46px; padding: 0 14px; font-size: 15px; color: #1F2617;
    border: 1px solid #D6D2C6; border-radius: 10px; background: #fff;
}
.pf-form .pf-field input:focus { outline: none; border-color: #71AA60; box-shadow: 0 0 0 3px rgba(113, 170, 96, .25); }
.pf-hint { margin-top: 6px; font-size: 13px; line-height: 1.45; color: #5C6152; }
/* A refused save or a live check: the message under its field. */
.pf-form .pf-field input.is-invalid { border-color: #A1261B; }
.pf-form .pf-field input.is-invalid:focus { border-color: #A1261B; box-shadow: 0 0 0 3px rgba(161, 38, 27, .15); }
.pf-error { margin-top: 6px; font-size: 13px; color: #A1261B; }
.pf-error:empty { margin-top: 0; }
/* Current password: last, in a light box (aligned with the fields above it). */
.pf-current { margin: 4px -16px 0; padding: 14px 16px; border-radius: 10px; background: #F7F5F0; }
.pf-current .pf-field { margin-bottom: 0; grid-template-columns: 180px minmax(0, 1fr); }
/* Full name counter: the same look as Add item's Item name ("25 / 60" on the label's line;
   rules copied from assets/css/item-modal.css, which is the whole dialog stylesheet). */
.im-label-row { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
.im-count { font-size: 12px; line-height: 1; color: #5C6152; font-variant-numeric: tabular-nums; }
.im-count.is-near { color: #8A6A00; }
.im-count.is-over { color: #A1261B; }
.pf-form .im-label-row label { margin: 0; }

/* Footer, like Add item: "Fix n errors to save" on the left, then Cancel and Save changes. */
.pf-foot { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 12px; margin-top: 20px; padding-top: 16px; border-top: 1px solid #F0EDE6; }
.pf-changes { margin-right: auto; font-size: 13px; color: #A1261B; }
.pf-foot-actions { display: flex; gap: 10px; }
.pf-cancel,
.pf-tab .pf-save.btn { height: 40px; padding: 0 18px; border-radius: var(--radius-control); font-size: 14px; font-weight: 500; display: inline-flex; align-items: center; justify-content: center; }
.pf-cancel { border: 1px solid #D6D2C6; background: #fff; color: #1F2617; cursor: pointer; transition: background-color .15s ease; }
.pf-cancel:hover { background: #F7F5F0; }
/* Save changes while it can't be used (nothing changed yet, an error, saving): light green, still readable. */
:root .pf-tab .pf-save.btn:disabled { background-color: #B9D3AE; border-color: #B9D3AE; color: #fff; opacity: 1; }

@media (max-width: 576px) {
    .pf-rows > div,
    .pf-form .pf-field,
    .pf-current .pf-field { grid-template-columns: minmax(0, 1fr); gap: 4px; }
    .pf-form .pf-field > label,
    .pf-form .im-label-row { padding-top: 0; }
    .pf-cancel, .pf-tab .pf-save.btn { min-height: 44px; }
}
/* Account status pills (My Profile and Manage Accounts): the shared .status-pill, sized 13px. */
.status-pill.acct { font-size: 13px; font-weight: 600; padding: 4px 12px; border-radius: var(--radius-pill); gap: 6px; white-space: nowrap; }
.status-pill.acct::before { width: 6px; height: 6px; }
.status-pill.acct.green { background: #EAF1E3; color: #2F5A27; }
.status-pill.acct.green::before { background: #4F9A3C; }
.status-pill.acct.gray { background: #ECEAE4; color: #5C6152; }
.status-pill.acct.gray::before { background: #8C8A82; }

.acct-row { display: flex; align-items: center; gap: 14px; padding: 14px 0; border-bottom: 1px solid #eee; }
.acct-row:last-child { border-bottom: none; }
.acct-avatar { width: 42px; height: 42px; border-radius: 50%; background: #f0efe9; display: flex; align-items: center; justify-content: center; font-weight: 700; }
.acct-name { font-weight: 700; }
.acct-email { color: #777; font-size: 13px; }
/* Role pill (Owner and Staff alike; My Profile and Manage Accounts): one neutral style. */
.acct-role-pill { background: #e9e7e1; border-radius: var(--radius-pill); padding: 4px 12px; font-size: 12px; font-weight: 600; }

.notif-pref-row { display: flex; justify-content: space-between; align-items: center; padding: 16px 0; border-bottom: 1px solid #eee; }
.notif-pref-row:last-child { border-bottom: none; }
.notif-pref-row strong { display: block; }
.notif-pref-row span.desc { color: #777; font-size: 13px; }

.dss-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
@media (max-width: 900px) { .dss-cards { grid-template-columns: 1fr; } }
.dss-formula { background: var(--content-bg); border-radius: var(--radius-control); padding: 14px 16px; font-size: 13px; text-align: center; margin-top: 14px; }
.dss-run { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 6px; }
.dss-run-help { margin: 0 0 18px; font-size: 13px; color: #5C6152; }
.dss-run-status { margin: 0 0 6px; font-size: 13px; font-weight: 500; color: #2C3A1E; }
.dss-last-run { font-size: 14px; color: #5C6152; }
.dss-last-run strong { color: #1F2617; font-weight: 600; }
.dss-spin { display: inline-block; animation: dssSpin 1s linear infinite; }
@keyframes dssSpin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .dss-spin { animation: none; } }
.dss-warning { background: #eee; border-radius: var(--radius-control); padding: 16px 20px; display: flex; gap: 14px; margin-bottom: 20px; }
</style>

<div class="page-wrap" style="padding:0;">
    <div class="set-tabs sticky-tabs">
        <?php foreach ($tabs as $key => $label): ?>
            <a href="?tab=<?= $key ?>" class="<?= $tab === $key ? 'active' : '' ?>"><?= esc($label) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="set-body">
        <?php if (!empty($success)): ?><div class="alert alert-success"><?= esc($success) ?></div><?php endif; ?>
        <?php if (!empty($error) && empty($fieldErrors)): ?><div class="alert alert-danger"><?= esc($error) ?></div><?php endif; ?>

        <?php if ($tab === 'profile'): ?>
            <?php
            $roleLabel = 'Owner';
            $fullName  = (string) ($admin['full_name'] ?? '');
            $initials  = initials($fullName);

            // A refused save (SettingsController::refuseForm) reopens its form with one message per field.
            // 'form' = a message for the whole form (attempt limit, save failed).
            $openForm  = $fieldErrors['form'] ?? null; // 'personal' | 'signin' | null
            $errs      = (array) ($fieldErrors['fields'] ?? []);
            $err       = static fn (string $form, string $field): ?string => $openForm === $form ? ($errs[$field] ?? null) : null;
            // The field's red state, tied to its hint (if any) and its error line.
            $invalid   = static function (string $form, string $field, string $id, bool $hint = false) use ($err): string {
                $bad = $err($form, $field) !== null;
                $ids = trim(($hint ? $id . 'Hint ' : '') . ($bad ? $id . 'Err' : ''));

                return ($bad ? ' class="is-invalid" aria-invalid="true"' : '') . ($ids !== '' ? ' aria-describedby="' . $ids . '"' : '');
            };
            $errLine   = static fn (string $form, string $field, string $id): string => ($m = $err($form, $field)) !== null
                ? '<div class="pf-error" id="' . $id . 'Err">' . esc($m) . '</div>' : '';
            // What was typed before a refused save (never a password: those are not kept). Escaped where used.
            $typed     = static fn (string $key, $default) => old($key, $default ?? '', false);

            // The sign-in before this one (users.last_login is already this session's).
            $prevLogin  = session()->get('previous_login');
            $lastSignIn = ! empty($prevLogin) ? date('M j, Y g:i A', strtotime($prevLogin)) : 'This is your first sign-in';

            // Contact number shown grouped (0985 214 5866) when it is an 11-digit 09 number; the saved value is unchanged.
            $phone        = (string) ($admin['phone'] ?? '');
            $phoneDigits  = preg_replace('/\D+/', '', $phone);
            $phoneDisplay = preg_match('/^09\d{9}$/', $phoneDigits) ? substr($phoneDigits, 0, 4) . ' ' . substr($phoneDigits, 4, 3) . ' ' . substr($phoneDigits, 7) : $phone;

            $pfEdit = $openForm === 'personal';
            $siEdit = $openForm === 'signin';
            $siEmail        = (string) $typed('email', $admin['email'] ?? '');
            $siEmailChanged = mb_strtolower(trim($siEmail)) !== mb_strtolower(trim((string) ($admin['email'] ?? '')));
            ?>
            <div class="pf-tab pf-layout">
                <section class="set-card pf-who<?= ($pfEdit || $siEdit) ? ' is-natural' : '' ?>" id="pfWho" aria-label="Your account">
                    <div class="pf-avatar" role="img" aria-label="<?= esc($fullName, 'attr') ?>"><span aria-hidden="true"><?= esc($initials) ?></span></div>
                    <div class="pf-who-name"><?= esc($fullName) ?></div>
                    <span class="acct-role-pill"><?= esc($roleLabel) ?></span>
                    <hr class="pf-who-divider">
                    <dl class="pf-facts">
                        <div><dt>Account status</dt><dd><span class="status-pill acct <?= ! empty($admin['is_active']) ? 'green' : 'gray' ?>"><?= ! empty($admin['is_active']) ? 'Active' : 'Inactive' ?></span></dd></div>
                        <div><dt>Member since</dt><dd><?= ! empty($admin['created_at']) ? esc(date('F j, Y', strtotime($admin['created_at']))) : '—' ?></dd></div>
                        <div><dt>Last sign-in</dt><dd><?= esc($lastSignIn) ?></dd></div>
                    </dl>
                </section>

                <div class="pf-main">
                    <section class="set-card" id="pfCard" aria-labelledby="pfTitle">
                        <div class="pf-head">
                            <h2 class="pf-title" id="pfTitle">Personal information</h2>
                            <button type="button" class="pf-edit" id="pfEditBtn" aria-label="Edit personal information"<?= $pfEdit ? ' hidden' : '' ?><?= demo_lock_attr($admin ?? null) ?>><?= view('partials/_pen_icon', ['penIconSize' => 16], ['saveData' => false]) ?>Edit</button>
                        </div>

                        <dl class="pf-view pf-rows" id="pfView"<?= $pfEdit ? ' hidden' : '' ?>>
                            <div><dt>Full name</dt><dd><?= $fullName !== '' ? esc($fullName) : '—' ?></dd></div>
                            <div><dt>Contact number</dt><dd><?= $phone !== '' ? esc($phoneDisplay) : '—' ?></dd></div>
                        </dl>

                        <?php // Name and contact number only (SettingsController::updateAccount); the email is in Sign-in & security. ?>
                        <form method="post" action="<?= site_url('admin/settings/account') ?>" class="pf-form" id="pfForm" data-submit-once data-busy-label="Saving…"<?= $pfEdit ? '' : ' hidden' ?>>
                            <?= csrf_field() ?>
                            <?php if (($m = $err('personal', 'form')) !== null): ?><div class="alert alert-danger" role="alert"><?= esc($m) ?></div><?php endif; ?>
                            <?php
                            // Soft limit, like Add item's Item name: typing past 60 is allowed, but the counter and
                            // field turn red, the message shows and Save is disabled. The server checks the same limit.
                            $pfName     = (string) $typed('full_name', $fullName);
                            $pfNameLen  = mb_strlen($pfName);
                            $pfNameOver = $pfNameLen > 60;
                            $pfNameMsg  = $err('personal', 'full_name') ?? ($pfNameOver ? 'Full name can be up to 60 characters.' : null);
                            ?>
                            <div class="pf-field">
                                <div class="im-label-row">
                                    <label for="pfFullName">Full name</label>
                                    <small class="im-count<?= $pfNameOver ? ' is-over' : ($pfNameLen >= 50 ? ' is-near' : '') ?>" id="pfNameCount"><?= $pfNameLen ?> / 60</small>
                                </div>
                                <div class="pf-control">
                                    <input id="pfFullName" name="full_name" value="<?= esc($pfName, 'attr') ?>" data-original="<?= esc($fullName, 'attr') ?>" autocomplete="name" required aria-describedby="pfNameCount pfFullNameErr"<?= $pfNameMsg !== null ? ' class="is-invalid" aria-invalid="true"' : '' ?>>
                                    <div class="pf-error" id="pfFullNameErr" role="alert"><?= $pfNameMsg !== null ? esc($pfNameMsg) : '' ?></div>
                                </div>
                            </div>
                            <div class="pf-field">
                                <label for="pfPhone">Contact number</label>
                                <div class="pf-control">
                                    <input id="pfPhone" name="phone" value="<?= esc($typed('phone', $phone), 'attr') ?>" data-original="<?= esc($phone, 'attr') ?>" placeholder="e.g. 0917 123 4567" inputmode="tel" autocomplete="tel" maxlength="20"<?= $invalid('personal', 'phone', 'pfPhone') ?>>
                                    <?= $errLine('personal', 'phone', 'pfPhone') ?>
                                </div>
                            </div>
                            <div class="pf-foot">
                                <span class="pf-changes" aria-live="polite"></span>
                                <div class="pf-foot-actions">
                                    <button type="button" class="pf-cancel" id="pfCancelBtn">Cancel</button>
                                    <button type="submit" class="btn btn-success pf-save"<?= demo_lock_attr($admin ?? null) ?>>Save changes</button>
                                </div>
                            </div>
                        </form>
                    </section>

                    <section class="set-card" id="pfSecurity" aria-labelledby="siTitle">
                        <div class="pf-head">
                            <h2 class="pf-title" id="siTitle">Sign-in &amp; security</h2>
                            <button type="button" class="pf-edit" id="siEditBtn" aria-label="Edit sign-in and security"<?= $siEdit ? ' hidden' : '' ?><?= demo_lock_attr($admin ?? null) ?>><?= view('partials/_pen_icon', ['penIconSize' => 16], ['saveData' => false]) ?>Edit</button>
                        </div>
                        <p class="pf-sub">Changing your username, email or password asks for your current password.</p>

                        <dl class="pf-view pf-rows" id="siView"<?= $siEdit ? ' hidden' : '' ?>>
                            <div><dt>Username</dt><dd><?= esc($admin['username'] ?? '—') ?></dd></div>
                            <div><dt>Email</dt><dd><?= ($admin['email'] ?? '') !== '' ? esc($admin['email']) : '—' ?></dd></div>
                            <div><dt>Password</dt><dd><span class="sec-dots" role="img" aria-label="Hidden">••••••••</span></dd></div>
                        </dl>

                        <?php // Username, email and password in one form; the current password is asked for once (SettingsController::updateSignIn). ?>
                        <form method="post" action="<?= site_url('admin/settings/sign-in') ?>" class="pf-form" id="siForm" data-submit-once data-busy-label="Saving…"<?= $siEdit ? '' : ' hidden' ?>>
                            <?= csrf_field() ?>
                            <?php if (($m = $err('signin', 'form')) !== null): ?><div class="alert alert-danger" role="alert"><?= esc($m) ?></div><?php endif; ?>
                            <div class="pf-field">
                                <label for="siUsername">Username</label>
                                <div class="pf-control">
                                    <input id="siUsername" name="username" value="<?= esc($typed('username', $admin['username'] ?? ''), 'attr') ?>" data-original="<?= esc($admin['username'] ?? '', 'attr') ?>" pattern="[A-Za-z0-9_\-]+" minlength="4" maxlength="50" title="4 to 50 letters, numbers, _ or -" required autocomplete="username"<?= $invalid('signin', 'username', 'siUsername', true) ?>>
                                    <div class="pf-hint" id="siUsernameHint">4–50 letters, numbers, - or _</div>
                                    <?= $errLine('signin', 'username', 'siUsername') ?>
                                </div>
                            </div>
                            <div class="pf-field">
                                <label for="siEmail">Email</label>
                                <div class="pf-control">
                                    <input id="siEmail" name="email" type="email" value="<?= esc($siEmail, 'attr') ?>" data-original="<?= esc($admin['email'] ?? '', 'attr') ?>" maxlength="100" required autocomplete="email"<?= $invalid('signin', 'email', 'siEmail') ?>>
                                    <?= $errLine('signin', 'email', 'siEmail') ?>
                                </div>
                            </div>
                            <?php // Shown once the email is edited: the new address is typed twice. ?>
                            <div class="pf-field" id="siEmailConfirmField"<?= $siEmailChanged ? '' : ' hidden' ?>>
                                <label for="siEmailConfirm">Confirm email</label>
                                <div class="pf-control">
                                    <input id="siEmailConfirm" name="email_confirm" type="email" value="<?= esc((string) $typed('email_confirm', ''), 'attr') ?>" maxlength="100" autocomplete="off"<?= $invalid('signin', 'email_confirm', 'siEmailConfirm') ?>>
                                    <?= $errLine('signin', 'email_confirm', 'siEmailConfirm') ?>
                                </div>
                            </div>
                            <div class="pf-field">
                                <label for="siNewPassword">New password</label>
                                <div class="pf-control">
                                    <input id="siNewPassword" name="new_password" type="password" placeholder="<?= esc(\App\Libraries\PasswordPolicy::HINT) ?>" autocomplete="new-password"<?= $invalid('signin', 'new_password', 'siNewPassword', true) ?>>
                                    <div class="pf-hint" id="siNewPasswordHint">Leave blank to keep your current password.</div>
                                    <?= $errLine('signin', 'new_password', 'siNewPassword') ?>
                                </div>
                            </div>
                            <div class="pf-field">
                                <label for="siNewPasswordConfirm">Repeat new password</label>
                                <div class="pf-control">
                                    <input id="siNewPasswordConfirm" name="new_password_confirm" type="password" autocomplete="new-password"<?= $invalid('signin', 'new_password_confirm', 'siNewPasswordConfirm') ?>>
                                    <?= $errLine('signin', 'new_password_confirm', 'siNewPasswordConfirm') ?>
                                </div>
                            </div>
                            <div class="pf-current">
                                <div class="pf-field">
                                    <label for="siCurrentPassword">Current password</label>
                                    <div class="pf-control">
                                        <input id="siCurrentPassword" name="current_password" type="password" required autocomplete="current-password"<?= $invalid('signin', 'current_password', 'siCurrentPassword', true) ?>>
                                        <div class="pf-hint" id="siCurrentPasswordHint">Needed to save any change in this section.</div>
                                        <?= $errLine('signin', 'current_password', 'siCurrentPassword') ?>
                                    </div>
                                </div>
                            </div>
                            <div class="pf-foot">
                                <span class="pf-changes" aria-live="polite"></span>
                                <div class="pf-foot-actions">
                                    <button type="button" class="pf-cancel" id="siCancelBtn">Cancel</button>
                                    <button type="submit" class="btn btn-success pf-save"<?= demo_lock_attr($admin ?? null) ?>>Save changes</button>
                                </div>
                            </div>
                        </form>
                    </section>
                </div>
            </div>

            <script>
            (function () {
                // Each card: a view, a form that opens with Edit, and Cancel that puts the saved values back.
                // Save changes stays disabled until a value differs from the saved one (changing it back
                // disables it again) and while any message shows under a field. Only one card edits at a time.
                const cards = [];

                // The left card keeps its view-mode size while a card on the right is edited: measured
                // just before the form opens and set as an inline height (with align-self: start, CSS
                // .is-locked). Cancel removes it; Save reloads the page. One column (900px and below): no lock.
                const who = document.getElementById('pfWho'), oneColumn = window.matchMedia('(max-width: 900px)');
                const lockWho = () => {
                    if (oneColumn.matches || who.classList.contains('is-locked') || who.classList.contains('is-natural')) { return; }
                    who.style.height = who.getBoundingClientRect().height + 'px';
                    who.classList.add('is-locked');
                };
                const unlockWho = () => {
                    if (cards.some((c) => c.isOpen())) { return; }
                    who.style.height = '';
                    who.classList.remove('is-locked', 'is-natural'); // back in view mode: stretch again
                };
                const visibleErrors = (form) => [...form.querySelectorAll('.pf-error')]
                    .filter((e) => e.textContent.trim() !== '' && !e.closest('[hidden]')).length;

                function card(prefix, firstField, changed, onReset) {
                    const view = document.getElementById(prefix + 'View'), form = document.getElementById(prefix + 'Form');
                    const edit = document.getElementById(prefix + 'EditBtn'), cancel = document.getElementById(prefix + 'CancelBtn');
                    const save = form.querySelector('button[type="submit"]'), note = form.querySelector('.pf-changes');
                    const editLocked = edit.disabled, saveLocked = save.disabled; // the demo owner's buttons stay disabled
                    const self = { form, edit, editLocked, isOpen: () => !form.hidden };

                    self.sync = () => {
                        const n = visibleErrors(form);
                        const text = n > 0 ? `Fix ${n} ${n === 1 ? 'error' : 'errors'} to save` : '';
                        if (note.textContent !== text) { note.textContent = text; }
                        if (!form.hasAttribute('aria-busy')) { save.disabled = saveLocked || n > 0 || !changed(); }
                    };
                    const show = (editing) => {
                        view.hidden = editing;
                        form.hidden = !editing;
                        edit.hidden = editing;
                        lockOthers();
                        if (editing) { document.getElementById(firstField).focus(); } else { edit.focus(); }
                    };
                    edit.addEventListener('click', () => { lockWho(); show(true); });
                    cancel.addEventListener('click', () => {
                        form.querySelectorAll('input[data-original]').forEach((i) => { i.value = i.dataset.original; });
                        form.querySelectorAll('input[type=password]').forEach((i) => { i.value = ''; });
                        form.querySelectorAll('.is-invalid').forEach((i) => { i.classList.remove('is-invalid'); i.removeAttribute('aria-invalid'); });
                        form.querySelectorAll('.pf-error').forEach((e) => { e.textContent = ''; });
                        form.querySelectorAll('.alert').forEach((e) => { e.remove(); });
                        if (onReset) { onReset(); }
                        self.sync();
                        show(false);
                        unlockWho();
                    });
                    // A message from the server goes away once its field is edited (the full name has its own check below).
                    form.addEventListener('input', (e) => {
                        const input = e.target;
                        if (input.id !== 'pfFullName' && input.classList.contains('is-invalid')) {
                            const msg = document.getElementById(input.id + 'Err');
                            if (msg) { msg.textContent = ''; }
                            input.classList.remove('is-invalid');
                            input.removeAttribute('aria-invalid');
                        }
                        self.sync();
                    });
                    cards.push(self);
                    return self;
                }

                // While one card is in edit mode, the other card's Edit button is disabled.
                function lockOthers() {
                    const open = cards.find((c) => c.isOpen());
                    cards.forEach((c) => {
                        if (c.editLocked) { return; }
                        const locked = !!open && open !== c;
                        c.edit.disabled = locked;
                        if (locked) { c.edit.title = 'Save or cancel the other section first'; } else { c.edit.removeAttribute('title'); }
                    });
                }

                // The new email is typed twice; the second box only shows once the email is changed.
                const email = document.getElementById('siEmail'), confirmField = document.getElementById('siEmailConfirmField'), confirm = document.getElementById('siEmailConfirm');
                const syncEmail = () => {
                    const changed = email.value.trim().toLowerCase() !== (email.dataset.original || '').trim().toLowerCase();
                    confirmField.hidden = !changed;
                    if (!changed) { confirm.value = ''; }
                };
                email.addEventListener('input', syncEmail);

                // What counts as a change (the server's "Nothing changed yet." stays as the backup).
                const name = document.getElementById('pfFullName'), phone = document.getElementById('pfPhone');
                const phoneDigits = (v) => { const d = v.replace(/\D+/g, ''); return /^63\d{10}$/.test(d) ? '0' + d.slice(2) : d; };
                const personalChanged = () => name.value.trim() !== name.dataset.original.trim()
                    || phoneDigits(phone.value) !== phoneDigits(phone.dataset.original);
                const username = document.getElementById('siUsername');
                const newPass = document.getElementById('siNewPassword'), newPass2 = document.getElementById('siNewPasswordConfirm');
                // A new password counts as a change; the current password alone does not.
                const signInChanged = () => username.value.trim() !== username.dataset.original
                    || email.value.trim().toLowerCase() !== (email.dataset.original || '').trim().toLowerCase()
                    || newPass.value !== '' || newPass2.value !== '';

                // Full name counter, as in Add item (Item name): "n / 60" counted in characters, amber from 50,
                // red past 60 with the message under the field and Save disabled. Typing is never blocked.
                const NAME_MAX = 60, NAME_OVER_MSG = 'Full name can be up to 60 characters.';
                const nameCount = document.getElementById('pfNameCount'), nameErr = document.getElementById('pfFullNameErr');
                let personal = null;
                const syncName = (edited) => {
                    const len = [...name.value].length, over = len > NAME_MAX;
                    nameCount.textContent = `${len} / ${NAME_MAX}`;
                    nameCount.classList.toggle('is-near', len >= 50 && !over);
                    nameCount.classList.toggle('is-over', over);
                    // A message from the server goes away once the name is edited; the limit shows right away.
                    const msg = over ? NAME_OVER_MSG : (edited ? '' : nameErr.textContent);
                    if (nameErr.textContent !== msg) { nameErr.textContent = msg; }
                    name.classList.toggle('is-invalid', msg !== '');
                    name.setAttribute('aria-invalid', msg !== '' ? 'true' : 'false');
                    if (personal) { personal.sync(); } // Save disabled while over the limit
                };
                name.addEventListener('input', () => syncName(true));

                personal = card('pf', 'pfFullName', personalChanged, () => syncName(true));
                card('si', 'siUsername', signInChanged, syncEmail);
                syncName(false);
                cards.forEach((c) => c.sync());
                lockOthers();
            })();
            </script>
        <?php elseif ($tab === 'accounts'): ?>
            <h4>Manage Accounts</h4>
            <div class="set-hint">Add, remove or reset staff accounts.</div>

            <div class="set-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0">Active Accounts (<?= count($staffAccounts) + 1 ?>)</h5>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addStaffModal"><i class="bi bi-plus-circle"></i> Add Staff Account</button>
                </div>

                <div class="acct-row">
                    <div class="acct-avatar" aria-hidden="true"><?= esc(initials($admin['full_name'] ?? '')) ?></div>
                    <div class="flex-grow-1">
                        <div class="acct-name"><?= esc($admin['full_name'] ?? '') ?></div>
                        <div class="acct-email"><?= esc($admin['email'] ?? '') ?></div>
                    </div>
                    <span class="acct-role-pill">Owner</span>
                    <span class="status-pill acct green">Active</span>
                    <span class="text-muted small">(You)</span>
                </div>

                <?php foreach ($staffAccounts as $s): ?>
                    <div class="acct-row">
                        <div class="acct-avatar" aria-hidden="true"><?= esc(initials($s['full_name'])) ?></div>
                        <div class="flex-grow-1">
                            <div class="acct-name"><?= esc($s['full_name']) ?></div>
                            <div class="acct-email">@<?= esc($s['username']) ?><?= ! empty($s['email']) ? ' &middot; ' . esc($s['email']) : '' ?></div>
                        </div>
                        <span class="acct-role-pill">Staff</span>
                        <?php
                        $pending   = \App\Libraries\StaffPasswordReset::isPending($s);
                        $expired   = \App\Libraries\StaffPasswordReset::isExpired($s);
                        $isInvite  = ($s['temp_password_kind'] ?? '') === 'invite';
                        ?>
                        <span class="status-pill acct <?= $s['is_active'] ? 'green' : 'gray' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span>
                        <?php if ($pending): ?>
                            <span class="status-pill acct <?= $expired ? 'amber' : 'blue' ?>"><?= $isInvite
                                ? ($expired ? 'Invite expired' : 'Waiting for first sign-in')
                                : ($expired ? 'Temporary password expired' : 'Waiting for new password') ?></span>
                            <?php if ($expired && $isInvite && ! empty($s['email'])): ?>
                                <form method="post" action="<?= site_url('admin/settings/staff-accounts/resend/' . $s['user_id']) ?>">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-dark">Resend</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                        <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#resetStaffPwModal"
                                data-action="<?= site_url('admin/settings/staff-accounts/password/' . $s['user_id']) ?>"
                                data-name="<?= esc($s['full_name'], 'attr') ?>"<?= demo_lock_attr($s) ?>>Reset Password</button>
                        <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="modal" data-bs-target="#staffUsernameModal"
                                data-action="<?= site_url('admin/settings/staff-accounts/username/' . $s['user_id']) ?>"
                                data-name="<?= esc($s['full_name'], 'attr') ?>" data-username="<?= esc($s['username'], 'attr') ?>"<?= demo_lock_attr($s) ?>>Edit Username</button>
                        <form method="post" action="<?= site_url('admin/settings/staff-accounts/toggle/' . $s['user_id']) ?>"
                              data-confirm="<?= $s['is_active'] ? "They won't be able to sign in until you reactivate the account." : 'They will be able to sign in again with their password.' ?>"
                              data-confirm-title="<?= ($s['is_active'] ? 'Deactivate ' : 'Reactivate ') . esc($s['full_name'], 'attr') ?>?"
                              data-confirm-ok="<?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?>"
                              data-confirm-variant="<?= $s['is_active'] ? 'danger' : 'success' ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm <?= $s['is_active'] ? 'btn-maroon' : 'btn-success' ?>"<?= demo_lock_attr($s) ?>><?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?></button>
                        </form>
                        <form method="post" action="<?= site_url('admin/settings/staff-accounts/delete/' . $s['user_id']) ?>" data-confirm="They will no longer be able to sign in. Their past sales and records will be kept."
                              data-confirm-title="Remove <?= esc($s['full_name'], 'attr') ?>?"
                              data-confirm-ok="Remove" data-confirm-variant="danger">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger"<?= demo_lock_attr($s) ?>>Remove</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="modal fade" id="resetStaffPwModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="post" id="resetStaffPwForm" action="" data-submit-once data-busy-label="Resetting…">
                            <?= csrf_field() ?>
                            <?= \App\Libraries\OnceToken::field('staff_password_reset') ?>
                            <div class="modal-header">
                                <h5 class="modal-title">Reset Password &mdash; <span id="resetStaffPwName"></span></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p class="text-muted small">You choose the new password and give it to the staff member yourself. They get an email that it was reset (it does not contain the password). When they sign in with it, they must create their own. This also unlocks a locked account.</p>
                                <div class="set-field mb-3"><label>New password</label><input name="new_password" type="password" placeholder="<?= esc(\App\Libraries\PasswordPolicy::HINT) ?>" required autocomplete="new-password"></div>
                                <div class="set-field mb-3"><label>Confirm new password</label><input name="confirm_password" type="password" required autocomplete="new-password"></div>
                                <div class="set-field mb-3"><label>Your password</label><input name="owner_password" type="password" required autocomplete="current-password"></div>
                                <label class="d-flex gap-2 align-items-start"><input type="checkbox" name="informed" value="1" id="resetInformed" style="margin-top:4px;" required> <span>I have informed <strong id="resetStaffPwName2"></strong> about this reset.</span></label>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success">Reset</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <script>
                document.getElementById('resetStaffPwModal').addEventListener('show.bs.modal', function (e) {
                    var btn = e.relatedTarget;
                    document.getElementById('resetStaffPwForm').action = btn.dataset.action;
                    document.getElementById('resetStaffPwName').textContent = btn.dataset.name;
                    document.getElementById('resetStaffPwName2').textContent = btn.dataset.name;
                    this.querySelectorAll('input[type="password"]').forEach(function (i) { i.value = ''; });
                    document.getElementById('resetInformed').checked = false;
                });
            </script>

            <div class="modal fade" id="staffUsernameModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="post" id="staffUsernameForm" action="">
                            <?= csrf_field() ?>
                            <div class="modal-header">
                                <h5 class="modal-title">Edit Username &mdash; <span id="staffUsernameName"></span></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="set-field mb-3"><label for="staffNewUsername">New username</label><input id="staffNewUsername" name="username" pattern="[A-Za-z0-9_\-]+" minlength="4" maxlength="50" title="4 to 50 letters, numbers, _ or -" required></div>
                                <p class="text-muted small mb-0">Staff sign in with their username and password. Tell them their new username before they next sign in.</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success">Save</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <script>
                document.getElementById('staffUsernameModal').addEventListener('show.bs.modal', function (e) {
                    var btn = e.relatedTarget;
                    document.getElementById('staffUsernameForm').action = btn.dataset.action;
                    document.getElementById('staffUsernameName').textContent = btn.dataset.name;
                    document.getElementById('staffNewUsername').value = btn.dataset.username;
                });
            </script>

            <div class="modal fade" id="addStaffModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="post" action="<?= site_url('admin/settings/staff-accounts') ?>" data-submit-once data-busy-label="Creating…">
                            <?= csrf_field() ?>
                            <?= \App\Libraries\OnceToken::field('staff_account') ?>
                            <div class="modal-header">
                                <h5 class="modal-title">Add Staff Account</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="set-field mb-3"><label>Full Name</label><input name="full_name" required></div>
                                <div class="set-field mb-3"><label>Username</label><input name="username" required></div>
                                <div class="set-field mb-2"><label>Email</label><input name="contact_email" type="email" placeholder="Where the temporary password is sent" required></div>
                                <p class="text-muted small mb-0">A temporary password is emailed to them. It works for 48 hours.</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success">Save &amp; create account</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="set-card">
                <h5 class="fw-bold mb-2">Staff Passwords <?= help_tip('Staff passwords', [
                    'Staff sign in with their username and their own password.',
                    'If someone forgets theirs, click Reset Password on their row. A temporary password is emailed to them. You won\'t see it.',
                    'If an account has no email, the temporary password is shown to you once.',
                ]) ?></h5>
                <p class="text-muted small mb-0">Each staff member has their own password.</p>
            </div>

        <?php elseif ($tab === 'notif'): ?>
            <h4>Notification Preferences</h4>
            <div class="set-hint">Choose which alerts you receive.</div>

            <div class="set-card">
                <?php
                $notifPrefs = $notifPrefs ?? [];
                $prefs = [
                    ['rop_alerts', 'Reorder At Alerts', 'Notify when an item reaches its Reorder At number'],
                ];
                ?>
                <?php foreach ($prefs as $pref): [$key, $label, $desc] = $pref; $tip = $pref[3] ?? null; ?>
                    <?php $isOn = !empty($notifPrefs[$key]); ?>
                    <div class="notif-pref-row">
                        <div><strong><?= esc($label) ?><?php if (! empty($tip)): ?> <?= help_tip($label, $tip) ?><?php endif; ?></strong><span class="desc"><?= esc($desc) ?></span></div>
                        <form method="post" action="<?= site_url('admin/settings/notification-preference') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="pref_key" value="<?= esc($key) ?>">
                            <input type="hidden" name="enabled" value="0">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" style="width:2.5em;height:1.4em;"
                                       name="enabled" value="1" <?= $isOn ? 'checked' : '' ?> onchange="this.form.submit()">
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($tab === 'dss'): ?>
            <h4>DSS Parameter <?= \App\Libraries\Glossary::tip('dss') ?></h4>
            <div class="set-hint">Settings used to work out what and when to order.</div>

            <div class="dss-warning">
                <i class="bi bi-exclamation-triangle fs-4"></i>
                <div>Changes apply to the next calculation. <?= help_tip('Before you change these', [
                    'Only the owner can change these settings.',
                    'They change every ordering suggestion in the app.',
                    'Nothing here uses AI. Results come from your past sales and the formulas shown.',
                ]) ?></div>
            </div>

            <?php // Recalculate ROP / EOQ / safety stock / classes now (same engine as `php spark dss:run`), in the background. ?>
            <form method="post" action="<?= site_url('admin/settings/run-engine') ?>" class="dss-run" id="dssRunForm">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-success" id="dssRunBtn"<?= $dssRunning ? ' disabled aria-busy="true"' : '' ?>>
                    <i class="bi bi-arrow-repeat<?= $dssRunning ? ' dss-spin' : '' ?>" aria-hidden="true"></i> <span class="dss-run-label"><?= $dssRunning ? 'Recalculating…' : 'Recalculate now' ?></span>
                </button>
                <span class="dss-last-run">
                    <?php if ($dssCalculated): ?>
                        Last calculated: <strong><?= esc($dssCalculated) ?></strong>
                    <?php else: ?>
                        Not calculated yet
                    <?php endif; ?>
                </span>
            </form>
            <p class="dss-run-status" id="dssRunStatus" role="status"<?= $dssRunning ? '' : ' hidden' ?>>
                <?= $dssStarted
                    ? 'Recalculating in the background. You can leave this page; you&rsquo;ll get a notice when it&rsquo;s done.'
                    : 'A recalculation is already running. You&rsquo;ll get a notice when it&rsquo;s done.' ?>
            </p>
            <p class="dss-run-help">Runs every 7 days. Run it now after changing settings. <?= help_tip('When to recalculate', 'Also run it after you change a supplier\'s delivery time.') ?></p>
            <script>
            (function () {
                const form = document.getElementById('dssRunForm');
                const btn = document.getElementById('dssRunBtn');
                const label = btn.querySelector('.dss-run-label');
                const icon = btn.querySelector('.bi');
                const busy = on => {
                    btn.disabled = on;
                    btn.toggleAttribute('aria-busy', on);
                    icon.classList.toggle('dss-spin', on);
                    label.textContent = on ? 'Recalculating…' : 'Recalculate now';
                };
                // The request only starts the run, so it returns quickly.
                form.addEventListener('submit', () => setTimeout(() => busy(true), 0));
                // Run finished (assets/js/dss-watch.js): enable the button again and refresh "Last calculated".
                window.addEventListener('dss:finished', e => {
                    busy(false);
                    document.getElementById('dssRunStatus').hidden = true;
                    if (e.detail && e.detail.calculated) {
                        const strong = document.createElement('strong');
                        strong.textContent = e.detail.calculated;
                        form.querySelector('.dss-last-run').replaceChildren('Last calculated: ', strong);
                    }
                });
            })();
            </script>

            <form method="post" action="<?= site_url('admin/settings/dss-parameters') ?>">
                <?= csrf_field() ?>
                <div class="dss-cards">
                    <div class="set-card">
                        <h5 class="fw-bold mb-3">Order Qty settings <?= \App\Libraries\Glossary::tip('order_qty') ?></h5>
                        <div class="set-field mb-3">
                            <label>Cost per Order (₱) <?= help_tip('Cost per Order', 'Fixed cost each time you place an order, such as transport and paperwork. Also called ordering cost (S).') ?></label>
                            <input name="ordering_cost" type="number" step="0.01" min="0" value="<?= esc($dss['ordering_cost'] ?? 0) ?>" required>
                            <small class="text-muted">Cost each time you place an order.</small>
                        </div>
                        <div class="set-field mb-3">
                            <label>Storage Cost per Item per Year (₱) <?= help_tip('Storage Cost', 'Cost to keep one item for a year, such as space and insurance. Also called holding cost (H).') ?></label>
                            <input name="holding_cost_per_unit" type="number" step="0.01" min="0" value="<?= esc($dss['holding_cost_per_unit'] ?? 0) ?>" required>
                            <small class="text-muted">Cost to keep one item for a year.</small>
                        </div>
                        <div class="set-field mb-3">
                            <label>System minimum order qty</label>
                            <input name="minimum_order_qty" type="number" min="1" value="<?= esc($dss['minimum_order_qty'] ?? 5) ?>" required>
                            <small class="text-muted">Smallest order the system will suggest. <?= help_tip('System minimum order qty', ['An item with no recent sales would get an Order Qty of 0. This makes sure a reorder is still suggested.', 'A supplier can also have its own minimum for an item (Inventory > Edit item). The Order Qty is never below either one.']) ?></small>
                        </div>
                        <div class="set-field mb-3">
                            <label for="ownerUsualOrderQty">Owner's usual order qty</label>
                            <input id="ownerUsualOrderQty" name="owner_usual_order_qty" type="number" min="1" step="1" value="<?= esc($dss['owner_usual_order_qty'] ?? \App\Models\DssParameterModel::DEFAULT_OWNER_USUAL_ORDER_QTY) ?>">
                            <small class="text-muted">What you usually order at a time. <?= help_tip("Owner's usual order qty", ['Used only by the Procurement Report > Cost comparison, to compare the yearly cost of ordering this much with the Order Qty the system suggests.', 'It does not change any Order Qty.']) ?></small>
                        </div>
                        <p class="small text-muted mb-0">How is this worked out? <?= help_tip('How Order Qty is worked out', [
                            'Order Qty = √(2 × D × S ÷ H)',
                            'D = yearly sales (daily sales × 365)',
                            'S = cost per order',
                            'H = storage cost per item per year',
                        ]) ?></p>
                    </div>
                    <div class="set-card">
                        <h5 class="fw-bold mb-3">Reorder At settings <?= \App\Libraries\Glossary::tip('reorder_at') ?></h5>
                        <div class="set-field mb-3">
                            <label>Default Availability Target (%) <?= \App\Libraries\Glossary::tip('availability') ?></label>
                            <input name="service_level_target" type="number" step="0.01" min="0" max="100" value="<?= esc($dss['service_level_target'] ?? 95) ?>" required>
                            <small class="text-muted">Used when an item has no A/B/C group yet. <?= help_tip('Availability target', [
                                'How often you want the item in stock.',
                                'Each item normally gets 95%, 90% or 85% from its A/B/C group.',
                                'This number is only used when an item has no group yet.',
                            ]) ?></small>
                        </div>
                        <div class="set-field mb-3">
                            <label>Safety Factor (Z-score) <?= help_tip('Safety Factor (Z-score)', 'A number that sets how much Safety Stock to keep. A higher number keeps more.') ?></label>
                            <input name="z_score" type="number" step="0.001" value="<?= esc($dss['z_score'] ?? 1.645) ?>" required>
                            <small class="text-muted">Used when an item has no A/B/C group yet. <?= help_tip('When is it used?', 'When grouping has not run yet, or cannot be reached.') ?></small>
                        </div>
                        <div class="set-field mb-3">
                            <label>Sales History Used (days) <?= help_tip('Sales History Used', 'How many days of past sales are used to work out daily demand. Also called demand lookback.') ?></label>
                            <input name="demand_lookback_days" type="number" min="1" value="<?= esc($dss['demand_lookback_days'] ?? 90) ?>" required>
                            <small class="text-muted">Days of past sales to look at.</small>
                        </div>
                        <p class="small text-muted mb-0">How is this worked out? <?= help_tip('How Reorder At is worked out', [
                            'Reorder At = d × L + Safety Stock',
                            'Safety Stock = Z × σd × √L',
                            'd = daily sales, L = delivery time in days',
                            'σd = how much daily sales go up and down, Z = safety factor',
                        ]) ?></p>
                    </div>
                </div>
                <div class="text-end mt-3"><button type="submit" class="btn btn-success">Save Parameters</button></div>
            </form>

        <?php elseif ($tab === 'records'): ?>
            <div class="rec-tab">
            <h4 class="rec-page-title">Data &amp; Records</h4>
            <p class="rec-page-sub">Old sales records can be saved, then removed.</p>

            <div class="rec-card">
                <form method="post" action="<?= site_url('admin/settings/retention') ?>" class="rec-row" id="retentionForm">
                    <?= csrf_field() ?>
                    <div class="rec-text">
                        <h5 class="rec-title"><label for="retentionYears" class="m-0">Keep sales records for</label></h5>
                        <p class="rec-desc" id="retentionHelp">Older records can be saved, then removed. <?= help_tip('Before you shorten this', 'Check BIR record-keeping rules before keeping records for fewer years.') ?></p>
                    </div>
                    <div class="rec-controls">
                        <select id="retentionYears" name="retention_years" class="rec-select" aria-describedby="retentionHelp" data-saved="<?= $retentionYears ?>">
                            <?php foreach ($retentionOptions as $y): ?>
                                <option value="<?= $y ?>" <?= $y === $retentionYears ? 'selected' : '' ?>>Keep <?= $y ?> years<?= $y === $retentionDefault ? ' (recommended)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="rec-btn" id="retentionSave" disabled>Save</button>
                    </div>
                </form>
                <script>
                (function () {
                    // Save turns on only when the choice differs from the saved one.
                    const sel = document.getElementById('retentionYears');
                    const btn = document.getElementById('retentionSave');
                    const sync = () => { btn.disabled = sel.value === sel.dataset.saved; };
                    sel.addEventListener('change', sync);
                    window.addEventListener('pageshow', () => { sel.value = sel.dataset.saved; sync(); });
                })();
                </script>
            </div>

            <div class="rec-card">
                <h5 class="rec-title">Old sales records</h5>
                <?php if ($oldRecords === 0 || empty($oldRange)): ?>
                    <div class="rec-box is-clear">
                        <span class="rec-box-ic" aria-hidden="true"><i class="bi bi-check-lg"></i></span>
                        <div class="rec-box-text">
                            <p class="rec-box-title">No sales records are older than <?= $retentionYears ?> years</p>
                            <p class="rec-box-sub">All sales history is kept. Nothing to do.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="rec-box is-due">
                        <span class="rec-box-ic" aria-hidden="true"><i class="bi bi-database"></i></span>
                        <div class="rec-box-text">
                            <p class="rec-box-title"><?= number_format($oldRecords) ?> sales records are older than <?= $retentionYears ?> years</p>
                            <p class="rec-box-sub">Old records from <?= esc(date('Y', strtotime($oldRange['first']))) ?> to <?= esc(date('M j, Y', strtotime($oldRange['last']))) ?>.</p>
                        </div>
                        <a href="<?= site_url('admin/archive') ?>" class="rec-btn">Review records</a>
                    </div>
                <?php endif; ?>
            </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?= $this->endSection() ?>
