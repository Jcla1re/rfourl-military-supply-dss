<?php
// app/Views/auth/landing.php
$attemptedRole = session()->getFlashdata('attempted_role');
$errorMsg      = session()->getFlashdata('error');
$successMsg    = session()->getFlashdata('success');
$lockout       = new \App\Libraries\LoginLockout();
$lockedSeconds = [
    'admin'    => $lockout->secondsRemaining('admin'),
    'staff'    => $lockout->secondsRemaining('staff'),
    'supplier' => $lockout->secondsRemaining('supplier'),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RfourL Military Supply</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="/assets/css/auth-theme.css" rel="stylesheet">
</head>
<body class="landing-body">
    <div class="landing-shell" id="landingShell">
        <div class="dark-panel" id="darkPanel">
            <!-- Default brand view -->
            <div class="dark-view is-active" id="darkBrandView">
                <div class="landing-logo-box">
                    <img src="/assets/img/RfourL_Logo.jpg" alt="RfourL Military Supply">
                </div>

                <div class="landing-brand">
                    <h1>RfourL<br>Military Supply</h1>
                    <p class="landing-tagline">Inventory, sales, and reorder decisions in one place.</p>
                </div>
            </div>

            <!-- "Signing in as <role>" view, shown once a role is picked -->
            <div class="dark-view" id="darkRoleView">
                <div class="landing-logo-box landing-logo-box-sm">
                    <img src="/assets/img/RfourL_Logo.jpg" alt="RfourL Military Supply">
                </div>

                <div class="signing-in-label">SIGNING IN AS</div>
                <h1 class="signing-in-role" id="signingInRoleName">Admin</h1>
                <p class="signing-in-desc" id="signingInRoleDesc"></p>

                <div class="dark-divider"></div>

                <div class="switch-role-label">Not you? Switch role</div>
                <div class="switch-role-buttons" id="switchRoleButtons"></div>
            </div>

            <div class="landing-footer-line">
                &copy; 2026 RfourL Military Supply &middot; 7C 2nd Ave, Brgy. Bagong Lipunan ng Crame, Quezon City
            </div>
        </div>

        <div class="content-panel" id="contentPanel">
            <!-- Role selection -->
            <div class="panel-content is-active" id="roleSelectView">
                <h2>Sign in</h2>
                <p class="landing-subtitle">Choose your role to continue.</p>

                <div class="role-cards">
                    <button type="button" class="role-card" data-role="admin">
                        <span class="role-icon"><i class="bi bi-shield-check"></i></span>
                        <span class="role-text">
                            <strong>Admin</strong>
                            <span>Manage inventory, reorders, reports, and settings.</span>
                        </span>
                        <i class="bi bi-chevron-right role-arrow"></i>
                    </button>

                    <button type="button" class="role-card" data-role="staff">
                        <span class="role-icon"><i class="bi bi-receipt"></i></span>
                        <span class="role-text">
                            <strong>Staff</strong>
                            <span>Process sales and print receipts at the point of sale.</span>
                        </span>
                        <i class="bi bi-chevron-right role-arrow"></i>
                    </button>

                    <button type="button" class="role-card" data-role="supplier">
                        <span class="role-icon"><i class="bi bi-truck"></i></span>
                        <span class="role-text">
                            <strong>Supplier</strong>
                            <span>View purchase orders and update deliveries.</span>
                        </span>
                        <i class="bi bi-chevron-right role-arrow"></i>
                    </button>
                </div>
            </div>

            <!-- Admin login -->
            <div class="panel-content login-form-view" id="loginView-admin" data-role="admin">
                <button type="button" class="back-to-roles"><i class="bi bi-chevron-left"></i> Change role</button>
                <div class="portal-badge"><i class="bi bi-shield-check"></i> ADMIN PORTAL</div>
                <h2>Welcome back</h2>
                <p class="form-subtitle">Sign in with your admin account.</p>
                <form action="/login/admin" method="post">
                    <?= csrf_field() ?>
                    <div class="field-group">
                        <label>Username</label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-person"></i>
                            <input type="text" name="username" placeholder="Enter your username" required>
                        </div>
                    </div>
                    <div class="field-group">
                        <label>Password</label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-lock"></i>
                            <input type="password" name="password" placeholder="Enter your password" required>
                            <button type="button" class="pw-toggle" aria-label="Show password"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="form-row-between">
                        <a href="<?= site_url('login/admin/forgot') ?>" class="forgot-link-inline">Forgot password?</a>
                    </div>
                    <button type="submit" class="btn-login" id="loginSubmitBtn-admin"<?= $lockedSeconds['admin'] > 0 ? ' disabled' : '' ?>>
                        <span>Sign in</span> <i class="bi bi-arrow-right"></i>
                    </button>
                </form>
                <?php if ($attemptedRole === 'admin' && $errorMsg): ?>
                    <div class="auth-error"><?= esc((string) $errorMsg) ?></div>
                <?php endif; ?>
                <?php if ($attemptedRole === 'admin' && $successMsg): ?>
                    <div class="auth-success"><?= esc((string) $successMsg) ?></div>
                <?php endif; ?>
            </div>

            <!-- Staff login -->
            <div class="panel-content login-form-view" id="loginView-staff" data-role="staff">
                <button type="button" class="back-to-roles"><i class="bi bi-chevron-left"></i> Change role</button>
                <div class="portal-badge"><i class="bi bi-receipt"></i> STAFF PORTAL</div>
                <h2>Welcome back</h2>
                <p class="form-subtitle">Sign in with your staff account.</p>
                <form action="/login/staff" method="post">
                    <?= csrf_field() ?>
                    <div class="field-group">
                        <label>Password</label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-lock"></i>
                            <input type="password" name="password" placeholder="Enter your password" required>
                            <button type="button" class="pw-toggle" aria-label="Show password"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="form-row-between">
                        <a href="<?= site_url('login/staff/forgot') ?>" class="forgot-link-inline">Forgot password?</a>
                    </div>
                    <button type="submit" class="btn-login" id="loginSubmitBtn-staff"<?= $lockedSeconds['staff'] > 0 ? ' disabled' : '' ?>>
                        <span>Sign in</span> <i class="bi bi-arrow-right"></i>
                    </button>
                </form>
                <?php if ($attemptedRole === 'staff' && $errorMsg): ?>
                    <div class="auth-error"><?= esc((string) $errorMsg) ?></div>
                <?php endif; ?>
            </div>

            <!-- Supplier login -->
            <div class="panel-content login-form-view" id="loginView-supplier" data-role="supplier">
                <button type="button" class="back-to-roles"><i class="bi bi-chevron-left"></i> Change role</button>
                <div class="portal-badge"><i class="bi bi-truck"></i> SUPPLIER PORTAL</div>
                <h2>Welcome back</h2>
                <p class="form-subtitle">Sign in with your supplier account.</p>
                <form action="/login/supplier" method="post">
                    <?= csrf_field() ?>
                    <div class="field-group">
                        <label>Username</label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-person"></i>
                            <input type="text" name="username" placeholder="Enter your username" required>
                        </div>
                    </div>
                    <div class="field-group">
                        <label>Password</label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-lock"></i>
                            <input type="password" name="password" placeholder="Enter your password" required>
                            <button type="button" class="pw-toggle" aria-label="Show password"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="form-row-between">
                        <a href="<?= site_url('login/supplier/forgot') ?>" class="forgot-link-inline">Forgot password?</a>
                    </div>
                    <button type="submit" class="btn-login" id="loginSubmitBtn-supplier"<?= $lockedSeconds['supplier'] > 0 ? ' disabled' : '' ?>>
                        <span>Sign in</span> <i class="bi bi-arrow-right"></i>
                    </button>
                </form>
                <?php if ($attemptedRole === 'supplier' && $errorMsg): ?>
                    <div class="auth-error"><?= esc((string) $errorMsg) ?></div>
                <?php endif; ?>
                <?php if ($attemptedRole === 'supplier' && $successMsg): ?>
                    <div class="auth-success"><?= esc((string) $successMsg) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        const ROLES = {
            admin:    { label: 'Admin',    description: 'Manage inventory, reorders, reports, and settings.' },
            staff:    { label: 'Staff',    description: 'Process sales and print receipts at the point of sale.' },
            supplier: { label: 'Supplier', description: 'View purchase orders and update deliveries.' },
        };
        const ROLE_ORDER = ['admin', 'staff', 'supplier'];

        const darkPanel = document.getElementById('darkPanel');
        const contentPanel = document.getElementById('contentPanel');
        const darkBrandView = document.getElementById('darkBrandView');
        const darkRoleView = document.getElementById('darkRoleView');
        const signingInRoleName = document.getElementById('signingInRoleName');
        const signingInRoleDesc = document.getElementById('signingInRoleDesc');
        const switchRoleButtons = document.getElementById('switchRoleButtons');
        const roleSelectView = document.getElementById('roleSelectView');
        const loginViews = [...document.querySelectorAll('.login-form-view')];
        const allFadeViews = [darkBrandView, darkRoleView, roleSelectView, ...loginViews];

        function renderSwitchButtons(currentRole) {
            switchRoleButtons.innerHTML = '';
            ROLE_ORDER.filter(r => r !== currentRole).forEach(r => {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'switch-role-btn';
                btn.innerHTML = `<span>Sign in as ${ROLES[r].label}</span><i class="bi bi-arrow-right"></i>`;
                btn.addEventListener('click', () => showRole(r, true));
                switchRoleButtons.appendChild(btn);
            });
        }

        function showRole(role, animate) {
            if (!animate) {
                darkPanel.classList.add('no-transition');
                contentPanel.classList.add('no-transition');
                allFadeViews.forEach(v => v.classList.add('no-transition'));
            }
            darkPanel.classList.add('slid');
            contentPanel.classList.add('slid');
            darkBrandView.classList.remove('is-active');
            darkRoleView.classList.add('is-active');
            signingInRoleName.textContent = ROLES[role].label;
            signingInRoleDesc.textContent = ROLES[role].description;
            renderSwitchButtons(role);
            roleSelectView.classList.remove('is-active');
            loginViews.forEach(v => { v.classList.toggle('is-active', v.dataset.role === role); });
            if (!animate) {
                void darkPanel.offsetWidth; // force reflow before re-enabling transitions
                darkPanel.classList.remove('no-transition');
                contentPanel.classList.remove('no-transition');
                allFadeViews.forEach(v => v.classList.remove('no-transition'));
            }
        }

        function showRoleSelect() {
            darkPanel.classList.remove('slid');
            contentPanel.classList.remove('slid');
            darkBrandView.classList.add('is-active');
            darkRoleView.classList.remove('is-active');
            loginViews.forEach(v => { v.classList.remove('is-active'); });
            roleSelectView.classList.add('is-active');
        }

        document.querySelectorAll('.role-card').forEach(card => {
            card.addEventListener('click', () => showRole(card.dataset.role, true));
        });

        document.querySelectorAll('.back-to-roles').forEach(btn => {
            btn.addEventListener('click', showRoleSelect);
        });

        document.querySelectorAll('.pw-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = btn.previousElementSibling;
                const icon = btn.querySelector('i');
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.className = 'bi bi-eye-slash';
                } else {
                    input.type = 'password';
                    icon.className = 'bi bi-eye';
                }
            });
        });

        <?php foreach (['admin', 'staff', 'supplier'] as $r): ?>
        <?php if ($lockedSeconds[$r] > 0): ?>
        (function () {
            let seconds = <?= (int) $lockedSeconds[$r] ?>;
            const btn = document.getElementById('loginSubmitBtn-<?= $r ?>');
            const label = btn.innerHTML;
            (function tick() {
                if (seconds <= 0) {
                    btn.disabled = false;
                    btn.innerHTML = label;
                    return;
                }
                btn.textContent = `Try again in ${seconds}s`;
                seconds--;
                setTimeout(tick, 1000);
            })();
        })();
        <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($attemptedRole && in_array($attemptedRole, ['admin', 'staff', 'supplier'], true)): ?>
        showRole(<?= json_encode($attemptedRole) ?>, false);
        <?php endif; ?>
    </script>
</body>
</html>
