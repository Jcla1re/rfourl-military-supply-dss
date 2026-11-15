<?php /** The supplier box. Expects $supplier (suppliers row, may be empty). */ ?>
<div class="box" style="margin-bottom: 12px;">
    <div class="lbl">Supplier</div>
    <strong><?= esc($supplier['company_name'] ?? '—') ?></strong><br>
    <?php if (! empty($supplier['contact_person'])): ?>Attn: <?= esc($supplier['contact_person']) ?><br><?php endif; ?>
    <?= esc(implode(' · ', array_filter([$supplier['contact_email'] ?? '', $supplier['contact_number'] ?? '']))) ?>
</div>
