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
            var entry = {
                product_id: parseInt(input.value, 10),
                name: row.getAttribute('data-option-name') || '',
                quantity: qty,
                price: parseFloat(row.getAttribute('data-unit-price')) || 0,
            };
            // Order-time ingredient picks for this option (restaurant popup):
            // one alternative per recipe; empty value means keep the main.
            var ingDiv = modalEl.querySelector('.erp-opt-ingredients[data-option-id="' + input.value + '"]');
            if (ingDiv) {
                var subs = [];
                ingDiv.querySelectorAll('.erp-opt-ing-group').forEach(function (grp) {
                    var checked = grp.querySelector('input[type="radio"]:checked');
                    var altVal = checked ? checked.value : '';
                    if (altVal !== '' && altVal !== null) {
                        subs.push({
                            recipe_id: parseInt(grp.getAttribute('data-recipe-id'), 10),
                            alternative_product_id: parseInt(altVal, 10),
                        });
                    }
                });
                if (subs.length) {
                    entry.ingredient_substitutes = subs;
                }
            }
            selected.push(entry);
        });

        return selected;
    }

    function renderOptionRow(opt, inputType, inputName) {
        var up = optionUnitPrice(opt, ctx.forPurchase);
        var skuHtml = opt.sku
            ? ' <small class="text-muted">(' + escapeHtml(opt.sku) + ')</small>'
            : '';
        var setupQty = parseFloat(opt.quantity);
        if (!(setupQty > 0)) {
            setupQty = 1;
        }
        var qtyHtml =
            '<input type="number" class="form-control form-control-sm erp-opt-qty flex-shrink-0" style="width:4rem" min="0.0001" step="any" value="' +
            setupQty +
            '" title="' +
            escapeHtml(t('qty', 'Qty')) +
            '">';
        var stockQty = opt.stock_qty != null && opt.stock_qty !== '' ? opt.stock_qty : '—';
        var stockUnit = opt.unit != null ? String(opt.unit) : '';
        var stockBadge =
            '<small class="badge bg-danger mb-0 rounded qty_badge flex-shrink-0 text-nowrap">' +
            escapeHtml(String(stockQty) + (stockUnit ? ' ' + stockUnit : '')) +
            '</small>';
        var typeMap = {
            product: ['type_product', 'Product', 'bg-primary'],
            service: ['type_service', 'Service', 'bg-info'],
            kit: ['type_kit', 'Kit', 'bg-warning text-dark'],
            ingredient: ['type_ingredient', 'Ingredient', 'bg-secondary']
        };
        var typeKey = String(opt.type || 'product').toLowerCase();
        var typeInfo = typeMap[typeKey] || [null, typeKey.charAt(0).toUpperCase() + typeKey.slice(1), 'bg-secondary'];
        var typeBadge =
            '<small class="badge ' +
            typeInfo[2] +
            ' mb-0 rounded flex-shrink-0 text-nowrap">' +
            escapeHtml(typeInfo[0] ? t(typeInfo[0], typeInfo[1]) : typeInfo[1]) +
            '</small>';
        var noStockBadge = opt.does_not_affect_stock
            ? '<small class="badge bg-dark mb-0 rounded flex-shrink-0 text-nowrap">' +
              escapeHtml(t('noStock', 'Does not affect stock')) +
              '</small>'
            : '';
        var variantsCount = parseInt(opt.variants_count, 10) || 0;
        var variantsBadge = variantsCount > 0
            ? '<small class="badge bg-info mb-0 rounded flex-shrink-0 text-nowrap">' +
              escapeHtml(t('variants', 'Variants')) +
              ': ' +
              variantsCount +
              '</small>'
            : '';

        return (
            '<label class="list-group-item erp-opt-row d-flex align-items-center gap-2 flex-nowrap" data-unit-price="' +
            up +
            '" data-option-name="' +
            escapeHtml(opt.name || '') +
            '">' +
            '<input type="' +
            inputType +
            '" class="form-check-input flex-shrink-0 ' +
            (inputType === 'radio' ? 'erp-opt-radio' : 'erp-opt-check') +
            '" name="' +
            escapeHtml(inputName) +
            '" value="' +
            escapeHtml(String(opt.product_id)) +
            '">' +
            '<span class="flex-grow-1 text-truncate min-w-0">' +
            escapeHtml(opt.name) +
            skuHtml +
            '</span>' +
            typeBadge +
            stockBadge +
            noStockBadge +
            variantsBadge +
            '<span class="text-muted small flex-shrink-0 text-nowrap">' +
            up.toFixed(2) +
            '</span>' +
            qtyHtml +
            '</label>'
        );
    }

    // Ingredients of an option product (an option is itself a product with
    // its own recipe). Rendered under the option row, never inside the
    // row's <label> — a click on a radio must not toggle the option.
    // One radio group per ingredient: main (checked by default) or one
    // alternative, each with its per-unit quantity.
    function renderOptionIngredients(opt) {
        if (!ctx.showOptionIngredients || !opt.ingredients || !opt.ingredients.length) {
            return '';
        }
        var html =
            '<div class="erp-opt-ingredients border rounded p-2 mb-2 bg-light" data-option-id="' +
            escapeHtml(String(opt.product_id)) +
            '">' +
            '<div class="fw-semibold small mb-1">' +
            escapeHtml(t('ingredients', 'Ingredients')) +
            '</div>';
        opt.ingredients.forEach(function (ing) {
            var mainQty = ing.quantity != null ? ' — ' + ing.quantity + (ing.unit ? ' ' + ing.unit : '') : '';
            html +=
                '<div class="erp-opt-ing-group small mb-2" data-recipe-id="' +
                escapeHtml(String(ing.recipe_id)) +
                '">' +
                '<div class="fw-semibold">' +
                escapeHtml(ing.ingredient_name) +
                '</div>';
            if (ing.affects_stock && ing.alternatives && ing.alternatives.length) {
                var groupName = 'erp-opt-ing-' + opt.product_id + '-' + ing.recipe_id;
                html +=
                    '<label class="d-block"><input type="radio" class="form-check-input me-1" name="' +
                    escapeHtml(groupName) +
                    '" value="" checked> ' +
                    escapeHtml(ing.ingredient_name + mainQty) +
                    ' (' +
                    escapeHtml(t('main', 'Main')) +
                    ')</label>';
                ing.alternatives.forEach(function (a) {
                    var aQty = a.quantity != null ? ' — ' + a.quantity : '';
                    html +=
                        '<label class="d-block"><input type="radio" class="form-check-input me-1" name="' +
                        escapeHtml(groupName) +
                        '" value="' +
                        escapeHtml(String(a.id)) +
                        '"> ' +
                        escapeHtml(a.name + aQty) +
                        '</label>';
                });
            } else {
                html += '<div class="text-muted">' + escapeHtml(ing.ingredient_name + mainQty) + '</div>';
            }
            html += '</div>';
        });
        html += '</div>';
        return html;
    }

    function calcOptionsAmount(selected) {
        var total = 0;
        selected.forEach(function (s) {
            total += (parseFloat(s.price) || 0) * (parseFloat(s.quantity) || 1);
        });
        return Math.round(total * 100) / 100;
    }

    // Parent unit price only — selected options do not modify the parent base price.
    function calcTotal(item, selected, forPurchase) {
        return Math.round(parentBasePrice(item, forPurchase) * 100) / 100;
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

            if (grp.required && picked.length < 1) {
                notifyError(t('selectGroup', 'Select at least one option for') + ' ' + groupName);
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
            ctx.showOptionIngredients = !!opts.showOptionIngredients;
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
                    var html =
                        '<div class="erp-opt-group mb-3">' +
                        '<div class="fw-semibold small mb-1">' +
                        escapeHtml(groupName) +
                        reqMark +
                        '</div>' +
                        '<div class="list-group">';
                    grp.items.forEach(function (opt) {
                        html += renderOptionRow(opt, 'checkbox', 'erp-opt-group-' + slugifyGroup(groupName) + '-' + opt.product_id);
                        html += renderOptionIngredients(opt);
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
                        addOnHtml += renderOptionIngredients(opt);
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
