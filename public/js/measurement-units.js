/*
 * Measurement-unit picker for product option rows and recipe rows.
 * The unit list itself is injected by the page as window.MEASUREMENT_UNITS
 * (from App\Support\MeasurementUnits) so PHP stays the single source.
 * Selects are enhanced with Choices.js (searchable) when available.
 */
(function () {
    function units() {
        return window.MEASUREMENT_UNITS || {};
    }

    function optionsHtml(selected) {
        var html = '<option value="">' + (window.MEASUREMENT_UNIT_PLACEHOLDER || 'Select unit') + '</option>';
        Object.keys(units()).forEach(function (code) {
            html += '<option value="' + code + '"' + (code === selected ? ' selected' : '') + '>' + units()[code] + '</option>';
        });
        return html;
    }

    function selectHtml(name, selected, extraClass) {
        return '<select name="' + name + '" class="form-select form-select-sm measurement-unit-select ' + (extraClass || '') + '">'
            + optionsHtml(selected) + '</select>';
    }

    function enhance(scope) {
        if (typeof Choices === 'undefined' || typeof $ === 'undefined') {
            return;
        }
        $(scope || document).find('select.measurement-unit-select').each(function () {
            if (this._muChoices) {
                return;
            }
            this._muChoices = new Choices(this, {
                searchEnabled: true,
                shouldSort: false,
                itemSelectText: '',
                searchPlaceholderValue: 'Search unit'
            });
        });
    }

    function setValue(select, code) {
        if (!select) {
            return;
        }
        select.value = code || '';
        if (select._muChoices) {
            select._muChoices.setChoiceByValue(code || '');
        }
        $(select).trigger('change');
    }

    window.MeasurementUnitsUI = {
        optionsHtml: optionsHtml,
        selectHtml: selectHtml,
        enhance: enhance,
        setValue: setValue
    };
})();
