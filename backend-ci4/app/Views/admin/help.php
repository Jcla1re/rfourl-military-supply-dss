<?= $this->extend('layouts/admin_layout') ?>
<?= $this->section('content') ?>

<?= view('partials/_help_guides', ['guides' => $guides ?? [], 'intro' => 'Click a task to see the steps.']) ?>

<?= $this->endSection() ?>
