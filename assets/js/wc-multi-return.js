jQuery(document).ready(function($) {
    $('#wc-multi-return-form').on('submit', async function(e) {
        e.preventDefault();
        const $form = $(this);
        const $submitBtn = $form.find('button[type="submit"]');
        const originalBtnText = $submitBtn.text();
        
        try {
            // Disable button during processing
            $submitBtn.prop('disabled', true).text(WCMultiReturn.i18n.processing || 'Processing...');
            
            // Validate selections
            const selected = $form.find('input[name="selected_items[]"]:checked');
            if (selected.length === 0) {
                alert(WCMultiReturn.i18n.select_one);
                return;
            }

            // Prepare items array
            const orderId = $form.find('input[name="order_id"]').val();
            const items = [];
            let validationError = false;

            selected.each(function() {
                const itemId = $(this).val();
                const qtyInput = $form.find(`input[name="return_qty[${itemId}]"]`);
                const reasonInput = $form.find(`input[name="return_reason[${itemId}]"]`);
                const qty = parseInt(qtyInput.val(), 10);
                const maxQty = parseInt(qtyInput.attr('max'), 10);
                const reason = reasonInput.val().trim();

                if (isNaN(qty) || qty < 1 || qty > maxQty) {
                    qtyInput.addClass('error').focus();
                    alert(WCMultiReturn.i18n.invalid_qty);
                    validationError = true;
                    return false; // Break the each loop
                }

                items.push({
                    item_id: itemId,
                    qty: qty,
                    reason: reason
                });
            });

            if (validationError || items.length === 0) {
                return;
            }
            // Prepare payload
            const payload = {
                action: 'wc_multi_return_submit',
                _ajax_nonce: WCMultiReturn.nonce,
                yith_nonce: WCMultiReturn.yith_nonce,
                order_id: orderId,
                items: JSON.stringify(items)
            };

            // Submit request
            const response = await $.ajax({
                url: WCMultiReturn.ajax_url,
                type: 'POST',
                data: payload,
                dataType: 'json'
            });
            console.log(response);
            if (response.success) {
                // Check for partial failures
                const failedItems = response.data.results ? 
                    Object.entries(response.data.results).filter(([id, success]) => !success).length : 0;
                
                if (failedItems > 0) {
                    const message = WCMultiReturn.i18n.partial_success.replace('{count}', failedItems);
                    alert(message);
                } else {
                    alert(WCMultiReturn.i18n.success);
                    window.location.reload();
                }
            } else {
                alert(response.data || WCMultiReturn.i18n.error);
            }
        
        } catch (error) {
            console.error('Return submission error:', error);
            const errorMessage = error.responseJSON && error.responseJSON.data ? 
                error.responseJSON.data : 
                (WCMultiReturn.i18n.error || 'An error occurred');
            alert(errorMessage);
        } finally {
            $submitBtn.prop('disabled', false).text(originalBtnText);
        }
    });

    // Quantity validation
    $(document).on('input', 'input[type="number"][name^="return_qty"]', function() {
        const $input = $(this);
        const max = parseInt($input.attr('max'), 10);
        const val = parseInt($input.val(), 10);
        
        if (isNaN(val) || val < 1) {
            $input.val(1);
        } else if (val > max) {
            $input.val(max);
        }
    });
});