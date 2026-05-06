/**
 * qa-status-master.js
 * Extracted from modules/admin/qa_status_master.php inline script
 */
function deleteQaStatus(id, label, usageCount) {
    if (usageCount > 0) {
        alert('Cannot delete this QA status: It is currently in use by ' + usageCount + ' record(s).');
        return;
    }

    var textEl = document.getElementById('deleteQaStatusConfirmText');
    var idEl = document.getElementById('deleteQaStatusId');
    var btn = document.getElementById('confirmDeleteQaStatusBtn');
    var modalEl = document.getElementById('deleteQaStatusConfirmModal');

    if (textEl) {
        textEl.textContent = 'Are you sure you want to delete QA status "' + label + '"? This action cannot be undone.';
    }
    if (idEl) idEl.value = id;
    if (btn) {
        btn.onclick = function () {
            document.getElementById('deleteQaStatusForm').submit();
        };
    }

    var modal = new bootstrap.Modal(modalEl);
    modal.show();
}
