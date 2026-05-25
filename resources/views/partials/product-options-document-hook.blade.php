{{-- Call after loading product JSON: ErpDocProductOptions.afterLoad(item, $line, el, forPurchase, applyFn) --}}
<script>
(function () {
    if (window.ErpDocProductOptions) return;
    window.ErpDocProductOptions = {
        afterLoad: function (item, $line, el, forPurchase, applyFn) {
            if (!item.options_enabled || !item.options || !item.options.length) {
                $line.find('.selected-options-json').val('');
                applyFn(item, parseFloat(forPurchase ? item.product.purchase_price : item.product.sale_price) || 0, '');
                return;
            }
            window.ErpProductOptions.open(item, function (selected, total) {
                $line.find('.selected-options-json').val(JSON.stringify(selected));
                applyFn(item, total, JSON.stringify(selected));
            }, { forPurchase: !!forPurchase });
        }
    };
})();
</script>
