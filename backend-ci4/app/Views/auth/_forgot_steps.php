<?php
// "← Back to sign in" and the 3-step progress bar at the top of the Forgot password pages. Expects $step (1-3).
$step  = (int) ($step ?? 1);
$names = [1 => 'Account', 2 => 'Check email', 3 => 'New password'];
?>
                <a href="<?= site_url('login') ?>" class="split-back"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to sign in</a>
                <ol class="split-steps" aria-label="Step <?= $step ?> of 3">
                    <?php foreach ($names as $n => $name): ?>
                        <li class="<?= $n < $step ? 'is-done' : ($n === $step ? 'is-current' : '') ?>"<?= $n === $step ? ' aria-current="step"' : '' ?>><span class="split-step-bar"></span><span class="split-step-name"><?= esc($name) ?></span></li>
                    <?php endforeach; ?>
                </ol>
