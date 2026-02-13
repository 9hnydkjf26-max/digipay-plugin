const settings_crypto = window.wc.wcSettings.getSetting( 'wcpg_crypto_data', {} );
const label_crypto = window.wp.htmlEntities.decodeEntities( settings_crypto.title ) || window.wp.i18n.__( 'Pay with Crypto', 'wc-payment-gateway' );
const Content_crypto = () => {
    return window.wp.htmlEntities.decodeEntities( settings_crypto.description || '' );
};
const Block_Gateway_Crypto = {
    name: 'wcpg_crypto',
    label: label_crypto,
    content: Object( window.wp.element.createElement )( Content_crypto, null ),
    edit: Object( window.wp.element.createElement )( Content_crypto, null ),
    canMakePayment: () => true,
    ariaLabel: label_crypto,
    supports: {
        features: settings_crypto.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod( Block_Gateway_Crypto );
