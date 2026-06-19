/**
 * Auto-close POS modals after 3 minutes of inactivity inside the modal.
 */
(function () {
    'use strict';

    var IDLE_MS = 180000;
    var MODAL_IDS = [
        'erpProductOptionsModal',
        'posOpenTableModal',
        'commonModal',
        'posVariantPickerModal',
    ];
    var timers = {};

    function isVisible(el) {
        return !!(el && (el.classList.contains('show') || el.style.display === 'block'));
    }

    function hideModal(el) {
        if (!el) {
            return;
        }
        if (typeof $ !== 'undefined' && $.fn.modal) {
            $(el).modal('hide');
            return;
        }
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            var instance = bootstrap.Modal.getInstance(el);
            if (instance) {
                instance.hide();
            }
        }
    }

    function clearTimer(id) {
        if (timers[id]) {
            clearTimeout(timers[id]);
            delete timers[id];
        }
    }

    function resetTimer(id, el) {
        clearTimer(id);
        timers[id] = setTimeout(function () {
            if (isVisible(el)) {
                hideModal(el);
            }
            clearTimer(id);
        }, IDLE_MS);
    }

    function onModalActivity(id, el) {
        if (isVisible(el)) {
            resetTimer(id, el);
        }
    }

    function bindModal(el) {
        var id = el.id;
        if (!id) {
            return;
        }

        function onShown() {
            resetTimer(id, el);
        }

        function onHidden() {
            clearTimer(id);
        }

        el.addEventListener('shown.bs.modal', onShown);
        el.addEventListener('hidden.bs.modal', onHidden);

        if (typeof $ !== 'undefined') {
            $(el).on('shown.bs.modal', onShown);
            $(el).on('hidden.bs.modal', onHidden);
        }

        ['click', 'keydown', 'input', 'change'].forEach(function (eventName) {
            el.addEventListener(
                eventName,
                function () {
                    onModalActivity(id, el);
                },
                true
            );
        });

        el.addEventListener(
            'touchstart',
            function () {
                onModalActivity(id, el);
            },
            { passive: true, capture: true }
        );
    }

    function init() {
        MODAL_IDS.forEach(function (modalId) {
            var el = document.getElementById(modalId);
            if (el) {
                bindModal(el);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
