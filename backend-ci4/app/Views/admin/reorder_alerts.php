
<?= $this->extend('layouts/admin_layout') ?>

<?= $this->section('content') ?>

<?php // The board, shared with Staff's page: partials/_reorder_board.php (role 'admin': Order, Dismiss, Restore, View order). ?>
<?= $this->include('partials/_reorder_board') ?>

<?php // "Order {qty}" and "Order all sizes" open the shared purchase order dialog (also used by Inventory). ?>
<?= view('partials/_purchase_order_dialog', ['suppliers' => $suppliers]) ?>

<?= $this->endSection() ?>
