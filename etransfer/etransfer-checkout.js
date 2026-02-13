/**
 * E-Transfer Payment Gateway - Block Checkout Integration
 *
 * Dynamically registers E-Transfer payment methods with WooCommerce Blocks.
 * Supports Email, URL, and Manual delivery methods.
 *
 * @package DigipayMasterPlugin
 * @since 12.7.0
 */

( function() {
	'use strict';

	// Payment method configurations.
	const paymentMethods = [
		{ id: 'digipay_etransfer_email', settingsKey: 'digipay_etransfer_email_data' },
		{ id: 'digipay_etransfer_url', settingsKey: 'digipay_etransfer_url_data' },
		{ id: 'digipay_etransfer_manual', settingsKey: 'digipay_etransfer_manual_data' },
	];

	/**
	 * Create a Content component for a payment method.
	 *
	 * @param {Object} settings Payment method settings.
	 * @return {Function} React component.
	 */
	function createContent( settings ) {
		return function Content() {
			const description = window.wp.htmlEntities.decodeEntities( settings.description || '' );
			return window.wp.element.createElement( 'div', null, description );
		};
	}

	/**
	 * Create a Label component for a payment method.
	 *
	 * @param {string} label Payment method label.
	 * @return {Function} React component.
	 */
	function createLabel( label ) {
		return function Label() {
			return window.wp.element.createElement( 'span', null, label );
		};
	}

	/**
	 * Register a payment method with WooCommerce Blocks.
	 *
	 * @param {string} methodId Payment method ID.
	 * @param {Object} settings Payment method settings.
	 */
	function registerPaymentMethod( methodId, settings ) {
		const label = window.wp.htmlEntities.decodeEntities( settings.title ) ||
			window.wp.i18n.__( 'Interac E-Transfer', 'wc-payment-gateway' );

		const Content = createContent( settings );
		const Label = createLabel( label );

		const paymentMethod = {
			name: methodId,
			label: window.wp.element.createElement( Label, null ),
			content: window.wp.element.createElement( Content, null ),
			edit: window.wp.element.createElement( Content, null ),
			canMakePayment: function() {
				return true;
			},
			ariaLabel: label,
			supports: {
				features: settings.supports || [ 'products' ]
			}
		};

		window.wc.wcBlocksRegistry.registerPaymentMethod( paymentMethod );
	}

	// Register each available payment method.
	paymentMethods.forEach( function( method ) {
		const settings = window.wc.wcSettings.getSetting( method.settingsKey, null );
		if ( settings && settings.name ) {
			registerPaymentMethod( method.id, settings );
		}
	} );

	// Fallback: Register legacy single method if no new methods found.
	const legacySettings = window.wc.wcSettings.getSetting( 'digipay_etransfer_data', null );
	if ( legacySettings && legacySettings.name ) {
		// Check if we already registered any new methods.
		const hasNewMethods = paymentMethods.some( function( method ) {
			return window.wc.wcSettings.getSetting( method.settingsKey, null ) !== null;
		} );

		if ( ! hasNewMethods ) {
			registerPaymentMethod( 'digipay_etransfer', legacySettings );
		}
	}

} )();
