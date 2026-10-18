<?php
// Shared confirmation dialog. Included once per layout and driven by
// public/assets/js/confirm-modal.js: put data-confirm="message" on a <form>
// or <a> (optional data-confirm-title, data-confirm-ok, data-confirm-variant
// = danger | success | primary). The text is filled in with textContent.
?>
<div class="modal fade confirm-modal" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center">
                <div class="confirm-icon" id="confirmModalIcon" aria-hidden="true"><i class="bi bi-question-circle"></i></div>
                <h5 class="confirm-title" id="confirmModalTitle">Are you sure?</h5>
                <p class="confirm-message" id="confirmModalMessage"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="confirmModalCancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn" id="confirmModalOk">Confirm</button>
            </div>
        </div>
    </div>
</div>
