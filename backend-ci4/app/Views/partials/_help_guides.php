<?php
// Help page body: one collapsible card per task, each with a short numbered list.
// Expects $guides (see App\Libraries\HelpGuides) and optional $intro.
$guides = $guides ?? [];
?>
<div class="page-wrap">
    <div class="help-page">
        <?php if (! empty($intro)): ?><p class="help-intro"><?= esc($intro) ?></p><?php endif; ?>

        <div class="accordion help-accordion" id="helpGuides">
            <?php foreach ($guides as $i => $guide): ?>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                data-bs-target="#guide<?= $i ?>" aria-expanded="false" aria-controls="guide<?= $i ?>">
                            <?= esc($guide['title']) ?>
                        </button>
                    </h2>
                    <div id="guide<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#helpGuides">
                        <div class="accordion-body">
                            <ol class="help-steps">
                                <?php foreach ($guide['steps'] as $step): ?>
                                    <li><?= esc($step) ?></li>
                                <?php endforeach; ?>
                            </ol>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
