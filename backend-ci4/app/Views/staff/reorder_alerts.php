
<?= $this->extend('layouts/staff_layout') ?>

<?= $this->section('content') ?>

<?php // The Owner's board, partials/_reorder_board.php, with role 'staff': Notify Owner is the only action. ?>
<?= $this->include('partials/_reorder_board') ?>

<?= $this->endSection() ?>
