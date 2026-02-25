/* Shopwalk for WooCommerce — Admin JS */
jQuery(function ($) {

    var syncBtn    = $('#shopwalk-sync-now');
    var testBtn    = $('#shopwalk-test-connection');
    var result     = $('#shopwalk-connection-result');

    /* ── AUTO-REGISTER (connect screen) ─────────────────────────────── */
    var registerBtn    = $('#shopwalk-auto-register-btn');
    var registerStatus = $('#shopwalk-register-status');

    if (registerBtn.length) {
        registerBtn.on('click', function () {
            registerBtn.prop('disabled', true).text(shopwalkWC.i18n.connecting || 'Connecting your store...');
            registerStatus.show().text('');

            $.post(shopwalkWC.ajaxUrl, {
                action: 'shopwalk_auto_register',
                nonce:  registerBtn.data('nonce') || shopwalkWC.registerNonce,
            })
            .done(function (res) {
                if (res.success) {
                    registerStatus
                        .css('color', '#46b450')
                        .text('✓ ' + (res.data.message || shopwalkWC.i18n.connectSuccess));
                    setTimeout(function () { window.location.reload(); }, 1200);
                } else {
                    registerStatus
                        .css('color', '#dc3232')
                        .text('✗ ' + (res.data && res.data.message ? res.data.message : shopwalkWC.i18n.connectError));
                    registerBtn.prop('disabled', false).text('Connect to Shopwalk AI — it\'s free');
                }
            })
            .fail(function () {
                registerStatus.css('color', '#dc3232').text('✗ ' + (shopwalkWC.i18n.connectError || 'Connection failed. Please try again.'));
                registerBtn.prop('disabled', false).text('Connect to Shopwalk AI — it\'s free');
            });
        });
    }

    /* ── SHOW / HIDE MANUAL KEY FORM ────────────────────────────────── */
    $('#shopwalk-show-manual-key').on('click', function (e) {
        e.preventDefault();
        $('#shopwalk-manual-key-form').slideToggle(200);
    });

    /* ── MANUAL KEY SAVE ─────────────────────────────────────────────── */
    $('#shopwalk-manual-key-save').on('click', function () {
        var key = $('#shopwalk-manual-key-input').val().trim();
        if (!key) return;

        $(this).prop('disabled', true).text('Saving...');

        $.post(shopwalkWC.ajaxUrl, {
            action:    'shopwalk_save_manual_key',
            nonce:     shopwalkWC.registerNonce,
            plugin_key: key,
        })
        .done(function (res) {
            if (res.success) {
                window.location.reload();
            } else {
                alert(res.data && res.data.message ? res.data.message : 'Failed to save key.');
                $('#shopwalk-manual-key-save').prop('disabled', false).text('Save Key');
            }
        })
        .fail(function () {
            alert('Request failed. Please try again.');
            $('#shopwalk-manual-key-save').prop('disabled', false).text('Save Key');
        });
    });

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
