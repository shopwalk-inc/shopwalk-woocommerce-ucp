/* Shopwalk for WooCommerce — Admin JS */
jQuery(function ($) {

    var syncBtn    = $('#shopwalk-sync-now');
    var testBtn    = $('#shopwalk-test-connection');
    var result     = $('#shopwalk-connection-result');

    /* ── STATUS DOT COLORS ───────────────────────────────────────────── */
    var COLOR_MAP = {
        green:  '#46b450',
        red:    '#dc3232',
        yellow: '#f56e28',
    };

    /* ── ROW KEY → DOM ORDER ─────────────────────────────────────────── */
    var STATUS_KEYS = [
        'plugin_key',
        'api',
        'catalog',
        'ucp',
        'browsing',
        'checkout',
        'webhooks',
    ];

    function updateStatusTable(checks) {
        var $rows = $('.shopwalk-status-table tr');
        STATUS_KEYS.forEach(function (key, idx) {
            var check = checks[key];
            if (!check) return;
            var $row = $rows.eq(idx);
            if (!$row.length) return;

            var color = COLOR_MAP[check.status] || COLOR_MAP.yellow;
            $row.find('.shopwalk-status-dot')
                .removeClass('shopwalk-status-green shopwalk-status-red shopwalk-status-yellow')
                .addClass('shopwalk-status-' + check.status)
                .css('color', color);
            $row.find('td').eq(1).text(check.label);
        });
    }

    /* ── TEST CONNECTION ─────────────────────────────────────────────── */
    if (testBtn.length) {
        testBtn.on('click', function () {
            testBtn.prop('disabled', true).text(shopwalkWC.i18n.testing || 'Testing...');
            result.html('<span style="color:#999;">⏳ Testing...</span>');

            $.post(shopwalkWC.ajaxUrl, {
                action: 'shopwalk_wc_test_connection',
                nonce:  shopwalkWC.testNonce,
            })
            .done(function (res) {
                if (res.success) {
                    updateStatusTable(res.data);
                    result.html('<span style="color:#46b450;">✓ Status refreshed</span>');
                } else {
                    result.html('<span style="color:#dc3232;">✗ ' + (res.data && res.data.message ? res.data.message : shopwalkWC.i18n.error) + '</span>');
                }
            })
            .fail(function () {
                result.html('<span style="color:#dc3232;">✗ ' + shopwalkWC.i18n.error + '</span>');
            })
            .always(function () {
                testBtn.prop('disabled', false).text(shopwalkWC.i18n.testConn || 'Test Connection');
            });
        });
    }

    /* ── SYNC ALL PRODUCTS ───────────────────────────────────────────── */
    if (syncBtn.length) {
        syncBtn.on('click', function () {
            syncBtn.prop('disabled', true).text(shopwalkWC.i18n.syncing);
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
                        (res.data.last ? ' <em>(' + res.data.last + ')</em>' : '') +
                        '</span>'
                    );
                } else {
                    result.html('<span style="color:#dc3232;">✗ ' + (res.data && res.data.message ? res.data.message : shopwalkWC.i18n.error) + '</span>');
                }
            })
            .fail(function () {
                result.html('<span style="color:#dc3232;">✗ ' + shopwalkWC.i18n.error + '</span>');
            })
            .always(function () {
                syncBtn.prop('disabled', false).text(shopwalkWC.i18n.syncNow);
            });
        });
    }

});
