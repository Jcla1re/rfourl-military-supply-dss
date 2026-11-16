<?php
// New + confirm password fields with the eye toggle, and the rule checks that tick green while typing
// (auth-forms.js). Expects $serverRule: the text of the last rule, which only the server can check.
?>
                    <div class="field-group">
                        <label for="newPassword">New password</label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-lock" aria-hidden="true"></i>
                            <input type="password" id="newPassword" name="new_password" placeholder="Enter a new password" minlength="<?= \App\Libraries\PasswordPolicy::MIN_LENGTH ?>" autocomplete="new-password" required autofocus data-pw-rules-for="pwRules">
                            <button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false" tabindex="-1"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="field-group">
                        <label for="confirmPassword">Confirm new password</label>
                        <div class="input-icon-wrap">
                            <i class="bi bi-lock" aria-hidden="true"></i>
                            <input type="password" id="confirmPassword" name="confirm_password" placeholder="Type it again" minlength="<?= \App\Libraries\PasswordPolicy::MIN_LENGTH ?>" autocomplete="new-password" required>
                            <button type="button" class="pw-toggle" aria-label="Show password" aria-pressed="false" tabindex="-1"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <ul class="pw-rules" id="pwRules" aria-live="polite">
                        <li data-rule="length"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> At least <?= \App\Libraries\PasswordPolicy::MIN_LENGTH ?> characters</li>
                        <li data-rule="letter-number"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Includes a letter and a number</li>
                        <li class="is-server"><i class="bi bi-info-circle" aria-hidden="true"></i> <?= esc($serverRule) ?></li>
                    </ul>
