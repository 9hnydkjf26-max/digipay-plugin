<?php
/**
 * Tests for Fingerprint checkout integration.
 *
 * Tests the enqueue_fingerprint_checkout method behavior.
 *
 * @package Digipay
 */

require_once __DIR__ . '/DigipayTestCase.php';

/**
 * Test class for Fingerprint integration.
 */
class FingerprintIntegrationTest extends DigipayTestCase {

	/**
	 * Test that Fingerprint public key constant is defined.
	 */
	public function test_fingerprint_public_key_constant_defined() {
		$this->assertTrue(
			defined( 'WCPG_FINGERPRINT_PUBLIC_KEY' ),
			'WCPG_FINGERPRINT_PUBLIC_KEY constant should be defined'
		);
	}

	/**
	 * Test that Fingerprint region constant is defined.
	 */
	public function test_fingerprint_region_constant_defined() {
		$this->assertTrue(
			defined( 'WCPG_FINGERPRINT_REGION' ),
			'WCPG_FINGERPRINT_REGION constant should be defined'
		);
	}

	/**
	 * Test that enqueue_fingerprint_checkout method exists on gateway class.
	 */
	public function test_enqueue_fingerprint_checkout_method_exists() {
		$plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
		$plugin_content = file_get_contents( $plugin_file );
		
		$this->assertStringContainsString(
			'function enqueue_fingerprint_checkout',
			$plugin_content,
			'Plugin should define enqueue_fingerprint_checkout method'
		);
	}

	/**
	 * Test that fingerprint script file path is correct in plugin.
	 */
	public function test_fingerprint_script_path_defined() {
		$plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
		$plugin_content = file_get_contents( $plugin_file );
		
		$this->assertStringContainsString(
			'assets/js/fingerprint-checkout.js',
			$plugin_content,
			'Plugin should reference fingerprint-checkout.js asset'
		);
	}

	/**
	 * Test that enqueue_fingerprint_checkout method body contains wp_enqueue_script.
	 */
	public function test_fingerprint_method_contains_enqueue_call() {
		$plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
		$plugin_content = file_get_contents( $plugin_file );

		// Extract the method body using brace matching
		$start = strpos( $plugin_content, 'function enqueue_fingerprint_checkout()' );
		$this->assertNotFalse( $start, 'Should find method' );

		$brace_start = strpos( $plugin_content, '{', $start );
		$depth = 1;
		$pos = $brace_start + 1;
		while ( $depth > 0 && $pos < strlen( $plugin_content ) ) {
			if ( $plugin_content[$pos] === '{' ) $depth++;
			if ( $plugin_content[$pos] === '}' ) $depth--;
			$pos++;
		}
		$method_body = substr( $plugin_content, $brace_start, $pos - $brace_start );

		$this->assertStringContainsString(
			'wp_enqueue_script',
			$method_body,
			'enqueue_fingerprint_checkout method should call wp_enqueue_script'
		);
	}


	/**
	 * Test that enqueue_fingerprint_checkout registers script with correct path.
	 */
	public function test_fingerprint_method_registers_script_with_path() {
		$plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
		$plugin_content = file_get_contents( $plugin_file );
		
		// Extract the method body (allowing for nested braces)
		$start = strpos( $plugin_content, 'function enqueue_fingerprint_checkout()' );
		$this->assertNotFalse( $start, 'Should find method' );
		
		// Find the opening brace and extract until matching close
		$brace_start = strpos( $plugin_content, '{', $start );
		$depth = 1;
		$pos = $brace_start + 1;
		while ( $depth > 0 && $pos < strlen( $plugin_content ) ) {
			if ( $plugin_content[$pos] === '{' ) $depth++;
			if ( $plugin_content[$pos] === '}' ) $depth--;
			$pos++;
		}
		$method_body = substr( $plugin_content, $brace_start, $pos - $brace_start );
		
		$this->assertStringContainsString(
			'fingerprint-checkout.js',
			$method_body,
			'enqueue_fingerprint_checkout should register script with fingerprint-checkout.js path'
		);
	}


