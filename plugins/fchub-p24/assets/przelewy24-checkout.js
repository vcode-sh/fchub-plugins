function fchubP24EscapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function fchubP24SafeImgUrl(url) {
    if (typeof url === 'string' && url.indexOf('https://') === 0) {
        return url;
    }
    return '';
}

window.addEventListener("fluent_cart_load_payments_przelewy24", function (e) {
    var i18n = window.fchub_p24_i18n || {};
    var submitButton = window.fluentcart_checkout_vars?.submit_button;
    var container = document.querySelector('.fluent-cart-checkout_embed_payment_container_przelewy24');
    var hasSubscription = !!(e.detail?.hasSubscription || e.detail?.has_subscription);

    if (!container) {
        e.detail.paymentLoader.enableCheckoutButton(submitButton?.text || i18n.place_order || 'Place Order');
        return;
    }

    ensureCheckoutStyles();

    container.innerHTML = '<p style="margin:0;padding:10px;color:#888;font-size:13px;">' + (i18n.loading || 'Loading payment methods...') + '</p>';

    fetch(e.detail.paymentInfoUrl, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": e.detail.nonce,
        },
        credentials: 'include'
    }).then(function (r) { return r.json(); }).then(function (response) {
        var methods = response?.payment_args?.methods || [];

        // For subscriptions, filter to card methods only
        if (hasSubscription) {
            methods = methods.filter(function (m) {
                var g = (m.group || '').toLowerCase();
                return g === 'credit card' || g === 'cards';
            });

            if (!methods.length) {
                container.innerHTML = '<p style="color:#dc3545;padding:10px;">' + (i18n.subscription_cards_only || 'Subscription requires card payment.') + '</p>';
                return;
            }
        }

        if (!methods.length) {
            container.innerHTML = '<p style="color:#dc3545;padding:10px;">' + (i18n.no_methods || 'No payment methods available.') + '</p>';
            return;
        }

        // Group methods
        var groups = {};
        var groupLabels = {
            'Blik': i18n.group_blik || 'BLIK',
            'FastTransfers': i18n.group_fast || 'Fast transfers',
            'Wallet': i18n.group_wallets || 'Wallets',
            'eTransfer': i18n.group_etransfer || 'e-Transfers',
            'TraditionalTransfer': i18n.group_traditional || 'Traditional transfer',
            'Cards': i18n.group_cards || 'Cards'
        };

        methods.forEach(function (m) {
            var g = m.group || 'Other';
            if (!groups[g]) groups[g] = [];
            groups[g].push(m);
        });

        // Sort: BLIK first, then FastTransfers, then rest
        var order = ['Blik', 'FastTransfers', 'Cards', 'Wallet', 'eTransfer', 'TraditionalTransfer'];
        var sortedKeys = Object.keys(groups).sort(function (a, b) {
            var ia = order.indexOf(a), ib = order.indexOf(b);
            if (ia === -1) ia = 99;
            if (ib === -1) ib = 99;
            return ia - ib;
        });

        var html = '<div class="fchub-p24-methods">';

        sortedKeys.forEach(function (groupKey) {
            var label = groupLabels[groupKey] || groupKey;
            html += '<div class="fchub-p24-group">';
            html += '<div class="fchub-p24-group-title">' + label + '</div>';
            html += '<div class="fchub-p24-grid">';

            groups[groupKey].forEach(function (m) {
                var safeName = fchubP24EscapeHtml(m.name || '');
                var safeImg = fchubP24EscapeHtml(fchubP24SafeImgUrl(m.imgUrl || ''));
                html += '<label class="fchub-p24-method-card">'
                    + '<input type="radio" name="fchub_p24_method" value="' + m.id + '" style="display:none;" />'
                    + (safeImg ? '<img src="' + safeImg + '" alt="' + safeName + '" title="' + safeName + '" />' : '<span>' + safeName + '</span>')
                    + '</label>';
            });

            html += '</div></div>';
        });

        html += '</div>';
        html += '<input type="hidden" name="fchub_p24_selected_method" id="fchub_p24_selected_method" value="" />';
        container.innerHTML = html;

        // Handle method selection
        container.querySelectorAll('input[name="fchub_p24_method"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                // Reset selection
                container.querySelectorAll('.fchub-p24-method-card').forEach(function (l) {
                    l.classList.remove('is-selected');
                });

                // Highlight selected
                var label = this.closest('label');
                if (label) {
                    label.classList.add('is-selected');
                }
                document.getElementById('fchub_p24_selected_method').value = this.value;

                // Enable checkout button
                e.detail.paymentLoader.enableCheckoutButton(submitButton?.text || i18n.pay || 'Pay');
            });
        });

    }).catch(function (err) {
        console.error('P24 methods error:', err);
        container.innerHTML = '<p style="color:#dc3545;padding:10px;">' + (i18n.error_loading || 'Error loading payment methods.') + '</p>';
    });
});

function ensureCheckoutStyles() {
    var style = document.getElementById('fchub-p24-checkout-styles');
    if (!style) {
        style = document.createElement('style');
        style.id = 'fchub-p24-checkout-styles';
        document.head.appendChild(style);
    }

    style.textContent = [
        '.fchub-p24-methods{padding:8px 0;}',
        '.fchub-p24-group{margin-bottom:12px;}',
        '.fchub-p24-group-title{font-size:12px;font-weight:600;color:#666;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px;}',
        '.fchub-p24-grid{display:grid !important;grid-template-columns:repeat(3,minmax(0,1fr)) !important;gap:8px !important;}',
        '.fchub-p24-method-card{display:flex !important;align-items:center !important;justify-content:center !important;box-sizing:border-box !important;border:0 !important;box-shadow:inset 0 0 0 2px #e0e0e0 !important;border-radius:8px !important;padding:8px 10px !important;cursor:pointer !important;transition:box-shadow .15s ease !important;background:#fff !important;min-height:72px !important;overflow:hidden !important;}',
        '.fchub-p24-method-card:hover{box-shadow:inset 0 0 0 2px #d13239 !important;}',
        '.fchub-p24-method-card.is-selected{box-shadow:inset 0 0 0 2px #d13239 !important;}',
        '.fchub-p24-method-card img{display:block !important;max-width:130px !important;max-height:50px !important;width:100% !important;height:46px !important;object-fit:contain !important;}',
        '@media (min-width: 1200px){.fchub-p24-grid{grid-template-columns:repeat(4,minmax(0,1fr)) !important;}}',
        '@media (max-width: 640px){.fchub-p24-grid{grid-template-columns:repeat(2,minmax(0,1fr)) !important;gap:6px !important;}.fchub-p24-method-card{min-height:66px !important;padding:8px !important;}.fchub-p24-method-card img{max-width:118px !important;max-height:46px !important;height:42px !important;}}'
    ].join('');
}
