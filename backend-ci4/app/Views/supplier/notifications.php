
<?= $this->extend('layouts/supplier_layout') ?>

<?= $this->section('content') ?>

<div class="page-wrap">
    <?= view('partials/_notifications_list', [
        'base'        => 'supplier',
        'groups'      => $groups ?? [],
        'filter'      => $filter ?? 'all',
        'allCount'    => $allCount ?? 0,
        'unreadCount' => $unreadCount ?? 0,
    ]) ?>
</div>

<?= $this->endSection() ?>
