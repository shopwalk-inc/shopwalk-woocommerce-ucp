/* Shopwalk for WooCommerce — Admin JS */
jQuery(function ($) {
    var btn    = $('#shopwalk-sync-now');
    var result = $('#shopwalk-sync-result');

    if (!btn.length) return;

    btn.on('click', function () {
        btn.prop('disabled', true).text(shopwalkWC.i18n.syncing);
        result.html('<span style="color:#999;">⏳ ' + shopwalkWC.i18n.syncing + '</span>');

        $.post(shopwalkWC.ajaxUrl, {
            action: 'shopwalk_wc_sync_all',
            nonce:  shopwalkWC.nonce,
        })
        .done(function (res) {
            if (res.success) {
                result.html(
                    '<span style="color:#46b450;">✓ ' +
                    res.data.message +
                    ' <em>(' + res.data.last + ')</em></span>'
                );
            } else {
                result.html('<span style="color:#dc3232;">✗ ' + (res.data.message || shopwalkWC.i18n.error) + '</span>');
            }
        })
        .fail(function () {
            result.html('<span style="color:#dc3232;">✗ ' + shopwalkWC.i18n.error + '</span>');
        })
        .always(function () {
            btn.prop('disabled', false).text(shopwalkWC.i18n.syncNow);
        });
    });
});
