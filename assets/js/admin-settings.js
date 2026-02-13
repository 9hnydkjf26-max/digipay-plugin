/**
 * WooCommerce Payment Gateway - Admin Settings Scripts
 *
 * JavaScript for the gateway settings page: advanced settings toggle,
 * tab save buttons, collapsible sections, and e-Transfer field visibility.
 *
 * @package Digipay
 * @version 12.6.18
 */

jQuery(document).ready(function($) {
	'use strict';

	// Find the Advanced Settings title row.
	var $advancedTitle = $('tr').filter(function() {
		return $(this).find('th').text().indexOf('Advanced Settings') !== -1;
	});

	if ($advancedTitle.length) {
		// Get all rows after the Advanced Settings title (these are the advanced fields).
		var $advancedRows = $advancedTitle.nextAll('tr');

		// Hide them initially.
		$advancedRows.hide();

		// Handle toggle click.
		$('#wcpg-toggle-advanced').on('click', function(e) {
			e.preventDefault();
			var $link = $(this);

			if ($advancedRows.is(':visible')) {
				$advancedRows.slideUp(200);
				$link.text('Click to expand');
			} else {
				$advancedRows.slideDown(200);
				$link.text('Click to collapse');
			}
		});
	}

	// Hide WooCommerce's default save button on non-Credit Card tabs.
	// The wcpgAdminSettings object is localized from PHP.
	if (typeof wcpgAdminSettings !== 'undefined' && wcpgAdminSettings.hideDefaultSaveButton) {
		$('p.submit > .woocommerce-save-button').not('#tab-e-transfer .woocommerce-save-button').hide();
	}

	// Auto-save toggle switches.
	$(document).on('change', '.wcpg-toggle-input', function() {
		var $input = $(this);
		var $toggle = $input.closest('.wcpg-toggle');
		var $status = $toggle.siblings('.wcpg-toggle-status');
		var settingKey = $input.data('setting-key');
		var gateway = $input.data('gateway');
		var value = $input.is(':checked') ? 'yes' : 'no';

		// Show saving state.
		$toggle.addClass('saving');
		$status.removeClass('visible error').text('');

		$.ajax({
			url: wcpgAdminSettings.ajaxUrl,
			type: 'POST',
			data: {
				action: 'wcpg_toggle_setting',
				nonce: wcpgAdminSettings.toggleNonce,
				gateway: gateway,
				setting_key: settingKey,
				value: value
			},
			success: function(response) {
				$toggle.removeClass('saving');
				if (response.success) {
					$status.text('Saved').addClass('visible');
					setTimeout(function() {
						$status.removeClass('visible');
					}, 2000);
					// Clear form dirty state to prevent "Leave site?" warning.
					$(window).off('beforeunload.wc-gateway-settings beforeunload');
					$input.data('saved-value', $input.is(':checked'));
					$input.prop('defaultChecked', $input.is(':checked'));
					// Reset WooCommerce change tracking.
					$('form').trigger('reset.wc-enhanced-select');
					if (typeof window.onbeforeunload === 'function') {
						window.onbeforeunload = null;
					}
				} else {
					$status.text(response.data || 'Error').addClass('visible error');
					// Revert toggle on error.
					$input.prop('checked', value === 'no');
				}
			},
			error: function() {
				$toggle.removeClass('saving');
				$status.text('Error saving').addClass('visible error');
				// Revert toggle on error.
				$input.prop('checked', value === 'no');
			}
		});
	});

	// Style checkboxes cleanly (standard checkboxes, no toggle transformation).
	$('#woocommerce_paygobillingcc_enabled, #woocommerce_paygobillingcc_encrypt_description').each(function() {
		var $checkbox = $(this);

		// Apply clean checkbox styling via inline styles.
		$checkbox.css({
			'width': '18px',
			'height': '18px',
			'margin': '0 10px 0 0',
			'vertical-align': 'middle',
			'accent-color': '#2271b1',
			'cursor': 'pointer'
		});

		// Style the parent label.
		$checkbox.closest('label').css({
			'display': 'inline-flex',
			'align-items': 'center',
			'font-size': '14px',
			'color': '#1d2327',
			'cursor': 'pointer'
		});
	});

	// --- Credit Card tab save button ---
	$('#wcpg-cc-save-btn').on('click', function() {
		var $btn = $(this);
		var $status = $('#wcpg-cc-save-status');

		$btn.prop('disabled', true).text('Saving...');
		$status.text('');

		var formData = $('#wcpg-cc-settings-form input, #wcpg-cc-settings-form select, #wcpg-cc-settings-form textarea').serialize();

		$.post(window.location.href, formData, function() {
			$btn.prop('disabled', false).text(wcpgAdminSettings.saveCCLabel);
			$status.html('<span style="color: green;">\u2713 Saved</span>');
			setTimeout(function() { $status.text(''); }, 3000);
		}).fail(function() {
			$btn.prop('disabled', false).text(wcpgAdminSettings.saveCCLabel);
			$status.html('<span style="color: red;">Save failed. Please try again.</span>');
		});
	});

	// --- Collapsible sections (shared by E-Transfer and Crypto tabs) ---
	$('.wcpg-collapsible-header').on('click', function() {
		var $body = $(this).next('.wcpg-collapsible-body');
		var $icon = $(this).find('.wcpg-collapse-icon');
		$body.slideToggle(200);
		$icon.toggleClass('wcpg-collapsed');
	});

	// --- E-Transfer tab save button ---
	$('#wcpg-etransfer-save-btn').on('click', function() {
		var $btn = $(this);
		var $status = $('#wcpg-etransfer-save-status');

		$btn.prop('disabled', true).text('Saving...');
		$status.text('');

		// Sync TinyMCE editors before collecting form data.
		if (typeof tinyMCE !== 'undefined') {
			tinyMCE.triggerSave();
		}

		var formData = $('#wcpg-etransfer-settings-form input, #wcpg-etransfer-settings-form select, #wcpg-etransfer-settings-form textarea').serialize();

		$.post(window.location.href, formData, function() {
			$btn.prop('disabled', false).text(wcpgAdminSettings.saveETransferLabel);
			$status.html('<span style="color: green;">\u2713 Saved</span>');
			setTimeout(function() { $status.text(''); }, 3000);
		}).fail(function() {
			$btn.prop('disabled', false).text(wcpgAdminSettings.saveETransferLabel);
			$status.html('<span style="color: red;">Save failed. Please try again.</span>');
		});
	});

	// --- Initialize select2 on multiselect fields ---
	$('#wcpg-crypto-settings-form select[multiple]').each(function() {
		if ($.fn.select2) {
			$(this).select2({ width: '350px' });
		}
	});

	// --- Crypto tab save button ---
	$('#wcpg-crypto-save-btn').on('click', function() {
		var $btn = $(this);
		var $status = $('#wcpg-crypto-save-status');

		$btn.prop('disabled', true).text('Saving...');
		$status.text('');

		var formData = $('#wcpg-crypto-settings-form input, #wcpg-crypto-settings-form select, #wcpg-crypto-settings-form textarea').serialize();

		$.post(window.location.href, formData, function() {
			$btn.prop('disabled', false).text(wcpgAdminSettings.saveCryptoLabel);
			$status.html('<span style="color: green;">\u2713 Saved</span>');
			setTimeout(function() { $status.text(''); }, 3000);
		}).fail(function() {
			$btn.prop('disabled', false).text(wcpgAdminSettings.saveCryptoLabel);
			$status.html('<span style="color: red;">Save failed. Please try again.</span>');
		});
	});

	// --- E-Transfer field visibility based on delivery method ---
	if (typeof wcpgAdminSettings !== 'undefined' && wcpgAdminSettings.etransferGatewayId) {
		var gwId = wcpgAdminSettings.etransferGatewayId;
		var $deliveryMethod = $('#woocommerce_' + gwId + '_delivery_method');
		var $enableManual = $('#woocommerce_' + gwId + '_enable_manual');
		var $descriptionApi = $('#woocommerce_' + gwId + '_description_api');

		var emailDefault = wcpgAdminSettings.etransferEmailDefault;
		var urlDefault = wcpgAdminSettings.etransferUrlDefault;

		function toggleETransferSections() {
			var method = $deliveryMethod.val();
			var manualEnabled = $enableManual.val() === 'yes';

			// Hide all delivery-method-specific fields first.
			$('.etransfer-delivery-email, .etransfer-delivery-url, .etransfer-delivery-manual').closest('tr').hide();

			// Show fields for the selected delivery method (email or url).
			if (method === 'email' || method === 'url') {
				$('.etransfer-delivery-' + method).closest('tr').show();
				$('.etransfer-delivery-email.etransfer-delivery-url').closest('tr').show();
			}

			// Show manual fields if the checkbox is enabled.
			if (manualEnabled) {
				$('.etransfer-delivery-manual').closest('tr').show();
			}

			// Pre-fill description_api based on delivery method.
			if ($descriptionApi.length) {
				var currentVal = $descriptionApi.val();
				if (method === 'email' && (currentVal === '' || currentVal === urlDefault)) {
					$descriptionApi.val(emailDefault);
				} else if (method === 'url' && (currentVal === '' || currentVal === emailDefault)) {
					$descriptionApi.val(urlDefault);
				}
			}

			// Section-level visibility.
			var requestMoneyActive = (method === 'email' || method === 'url');
			$('.wcpg-collapsible-section[data-section="request_money"]').toggle(requestMoneyActive);
			$('.wcpg-collapsible-section[data-section="send_money"]').toggle(manualEnabled);
			$('.wcpg-collapsible-section[data-section="api"]').toggle(requestMoneyActive || manualEnabled);
		}

		$deliveryMethod.on('change', toggleETransferSections);
		$enableManual.on('change', toggleETransferSections);
		toggleETransferSections();
	}
});
