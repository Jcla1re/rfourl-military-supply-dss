
<?= $this->extend('layouts/staff_layout') ?>

<?= $this->section('content') ?>

<div class="page-wrap">
    <?= view('partials/_notifications_list', [
        'base'        => 'staff',
        'groups'      => $groups ?? [],
        'filter'      => $filter ?? 'all',
        'allCount'    => $allCount ?? 0,
        'unreadCount' => $unreadCount ?? 0,
    ]) ?>
</div>

<?= $this->endSection() ?>
