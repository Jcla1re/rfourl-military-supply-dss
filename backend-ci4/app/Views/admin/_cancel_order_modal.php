<?php
// app/Views/admin/_cancel_order_modal.php
// One shared "Cancel order" dialog for the Orders page and the order detail page.
// A button with data-cancel-order (data-action, data-so, data-supplier, data-items, data-status)
// opens it. The Cancel order button stays off until a reason is chosen (and, for "Other", a note of 5 to 200 characters).
?>
<div class="modal fade confirm-modal" id="cancelOrderModal" tabindex="-1" aria-labelledby="cancelOrderTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="cancelOrderForm" action="">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <div class="text-center">
                        <div class="confirm-icon danger" aria-hidden="true"><i class="bi bi-x-octagon"></i></div>
                        <h5 class="confirm-title" id="cancelOrderTitle">Cancel order?</h5>
                        <p class="confirm-message" id="cancelOrderSummary"></p>
                    </div>

                    <div class="alert alert-warning small text-start" id="cancelOrderWarning" hidden>
                        The supplier has already accepted this order and may have started preparing it.
                    </div>

                    <label for="cancelReason" class="fw-bold small mb-1">Reason</label>
                    <select name="reason" id="cancelReason" class="form-select" required>
                        <option value="">Choose a reason…</option>
                        <?php foreach (\App\Models\StockOrderModel::CANCEL_REASONS as $reason): ?>
                            <option value="<?= esc($reason) ?>"><?= esc($reason) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div id="cancelNoteWrap" class="mt-2" hidden>
                        <label for="cancelNote" class="fw-bold small mb-1">Note (5 to 200 characters)</label>
                        <textarea name="note" id="cancelNote" class="form-control" rows="2" maxlength="200"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep order</button>
                    <button type="submit" class="btn btn-maroon" id="cancelOrderGo" disabled>Cancel order</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var modalEl = document.getElementById('cancelOrderModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    var form = document.getElementById('cancelOrderForm');
    var reason = document.getElementById('cancelReason');
    var noteWrap = document.getElementById('cancelNoteWrap');
    var note = document.getElementById('cancelNote');
    var go = document.getElementById('cancelOrderGo');

    function check() {
        var isOther = reason.value === 'Other';
        noteWrap.hidden = !isOther;
        var len = note.value.trim().length;
        go.disabled = reason.value === '' || (isOther && (len < 5 || len > 200));
    }
    reason.addEventListener('change', check);
    note.addEventListener('input', check);

    document.addEventListener('click', function (event) {
        var btn = event.target.closest('[data-cancel-order]');
        if (!btn) return;
        var items = parseInt(btn.dataset.items || '0', 10);
        form.action = btn.dataset.action;
        document.getElementById('cancelOrderTitle').textContent = 'Cancel order ' + btn.dataset.so + '?';
        document.getElementById('cancelOrderSummary').textContent = (btn.dataset.supplier || 'Supplier') + ' · ' + items + ' item' + (items === 1 ? '' : 's');
        document.getElementById('cancelOrderWarning').hidden = btn.dataset.status !== 'Preparing';
        reason.value = '';
        note.value = '';
        check();
        modal.show();
    });
})();
</script>
