/**
 * Product options picker modal (parent price + selected option prices).
 * Vanilla JS — expects #erpProductOptionsModal in DOM and window.ErpProductOptionsI18n.
 */
(function () {
    'use strict';

    if (window.ErpProductOptions) {
        return;
    }

    var i18n = window.ErpProductOptionsI18n || {};
    var ctx = { item: null, forPurchase: false, onDone: null };

    function t(key, fallback) {
        return i18n[key] != null && i18n[key] !== '' ? i18n[key] : fallback;
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function optionUnitPrice(opt, forPurchase) {
        return forPurchase
            ? (parseFloat(opt.purchase_price) || 0)
            : (parseFloat(opt.sale_price) || 0);
    }

    function parentBasePrice(item, forPurchase) {
        if (!item || !item.product) {
            return 0;
        }
        return forPurchase
            ? (parseFloat(item.product.purchase_price) || 0)
            : (parseFloat(item.product.sale_price) || 0);
    }

    function buildSelected(modalEl) {
        var selected = [];
        if (!modalEl) {
            return selected;
        }
        modalEl.querySelectorAll('.erp-opt-check:checked').forEach(function (checkbox) {
            var row = checkbox.closest('.erp-opt-row');
            if (!row) {
                return;
            }
            var qtyInput = row.querySelector('.erp-opt-qty');
            var qty = parseFloat(qtyInput && qtyInput.value) || 1;
            if (qty <= 0) {
                qty = 1;
            }
            selected.push({
                product_id: parseInt(checkbox.value, 10),
                quantity: qty,
                price: parseFloat(row.getAttribute('data-unit-price')) || 0,
            });
        });
        return selected;
    }

    function calcTotal(item, selected, forPurchase) {
        var total = parentBasePrice(item, forPurchase);
        selected.forEach(function (s) {
            total += (parseFloat(s.price) || 0) * (parseFloat(s.quantity) || 1);
        });
        return Math.round(total * 100) / 100;
    }

    function getModalEl() {
        return document.getElementById('erpProductOptionsModal');
    }

    function showModal(modalEl) {
        if (!modalEl) {
            return;
        }
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
            return;
        }
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
        modalEl.setAttribute('aria-modal', 'true');
        modalEl.removeAttribute('aria-hidden');
        document.body.classList.add('modal-open');
    }

    function hideModal(modalEl) {
        if (!modalEl) {
            return;
        }
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            var instance = bootstrap.Modal.getInstance(modalEl);
            if (instance) {
                instance.hide();
            }
            return;
        }
        modalEl.classList.remove('show');
        modalEl.style.display = 'none';
        modalEl.setAttribute('aria-hidden', 'true');
        modalEl.removeAttribute('aria-modal');
        document.body.classList.remove('modal-open');
    }

    function notifyError(message) {
        if (typeof show_toastr === 'function') {
            show_toastr(t('error', 'Error'), message, 'error');
        } else {
            alert(message);
        }
    }

    window.ErpProductOptions = {
        open: function (item, onDone, opts) {
            opts = opts || {};
            ctx.item = item;
            ctx.forPurchase = !!opts.forPurchase;
            ctx.onDone = typeof onDone === 'function' ? onDone : null;

            var modalEl = getModalEl();
            var parentNameEl = document.getElementById('erpProductOptionsParentName');
            var listEl = document.getElementById('erpProductOptionsList');
            if (!modalEl || !listEl) {
                return;
            }

            var options = item && item.options ? item.options : [];
            if (parentNameEl) {
                parentNameEl.textContent =
                    item && item.product && item.product.name ? item.product.name : '';
            }

            listEl.innerHTML = '';
            if (!options.length) {
                listEl.insertAdjacentHTML(
                    'beforeend',
                    '<div class="text-muted">' +
                        escapeHtml(t('noOptions', 'No options configured for this product.')) +
                        '</div>'
                );
            } else {
                options.forEach(function (opt) {
                    var up = optionUnitPrice(opt, ctx.forPurchase);
                    var skuHtml = opt.sku
                        ? ' <small class="text-muted">(' + escapeHtml(opt.sku) + ')</small>'
                        : '';
                    listEl.insertAdjacentHTML(
                        'beforeend',
                        '<label class="list-group-item erp-opt-row d-flex align-items-center gap-2 flex-wrap" data-unit-price="' +
                            up +
                            '">' +
                            '<input type="checkbox" class="form-check-input erp-opt-check" value="' +
                            escapeHtml(String(opt.product_id)) +
                            '">' +
                            '<span class="flex-grow-1">' +
                            escapeHtml(opt.name) +
                            skuHtml +
                            '</span>' +
                            '<span class="text-muted small">' +
                            up.toFixed(2) +
                            '</span>' +
                            '<input type="number" class="form-control form-control-sm erp-opt-qty" style="width:4.5rem" min="0.0001" step="any" value="1" title="' +
                            escapeHtml(t('qty', 'Qty')) +
                            '">' +
                            '</label>'
                    );
                });
            }

            showModal(modalEl);
        },
    };

    function onConfirmClick() {
        var modalEl = getModalEl();
        var selected = buildSelected(modalEl);
        if (!selected.length) {
            notifyError(t('selectOne', 'Select at least one product option.'));
            return;
        }
        var total = calcTotal(ctx.item, selected, ctx.forPurchase);
        hideModal(modalEl);
        if (ctx.onDone) {
            ctx.onDone(selected, total);
        }
    }

    function bindConfirmButton() {
        var btn = document.getElementById('erpProductOptionsConfirm');
        if (!btn || btn.dataset.erpOptionsBound === '1') {
            return;
        }
        btn.dataset.erpOptionsBound = '1';
        btn.addEventListener('click', onConfirmClick);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindConfirmButton);
    } else {
        bindConfirmButton();
    }
})();