	/**
	 * Test that wp_enqueue_script is called with proper URL parameter.
	 */
	public function test_fingerprint_method_has_script_url_param() {
		$plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
		$plugin_content = file_get_contents( $plugin_file );
		
		// Extract the method body
		$start = strpos( $plugin_content, 'function enqueue_fingerprint_checkout()' );
		$brace_start = strpos( $plugin_content, '{', $start );
		$depth = 1;
		$pos = $brace_start + 1;
		while ( $depth > 0 && $pos < strlen( $plugin_content ) ) {
			if ( $plugin_content[$pos] === '{' ) $depth++;
			if ( $plugin_content[$pos] === '}' ) $depth--;
			$pos++;
		}
		$method_body = substr( $plugin_content, $brace_start, $pos - $brace_start );
		
		$this->assertStringContainsString(
			'plugin_dir_url',
			$method_body,
			'enqueue_fingerprint_checkout should use plugin_dir_url for script URL'
		);
	}


	/**
	 * Test that wp_localize_script is called for config data.
	 */
	public function test_fingerprint_method_localizes_script() {
		$plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
		$plugin_content = file_get_contents( $plugin_file );
		
		// Extract the method body
		$start = strpos( $plugin_content, 'function enqueue_fingerprint_checkout()' );
		$brace_start = strpos( $plugin_content, '{', $start );
		$depth = 1;
		$pos = $brace_start + 1;
		while ( $depth > 0 && $pos < strlen( $plugin_content ) ) {
			if ( $plugin_content[$pos] === '{' ) $depth++;
			if ( $plugin_content[$pos] === '}' ) $depth--;
			$pos++;
		}
		$method_body = substr( $plugin_content, $brace_start, $pos - $brace_start );
		
		$this->assertStringContainsString(
			'wp_localize_script',
			$method_body,
			'enqueue_fingerprint_checkout should call wp_localize_script for config'
		);
	}


	/**
	 * Test that config includes Fingerprint API key.
	 */
	public function test_fingerprint_config_includes_key() {
		$plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
		$plugin_content = file_get_contents( $plugin_file );
		
		// Extract the method body
		$start = strpos( $plugin_content, 'function enqueue_fingerprint_checkout()' );
		$brace_start = strpos( $plugin_content, '{', $start );
		$depth = 1;
		$pos = $brace_start + 1;
		while ( $depth > 0 && $pos < strlen( $plugin_content ) ) {
			if ( $plugin_content[$pos] === '{' ) $depth++;
			if ( $plugin_content[$pos] === '}' ) $depth--;
			$pos++;
		}
		$method_body = substr( $plugin_content, $brace_start, $pos - $brace_start );
		
		$this->assertStringContainsString(
			'WCPG_FINGERPRINT_PUBLIC_KEY',
			$method_body,
			'Config should include Fingerprint API key constant'
		);
	}


	/**
	 * Test that config includes Fingerprint region.
	 */
	public function test_fingerprint_config_includes_region() {
		$plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
		$plugin_content = file_get_contents( $plugin_file );
		
		// Extract the method body
		$start = strpos( $plugin_content, 'function enqueue_fingerprint_checkout()' );
		$brace_start = strpos( $plugin_content, '{', $start );
		$depth = 1;
		$pos = $brace_start + 1;
		while ( $depth > 0 && $pos < strlen( $plugin_content ) ) {
			if ( $plugin_content[$pos] === '{' ) $depth++;
			if ( $plugin_content[$pos] === '}' ) $depth--;
			$pos++;
		}
		$method_body = substr( $plugin_content, $brace_start, $pos - $brace_start );
		
		$this->assertStringContainsString(
			'WCPG_FINGERPRINT_REGION',
			$method_body,
			'Config should include Fingerprint region constant'
		);
	}


