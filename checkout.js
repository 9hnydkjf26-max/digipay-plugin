const settings_cc = window.wc.wcSettings.getSetting( 'paygobillingcc_data', {} );
const label_cc = window.wp.htmlEntities.decodeEntities( settings_cc.title ) || window.wp.i18n.__( 'Pay by Credit Card', 'paygobillingcc' );
const Content_cc = () => {
    return window.wp.htmlEntities.decodeEntities( settings_cc.description || '' );
};
const Block_Gateway = {
    name: 'paygobillingcc',
    label: label_cc,
    content: Object( window.wp.element.createElement )( Content_cc, null ),
    edit: Object( window.wp.element.createElement )( Content_cc, null ),
    canMakePayment: () => true,
    ariaLabel: label_cc,
    supports: {
        features: settings_cc.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod( Block_Gateway );