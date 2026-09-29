/**
 * Shared dialogs: confirm, message and prompt, in one design (coloured top
 * bar, tinted icon circle, title + message, grey footer with a text Cancel
 * and a tinted action button). Variants: danger (red), warning (amber),
 * success (green), info (blue).
 *
 * Never use window.confirm()/prompt()/alert() in this module - native dialogs
 * block the entire page (and browser automation) until dismissed. This
 * injects its own Bootstrap modal markup on first use, so any script can call
 * it without a page needing to provide the HTML.
 *
 *   edmConfirm('Delete this list?', function () { ... });
 *   edmConfirm('Submit for review?', fn, { title: 'Submit campaign', variant: 'info', confirmLabel: 'Submit' });
 *   edmAlert('Saved.', { variant: 'success', title: 'Done' });
 *   edmPrompt('Why is it rejected?', function (text) { ... },
 *             { title: 'Reject review', variant: 'danger', label: 'Comment', required: true });
 *
 * edmConfirm without options guesses from the message: Delete / Remove /
 * Stop / Reject / Deactivate -> danger with that word on the button;
 * anything else -> info with Confirm.
 */
(function () {
    'use strict';

    var ICONS = {
        danger:  'bi-exclamation-circle',
        warning: 'bi-exclamation-triangle',
        success: 'bi-check-circle',
        info:    'bi-info-circle'
    };
    var DANGER_WORDS = /^(delete|remove|stop|reject|deactivate|discard|cancel)\b/i;

    var el, modal, titleEl, msgEl, iconEl, fieldWrap, fieldLabel, fieldInput, fieldError, cancelBtn, okBtn;
    var pending = null;
    var busy = false;    // shown, or still fading in / out
    var queued = null;   // a dialog asked for while another was closing

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function ensure() {
        if (modal) { return; }
        el = document.createElement('div');
        el.className = 'modal fade';
        el.id = 'edm-dialog';
        el.tabIndex = -1;
        el.setAttribute('aria-hidden', 'true');
        el.setAttribute('aria-labelledby', 'edm-dialog-title');
        el.innerHTML =
            '<div class="modal-dialog modal-dialog-centered edm-dlg-dialog">' +
                '<div class="modal-content edm-dlg">' +
                    '<div class="edm-dlg-body">' +
                        '<span class="edm-dlg-icon" aria-hidden="true"><i class="bi"></i></span>' +
                        '<div class="edm-dlg-text">' +
                            '<h2 class="edm-dlg-title" id="edm-dialog-title"></h2>' +
                            '<div class="edm-dlg-msg"></div>' +
                            '<div class="edm-dlg-field" hidden>' +
                                '<label class="form-label" for="edm-dialog-input"></label>' +
                                '<textarea class="form-control" id="edm-dialog-input" rows="3"></textarea>' +
                                '<div class="edm-form-error"></div>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="edm-dlg-foot">' +
                        '<button type="button" class="btn edm-dlg-cancel" data-bs-dismiss="modal">Cancel</button>' +
                        '<button type="button" class="btn edm-dlg-ok">OK</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(el);
        modal = new bootstrap.Modal(el);
        titleEl = el.querySelector('.edm-dlg-title');
        msgEl = el.querySelector('.edm-dlg-msg');
        iconEl = el.querySelector('.edm-dlg-icon i');
        fieldWrap = el.querySelector('.edm-dlg-field');
        fieldLabel = fieldWrap.querySelector('label');
        fieldInput = fieldWrap.querySelector('textarea');
        fieldError = fieldWrap.querySelector('.edm-form-error');
        cancelBtn = el.querySelector('.edm-dlg-cancel');
        okBtn = el.querySelector('.edm-dlg-ok');

        okBtn.addEventListener('click', function () {
            if (!pending) { modal.hide(); return; }
            var value = null;
            if (pending.prompt) {
                value = fieldInput.value.trim();
                if (pending.required && !value) {
                    fieldError.textContent = (pending.label || 'This field') + ' is required.';
                    fieldInput.focus();
                    return;
                }
            }
            var fn = pending.fn;
            pending = null;
            modal.hide();
            if (fn) { fn(value); }
        });
        el.addEventListener('shown.bs.modal', function () {
            (fieldWrap.hidden ? okBtn : fieldInput).focus();
        });
        el.addEventListener('hidden.bs.modal', function () {
            busy = false;
            var p = pending;
            pending = null;
            if (p && p.onCancel) { p.onCancel(); }
            // Bootstrap ignores show() mid-transition, so a dialog opened
            // while this one was closing (e.g. "Approved" after a decision)
            // is shown now.
            if (queued) {
                var next = queued;
                queued = null;
                open(next);
            }
        });
    }

    function open(o) {
        ensure();
        if (busy) {
            queued = o;
            if (el.classList.contains('show') && !pending) { modal.hide(); }
            return;
        }
        busy = true;
        var variant = ICONS[o.variant] ? o.variant : 'info';
        el.querySelector('.edm-dlg').className = 'modal-content edm-dlg edm-dlg-' + variant;
        iconEl.className = 'bi ' + ICONS[variant];
        titleEl.textContent = o.title;
        msgEl.innerHTML = o.html ? o.message : esc(o.message);
        msgEl.hidden = !o.message;
        fieldWrap.hidden = !o.prompt;
        fieldError.textContent = '';
        if (o.prompt) {
            fieldLabel.innerHTML = esc(o.label || 'Comment') + (o.required ? ' <span class="edm-req">*</span>' : '');
            fieldInput.value = o.value || '';
            fieldInput.placeholder = o.placeholder || '';
        }
        cancelBtn.hidden = !!o.alert;
        cancelBtn.textContent = o.cancelLabel || 'Cancel';
        okBtn.textContent = o.okLabel;
        pending = { fn: o.fn, onCancel: o.onCancel, prompt: !!o.prompt, required: !!o.required, label: o.label };
        modal.show();
    }

    function guess(message) {
        var m = DANGER_WORDS.exec(String(message || '').trim());
        return m
            ? { variant: 'danger', title: 'Are you sure?', okLabel: m[1].charAt(0).toUpperCase() + m[1].slice(1).toLowerCase() }
            : { variant: 'info', title: 'Please confirm', okLabel: 'Confirm' };
    }

    window.edmConfirm = function (message, onConfirm, opts) {
        opts = opts || {};
        var g = guess(message);
        open({
            variant: opts.variant || g.variant,
            title: opts.title || g.title,
            message: message,
            html: !!opts.html,
            okLabel: opts.confirmLabel || g.okLabel,
            cancelLabel: opts.cancelLabel,
            fn: function () { if (onConfirm) { onConfirm(); } },
            onCancel: opts.onCancel
        });
    };

    window.edmAlert = function (message, opts) {
        opts = opts || {};
        var variant = opts.variant || 'info';
        open({
            alert: true,
            variant: variant,
            title: opts.title || { success: 'Done', warning: 'Warning', danger: 'Something went wrong', info: 'Notice' }[variant],
            message: message,
            html: !!opts.html,
            okLabel: opts.buttonLabel || 'OK',
            fn: opts.onClose,
            onCancel: opts.onClose
        });
    };

    window.edmPrompt = function (message, onConfirm, opts) {
        opts = opts || {};
        open({
            prompt: true,
            variant: opts.variant || 'info',
            title: opts.title || 'Please confirm',
            message: message,
            label: opts.label,
            placeholder: opts.placeholder,
            value: opts.value,
            required: !!opts.required,
            okLabel: opts.confirmLabel || 'Confirm',
            cancelLabel: opts.cancelLabel,
            fn: onConfirm,
            onCancel: opts.onCancel
        });
    };
})();