	/**
	 * Test that config includes site ID.
	 */
	public function test_fingerprint_config_includes_site_id() {
		$plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
		$plugin_content = file_get_contents( $plugin_file );
		
		// Extract the method body
		$start = strpos( $plugin_content, 'function enqueue_fingerprint_checkout()' );
		$brace_start = strpos( $plugin_content, '{', $start );
		$depth = 1;
		$pos = $brace_start + 1;
		while ( $depth > 0 && $pos < strlen( $plugin_content ) ) {
			if ( $plugin_content[$pos] === '{' ) $depth++;
			if ( $plugin_content[$pos] === '}' ) $depth--;
			$pos++;
		}
		$method_body = substr( $plugin_content, $brace_start, $pos - $brace_start );
		
		$this->assertStringContainsString(
			'siteId',
			$method_body,
			'Config should include siteId'
		);
	}


	/**
	 * Test that config includes site name.
	 */
	public function test_fingerprint_config_includes_site_name() {
		$plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
		$plugin_content = file_get_contents( $plugin_file );
		
		// Extract the method body
		$start = strpos( $plugin_content, 'function enqueue_fingerprint_checkout()' );
		$brace_start = strpos( $plugin_content, '{', $start );
		$depth = 1;
		$pos = $brace_start + 1;
		while ( $depth > 0 && $pos < strlen( $plugin_content ) ) {
			if ( $plugin_content[$pos] === '{' ) $depth++;
			if ( $plugin_content[$pos] === '}' ) $depth--;
			$pos++;
		}
		$method_body = substr( $plugin_content, $brace_start, $pos - $brace_start );
		
		$this->assertStringContainsString(
			'siteName',
			$method_body,
			'Config should include siteName'
		);
	}


	/**
	 * Test that config includes cart total.
	 */
	public function test_fingerprint_config_includes_cart_total() {
		$plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
		$plugin_content = file_get_contents( $plugin_file );
		
		// Extract the method body
		$start = strpos( $plugin_content, 'function enqueue_fingerprint_checkout()' );
		$brace_start = strpos( $plugin_content, '{', $start );
		$depth = 1;
		$pos = $brace_start + 1;
		while ( $depth > 0 && $pos < strlen( $plugin_content ) ) {
			if ( $plugin_content[$pos] === '{' ) $depth++;
			if ( $plugin_content[$pos] === '}' ) $depth--;
			$pos++;
		}
		$method_body = substr( $plugin_content, $brace_start, $pos - $brace_start );
		
		$this->assertStringContainsString(
			'cartTotal',
			$method_body,
			'Config should include cartTotal'
		);
	}


	/**
	 * Test that config includes cart item count.
	 */
	public function test_fingerprint_config_includes_cart_item_count() {
		$plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
		$plugin_content = file_get_contents( $plugin_file );
		
		$start = strpos( $plugin_content, 'function enqueue_fingerprint_checkout()' );
		$brace_start = strpos( $plugin_content, '{', $start );
		$depth = 1;
		$pos = $brace_start + 1;
		while ( $depth > 0 && $pos < strlen( $plugin_content ) ) {
			if ( $plugin_content[$pos] === '{' ) $depth++;
			if ( $plugin_content[$pos] === '}' ) $depth--;
			$pos++;
		}
		$method_body = substr( $plugin_content, $brace_start, $pos - $brace_start );
		
		$this->assertStringContainsString(
			'cartItemCount',
			$method_body,
			'Config should include cartItemCount'
		);
	}


	/**
	 * Test that config includes currency.
	 */
	public function test_fingerprint_config_includes_currency() {
		$plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
		$plugin_content = file_get_contents( $plugin_file );
		
		$start = strpos( $plugin_content, 'function enqueue_fingerprint_checkout()' );
		$brace_start = strpos( $plugin_content, '{', $start );
		$depth = 1;
		$pos = $brace_start + 1;
		while ( $depth > 0 && $pos < strlen( $plugin_content ) ) {
			if ( $plugin_content[$pos] === '{' ) $depth++;
			if ( $plugin_content[$pos] === '}' ) $depth--;
			$pos++;
		}
		$method_body = substr( $plugin_content, $brace_start, $pos - $brace_start );
		
		$this->assertStringContainsString(
			'currency',
			$method_body,
			'Config should include currency'
		);
	}


