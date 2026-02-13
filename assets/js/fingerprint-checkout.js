// Fingerprint Checkout Integration
(function($) {
    'use strict';

    var fpAgent = null;
    var fpInitialized = false;

    function initFingerprint() {
        if (fpInitialized) return Promise.resolve(fpAgent);

        return import('https://fpjscdn.net/v3/' + wcpgFPConfig.key)
            .then(function(FingerprintJS) {
                return FingerprintJS.load({ region: wcpgFPConfig.region });
            })
            .then(function(agent) {
                fpAgent = agent;
                fpInitialized = true;
                return agent;
            });
    }

    function getCheckoutData() {
        return {
            email: $('#billing_email').val() || '',
            firstName: $('#billing_first_name').val() || '',
            lastName: $('#billing_last_name').val() || '',
            phone: $('#billing_phone').val() || '',
            billing: {
                address1: $('#billing_address_1').val() || '',
                address2: $('#billing_address_2').val() || '',
                city: $('#billing_city').val() || '',
                state: $('#billing_state').val() || '',
                postcode: $('#billing_postcode').val() || '',
                country: $('#billing_country').val() || ''
            },
            cart: {
                total: wcpgFPConfig.cartTotal || 0,
                itemCount: wcpgFPConfig.cartItemCount || 0,
                currency: wcpgFPConfig.currency || 'CAD'
            },
            siteId: wcpgFPConfig.siteId || '',
            siteName: wcpgFPConfig.siteName || '',
            paymentMethod: $('input[name="payment_method"]:checked').val() || '',
            checkoutTime: new Date().toISOString()
        };
    }

    async function hashEmail(email) {
        if (!email) return 'anonymous';
        var encoder = new TextEncoder();
        var data = encoder.encode(email.toLowerCase().trim());
        var hashBuffer = await crypto.subtle.digest('SHA-256', data);
        var hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.slice(0, 8).map(function(b) {
            return b.toString(16).padStart(2, '0');
        }).join('');
    }

    async function sendToFingerprint() {
        try {
            var agent = await initFingerprint();
            var checkoutData = getCheckoutData();
            var emailHash = await hashEmail(checkoutData.email);

            var result = await agent.get({
                linkedId: emailHash,
                tag: {
                    type: 'checkout',
                    customer: {
                        email: checkoutData.email,
                        name: checkoutData.firstName + ' ' + checkoutData.lastName,
                        phone: checkoutData.phone
                    },
                    billing: checkoutData.billing,
                    cart: checkoutData.cart,
                    site: {
                        id: checkoutData.siteId,
                        name: checkoutData.siteName
                    },
                    payment: {
                        method: checkoutData.paymentMethod
                    },
                    timestamp: checkoutData.checkoutTime
                }
            });

            console.debug('FP checkout tracked:', result.visitorId);
        } catch (e) {
            console.debug('FP error:', e.message);
        }
    }

    $(document).ready(function() {
        $('form.checkout').on('checkout_place_order', function() {
            sendToFingerprint();
            return true;
        });

        if (typeof wp !== 'undefined' && wp.hooks) {
            wp.hooks.addAction(
                'experimental__woocommerce_blocks-checkout-submit',
                'wcpg-fingerprint',
                sendToFingerprint
            );
        }

        initFingerprint().then(function(agent) {
            agent.get({
                linkedId: 'pageview',
                tag: {
                    type: 'checkout_pageview',
                    site: {
                        id: wcpgFPConfig.siteId,
                        name: wcpgFPConfig.siteName
                    },
                    cart: {
                        total: wcpgFPConfig.cartTotal,
                        itemCount: wcpgFPConfig.cartItemCount
                    },
                    timestamp: new Date().toISOString()
                }
            });
        });
    });
})(jQuery);
