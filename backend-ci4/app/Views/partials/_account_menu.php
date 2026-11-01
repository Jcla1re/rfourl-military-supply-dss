<?php
// app/Views/partials/_account_menu.php
// Collapsed sidebar only (Owner, Staff, Supplier layouts): the avatar becomes an "Account menu"
// button that opens a small card (name + role, My Profile, Log out) to the right of the sidebar.
// The expanded sidebar keeps its own name / role link and Log out button.
// Behaviour: assets/js/account-menu.js. Styles: admin-theme.css ("Account menu").
//
// Expects: $profileUrl, $name, $role.
?>
<button type="button" class="account-menu-btn" id="accountMenuBtn" aria-label="Account menu" aria-haspopup="menu" aria-expanded="false" aria-controls="accountMenu">
    <i class="bi bi-person-circle" aria-hidden="true"></i>
</button>
<div class="account-menu" id="accountMenu" role="menu" aria-labelledby="accountMenuName" hidden>
    <div class="account-menu-head">
        <div class="account-menu-name" id="accountMenuName"><?= esc($name) ?></div>
        <div class="account-menu-role"><?= esc($role) ?></div>
    </div>
    <a href="<?= esc($profileUrl, 'attr') ?>" class="account-menu-item" role="menuitem" tabindex="-1">
        <i class="bi bi-person" aria-hidden="true"></i> My Profile
    </a>
    <form method="post" action="<?= site_url('logout') ?>" class="m-0">
        <?= csrf_field() ?>
        <button type="submit" class="account-menu-item is-logout" role="menuitem" tabindex="-1">
            <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Log out
        </button>
    </form>
</div>