	/**
	 * Test that cart data is fetched from WC cart.
	 */
	public function test_fingerprint_method_uses_wc_cart() {
		$plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
		$plugin_content = file_get_contents( $plugin_file );
		
		$start = strpos( $plugin_content, 'function enqueue_fingerprint_checkout()' );
		$brace_start = strpos( $plugin_content, '{', $start );
		$depth = 1;
		$pos = $brace_start + 1;
		while ( $depth > 0 && $pos < strlen( $plugin_content ) ) {
			if ( $plugin_content[$pos] === '{' ) $depth++;
			if ( $plugin_content[$pos] === '}' ) $depth--;
			$pos++;
		}
		$method_body = substr( $plugin_content, $brace_start, $pos - $brace_start );
		
		$this->assertStringContainsString(
			'WC()->cart',
			$method_body,
			'Method should get cart data from WC()->cart'
		);
	}


	/**
	 * Test that method checks for checkout page.
	 */
	public function test_fingerprint_method_checks_checkout() {
		$plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
		$plugin_content = file_get_contents( $plugin_file );
		
		$start = strpos( $plugin_content, 'function enqueue_fingerprint_checkout()' );
		$brace_start = strpos( $plugin_content, '{', $start );
		$depth = 1;
		$pos = $brace_start + 1;
		while ( $depth > 0 && $pos < strlen( $plugin_content ) ) {
			if ( $plugin_content[$pos] === '{' ) $depth++;
			if ( $plugin_content[$pos] === '}' ) $depth--;
			$pos++;
		}
		$method_body = substr( $plugin_content, $brace_start, $pos - $brace_start );
		
		$this->assertStringContainsString(
			'is_checkout',
			$method_body,
			'Method should check if on checkout page'
		);
	}


	/**
	 * Test that method skips if API key is placeholder.
	 */
	public function test_fingerprint_method_checks_api_key() {
		$plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
		$plugin_content = file_get_contents( $plugin_file );
		
		$start = strpos( $plugin_content, 'function enqueue_fingerprint_checkout()' );
		$brace_start = strpos( $plugin_content, '{', $start );
		$depth = 1;
		$pos = $brace_start + 1;
		while ( $depth > 0 && $pos < strlen( $plugin_content ) ) {
			if ( $plugin_content[$pos] === '{' ) $depth++;
			if ( $plugin_content[$pos] === '}' ) $depth--;
			$pos++;
		}
		$method_body = substr( $plugin_content, $brace_start, $pos - $brace_start );
		
		$this->assertStringContainsString(
			'your_public_api_key_here',
			$method_body,
			'Method should check if API key is still placeholder'
		);
	}


	/**
	 * Test that script has jquery dependency.
	 */
	public function test_fingerprint_script_has_jquery_dependency() {
		$plugin_file = dirname( __DIR__ ) . '/woocommerce-gateway-paygo.php';
		$plugin_content = file_get_contents( $plugin_file );
		
		$start = strpos( $plugin_content, 'function enqueue_fingerprint_checkout()' );
		$brace_start = strpos( $plugin_content, '{', $start );
		$depth = 1;
		$pos = $brace_start + 1;
		while ( $depth > 0 && $pos < strlen( $plugin_content ) ) {
			if ( $plugin_content[$pos] === '{' ) $depth++;
			if ( $plugin_content[$pos] === '}' ) $depth--;
			$pos++;
		}
		$method_body = substr( $plugin_content, $brace_start, $pos - $brace_start );
		
		$this->assertStringContainsString(
			"'jquery'",
			$method_body,
			'Script should have jquery as dependency'
		);
	}


	/**
	 * Test that fingerprint-checkout.js file exists.
	 */
	public function test_fingerprint_js_file_exists() {
		$js_file = dirname( __DIR__ ) . '/assets/js/fingerprint-checkout.js';
		$this->assertFileExists(
			$js_file,
			'fingerprint-checkout.js should exist in assets/js/'
		);
	}


	/**
	 * Test that JS file contains jQuery wrapper.
	 */
	public function test_fingerprint_js_has_jquery_wrapper() {
		$js_file = dirname( __DIR__ ) . '/assets/js/fingerprint-checkout.js';
		$js_content = file_get_contents( $js_file );
		
		$this->assertStringContainsString(
			'jQuery',
			$js_content,
			'JS file should use jQuery'
		);
	}


