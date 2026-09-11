/**
 * Shared confirm-action modal.
 *
 * Never use window.confirm()/prompt()/alert() in this module - native dialogs
 * block the entire page (and browser automation) until dismissed. This
 * injects its own Bootstrap modal markup on first use, so any script can call
 * it without a page needing to provide the HTML.
 *
 * Usage: edmConfirm('Delete this list?', function () { ...do it... });
 */
(function () {
    'use strict';

    var modal, bodyEl, confirmBtn, pending;

    function ensure() {
        if (modal) { return; }
        var el = document.createElement('div');
        el.className = 'modal fade';
        el.id = 'edm-confirm-modal';
        el.tabIndex = -1;
        el.setAttribute('aria-hidden', 'true');
        el.innerHTML =
            '<div class="modal-dialog modal-sm">' +
                '<div class="modal-content">' +
                    '<div class="modal-body" id="edm-confirm-body"></div>' +
                    '<div class="modal-footer">' +
                        '<button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>' +
                        '<button type="button" class="btn btn-danger btn-sm" id="edm-confirm-btn">Confirm</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(el);
        modal = new bootstrap.Modal(el);
        bodyEl = el.querySelector('#edm-confirm-body');
        confirmBtn = el.querySelector('#edm-confirm-btn');
        confirmBtn.addEventListener('click', function () {
            modal.hide();
            if (pending) {
                var fn = pending;
                pending = null;
                fn();
            }
        });
    }

    window.edmConfirm = function (message, onConfirm) {
        ensure();
        bodyEl.textContent = message;
        pending = onConfirm;
        modal.show();
    };
})();
