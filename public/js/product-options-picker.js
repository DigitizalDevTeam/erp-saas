/**
 * Product options picker modal (parent price + selected option prices).
 * Supports grouped single-choice (radio) and ungrouped multi-select (checkbox).
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

    function slugifyGroup(name) {
        return String(name || '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'group';
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

    function groupOptions(options) {
        var groups = {};
        var ungrouped = [];

        (options || []).forEach(function (opt) {
            var groupName = (opt.option_group || '').trim();
            if (groupName) {
                if (!groups[groupName]) {
                    groups[groupName] = { name: groupName, required: false, items: [] };
                }
                groups[groupName].items.push(opt);
                if (opt.is_required) {
                    groups[groupName].required = true;
                }
            } else {
                ungrouped.push(opt);
            }
        });

        return { groups: groups, ungrouped: ungrouped };
    }

    function buildSelected(modalEl) {
        var selected = [];
        if (!modalEl) {
            return selected;
        }

        modalEl.querySelectorAll('.erp-opt-check:checked, .erp-opt-radio:checked').forEach(function (input) {
            var row = input.closest('.erp-opt-row');
            if (!row) {
                return;
            }
            var qtyInput = row.querySelector('.erp-opt-qty');
            var qty = parseFloat(qtyInput && qtyInput.value) || 1;
            if (qty <= 0) {
                qty = 1;
            }
            selected.push({
                product_id: parseInt(input.value, 10),
                quantity: qty,
                price: parseFloat(row.getAttribute('data-unit-price')) || 0,
            });
        });

        return selected;
    }

    function renderOptionRow(opt, inputType, inputName) {
        var up = optionUnitPrice(opt, ctx.forPurchase);
        var skuHtml = opt.sku
            ? ' <small class="text-muted">(' + escapeHtml(opt.sku) + ')</small>'
            : '';
        var qtyHtml =
            inputType === 'checkbox'
                ? '<input type="number" class="form-control form-control-sm erp-opt-qty" style="width:4.5rem" min="0.0001" step="any" value="1" title="' +
                  escapeHtml(t('qty', 'Qty')) +
                  '">'
                : '';

        return (
            '<label class="list-group-item erp-opt-row d-flex align-items-center gap-2 flex-wrap" data-unit-price="' +
            up +
            '">' +
            '<input type="' +
            inputType +
            '" class="form-check-input ' +
            (inputType === 'radio' ? 'erp-opt-radio' : 'erp-opt-check') +
            '" name="' +
            escapeHtml(inputName) +
            '" value="' +
            escapeHtml(String(opt.product_id)) +
            '">' +
            '<span class="flex-grow-1">' +
            escapeHtml(opt.name) +
            skuHtml +
            '</span>' +
            '<span class="text-muted small">' +
            up.toFixed(2) +
            '</span>' +
            qtyHtml +
            '</label>'
        );
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
        if (typeof $ !== 'undefined' && $.fn.modal) {
            $(modalEl).modal('show');
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
        if (typeof $ !== 'undefined' && $.fn.modal) {
            $(modalEl).modal('hide');
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

    function validateSelection(options, selected) {
        var grouped = groupOptions(options);
        var groupNames = Object.keys(grouped.groups);

        for (var i = 0; i < groupNames.length; i++) {
            var groupName = groupNames[i];
            var grp = grouped.groups[groupName];
            var picked = selected.filter(function (s) {
                return grp.items.some(function (item) {
                    return parseInt(item.product_id, 10) === parseInt(s.product_id, 10);
                });
            });

            if (grp.required && picked.length !== 1) {
                notifyError(t('selectGroup', 'Select one option for') + ' ' + groupName);
                return false;
            }
            if (!grp.required && picked.length > 1) {
                notifyError(t('selectOneGroup', 'Select at most one option for') + ' ' + groupName);
                return false;
            }
        }

        if (!selected.length) {
            notifyError(t('selectOne', 'Select at least one product option.'));
            return false;
        }

        return true;
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
                var grouped = groupOptions(options);

                Object.keys(grouped.groups).forEach(function (groupName) {
                    var grp = grouped.groups[groupName];
                    var reqMark = grp.required
                        ? ' <span class="text-danger">*</span>'
                        : '';
                    var radioName = 'erp-opt-group-' + slugifyGroup(groupName);
                    var html =
                        '<div class="erp-opt-group mb-3">' +
                        '<div class="fw-semibold small mb-1">' +
                        escapeHtml(groupName) +
                        reqMark +
                        '</div>' +
                        '<div class="list-group">';
                    grp.items.forEach(function (opt) {
                        html += renderOptionRow(opt, 'radio', radioName);
                    });
                    html += '</div></div>';
                    listEl.insertAdjacentHTML('beforeend', html);
                });

                if (grouped.ungrouped.length) {
                    var addOnHtml =
                        '<div class="erp-opt-group mb-0">' +
                        '<div class="fw-semibold small mb-1">' +
                        escapeHtml(t('addOns', 'Add-ons')) +
                        '</div>' +
                        '<div class="list-group">';
                    grouped.ungrouped.forEach(function (opt) {
                        addOnHtml += renderOptionRow(opt, 'checkbox', 'erp-opt-addon-' + opt.product_id);
                    });
                    addOnHtml += '</div></div>';
                    listEl.insertAdjacentHTML('beforeend', addOnHtml);
                }
            }

            showModal(modalEl);
        },
    };

    function onConfirmClick() {
        var modalEl = getModalEl();
        var options = ctx.item && ctx.item.options ? ctx.item.options : [];
        var selected = buildSelected(modalEl);
        if (!validateSelection(options, selected)) {
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