	/**
	 * Test that JS file contains initFingerprint function.
	 */
	public function test_fingerprint_js_has_init_function() {
		$js_file = dirname( __DIR__ ) . '/assets/js/fingerprint-checkout.js';
		$js_content = file_get_contents( $js_file );
		
		$this->assertStringContainsString(
			'initFingerprint',
			$js_content,
			'JS file should have initFingerprint function'
		);
	}


	/**
	 * Test that JS file contains getCheckoutData function.
	 */
	public function test_fingerprint_js_has_get_checkout_data() {
		$js_file = dirname( __DIR__ ) . '/assets/js/fingerprint-checkout.js';
		$js_content = file_get_contents( $js_file );
		
		$this->assertStringContainsString(
			'getCheckoutData',
			$js_content,
			'JS file should have getCheckoutData function'
		);
	}


	/**
	 * Test that JS file contains sendToFingerprint function.
	 */
	public function test_fingerprint_js_has_send_function() {
		$js_file = dirname( __DIR__ ) . '/assets/js/fingerprint-checkout.js';
		$js_content = file_get_contents( $js_file );
		
		$this->assertStringContainsString(
			'sendToFingerprint',
			$js_content,
			'JS file should have sendToFingerprint function'
		);
	}


	/**
	 * Test that JS file contains hashEmail function.
	 */
	public function test_fingerprint_js_has_hash_email() {
		$js_file = dirname( __DIR__ ) . '/assets/js/fingerprint-checkout.js';
		$js_content = file_get_contents( $js_file );
		
		$this->assertStringContainsString(
			'hashEmail',
			$js_content,
			'JS file should have hashEmail function'
		);
	}


	/**
	 * Test that JS file uses document ready.
	 */
	public function test_fingerprint_js_has_document_ready() {
		$js_file = dirname( __DIR__ ) . '/assets/js/fingerprint-checkout.js';
		$js_content = file_get_contents( $js_file );
		
		$this->assertStringContainsString(
			'$(document).ready',
			$js_content,
			'JS file should use document ready'
		);
	}


	/**
	 * Test that JS file imports Fingerprint SDK.
	 */
	public function test_fingerprint_js_imports_sdk() {
		$js_file = dirname( __DIR__ ) . '/assets/js/fingerprint-checkout.js';
		$js_content = file_get_contents( $js_file );
		
		$this->assertStringContainsString(
			'fpjscdn.net',
			$js_content,
			'JS file should import Fingerprint SDK from CDN'
		);
	}


	/**
	 * Test that JS file reads billing email field.
	 */
	public function test_fingerprint_js_reads_billing_email() {
		$js_file = dirname( __DIR__ ) . '/assets/js/fingerprint-checkout.js';
		$js_content = file_get_contents( $js_file );
		
		$this->assertStringContainsString(
			'#billing_email',
			$js_content,
			'JS file should read billing email field'
		);
	}


	/**
	 * Test that JS file uses crypto for hashing.
	 */
	public function test_fingerprint_js_uses_crypto() {
		$js_file = dirname( __DIR__ ) . '/assets/js/fingerprint-checkout.js';
		$js_content = file_get_contents( $js_file );
		
		$this->assertStringContainsString(
			'crypto.subtle',
			$js_content,
			'JS file should use crypto.subtle for hashing'
		);
	}


	/**
	 * Test that JS file calls agent.get.
	 */
	public function test_fingerprint_js_calls_agent_get() {
		$js_file = dirname( __DIR__ ) . '/assets/js/fingerprint-checkout.js';
		$js_content = file_get_contents( $js_file );
		
		$this->assertStringContainsString(
			'agent.get',
			$js_content,
			'JS file should call agent.get'
		);
	}


	/**
	 * Test that JS file hooks checkout form.
	 */
	public function test_fingerprint_js_hooks_checkout_form() {
		$js_file = dirname( __DIR__ ) . '/assets/js/fingerprint-checkout.js';
		$js_content = file_get_contents( $js_file );
		
		$this->assertStringContainsString(
			'form.checkout',
			$js_content,
			'JS file should hook checkout form'
		);
	}
}
