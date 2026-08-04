{{-- Shared product-variant select helpers for document line repeaters --}}
<script>
    window.applyProductVariants = function ($wrap, item, selectedVariantId, priceKey) {
        priceKey = priceKey || 'sale_price';
        var $variant = $wrap.find('.variant-select');
        if (!$variant.length) {
            return;
        }
        $variant.empty().append($('<option/>').val('').text('{{ __("—") }}'));
        if (item && item.variants_enabled && item.variants && item.variants.length) {
            $variant.prop('disabled', false).prop('required', true);
            item.variants.forEach(function (v) {
                var p = (v[priceKey] != null && v[priceKey] !== '')
                    ? v[priceKey]
                    : (v.price != null ? v.price : '');
                var txt = (v.label || '') + (p !== '' && p !== null ? (' — ' + p) : '');
                $variant.append(
                    $('<option/>').val(v.id).text(txt).attr('data-price', p !== null && p !== undefined ? p : '')
                );
            });
            if (selectedVariantId) {
                $variant.val(String(selectedVariantId));
            }
        } else {
            $variant.prop('disabled', true).prop('required', false).val('');
        }
    };

    $(document).on('change', '.variant-select', function () {
        var $wrap = $(this).closest('[data-repeater-item]');
        var price = $(this).find('option:selected').attr('data-price');
        if (price !== undefined && price !== '') {
            var $price = $wrap.find('.price').first();
            $price.val(parseFloat(price).toFixed(2));
            $price.trigger('keyup').trigger('change');
        }
    });
</script>
