<?php

if (! function_exists('help_tip')) {
    /**
     * The small (i) button that opens a help pop-up on click or tap
     * (assets/js/help-tip.js starts the Bootstrap popover once per page).
     *
     *   <?= help_tip('Safety stock', 'Extra units kept for busy days.') ?>
     *   <?= help_tip('A, B and C', ['A: best sellers (80% of sales)', 'B: the next 15%', 'C: the last 5%']) ?>
     *
     * $body: a string (blank lines start a new paragraph) or a list of short bullet points.
     * Everything is escaped. Keep it to a few short lines.
     */
    function help_tip(string $title, string|array $body, string $label = 'More info'): string
    {
        if (is_array($body)) {
            $html = '<ul class="help-list">';
            foreach ($body as $point) {
                $html .= '<li>' . esc($point) . '</li>';
            }
            $html .= '</ul>';
        } else {
            $html = '';
            foreach (preg_split('/\n\s*\n/', trim($body)) as $paragraph) {
                $html .= '<p>' . esc(trim($paragraph)) . '</p>';
            }
        }

        return '<button type="button" class="info-tip help-tip" aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '" aria-expanded="false"'
            . ' data-help-title="' . htmlspecialchars($title, ENT_QUOTES) . '" data-help-body="' . htmlspecialchars($html, ENT_QUOTES) . '">'
            . '<i class="bi bi-info-circle" aria-hidden="true"></i></button>';
    }
}
