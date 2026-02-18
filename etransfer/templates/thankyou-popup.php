<?php
/**
 * E-Transfer Thank You Page Template
 *
 * Displays payment completion UI based on delivery method.
 *
 * @package DigipayMasterPlugin
 * @since 12.7.0
 *
 * @var WC_Order $order       Order object.
 * @var string   $method      Delivery method (email, url, manual).
 * @var string   $popup_title Popup title (email method).
 * @var string   $popup_body  Popup body text (email method).
 * @var string   $payment_url Payment URL (url method).
 * @var string   $button_text Button text (url method).
 */

defined( 'ABSPATH' ) || exit;

$method = isset( $method ) ? $method : 'email';
?>

<?php if ( 'email' === $method ) : ?>
	<?php
	// Use static variable to prevent duplicates across multiple includes.
	static $popup_rendered = false;
	if ( $popup_rendered ) {
		return;
	}
	$popup_rendered = true;

	$the_popup_title = isset( $popup_title ) ? $popup_title : __( 'Complete Your Payment', 'wc-payment-gateway' );
	$the_popup_body  = isset( $popup_body ) ? $popup_body : __( 'A payment link has been sent to your email.', 'wc-payment-gateway' );
	?>
	<div class="wcpg-etransfer-instructions" style="background:#f8f9fa;border:1px solid #e9ecef;border-left:4px solid #28a745;border-radius:4px;padding:20px;margin:20px 0;">
		<h3 style="margin:0 0 15px;font-size:18px;"><?php echo esc_html( $the_popup_title ); ?></h3>
		<p style="margin:0;font-size:14px;line-height:1.5;"><?php echo wp_kses_post( $the_popup_body ); ?></p>
	</div>
	<?php

	// Output popup in footer to ensure it's outside any containers.
	add_action( 'wp_footer', function() use ( $the_popup_title, $the_popup_body ) {
		static $footer_popup_done = false;
		if ( $footer_popup_done ) return;
		$footer_popup_done = true;
		?>
		<div class="wcpg-etransfer-popup" id="wcpg-etransfer-modal" style="position:fixed;top:0;left:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;z-index:999999;background:rgba(0,0,0,0.6);">
			<div style="background:#fff;padding:40px;border-radius:8px;max-width:500px;width:90%;position:relative;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.2);">
				<button type="button" id="wcpg-modal-close" style="position:absolute;top:10px;right:10px;width:32px;height:32px;font-size:24px;font-weight:bold;background:#f0f0f0;border:none;border-radius:4px;cursor:pointer;color:#333;line-height:32px;padding:0;">&times;</button>
				<h3 style="margin:0 0 15px;font-size:22px;"><?php echo esc_html( $the_popup_title ); ?></h3>
				<p style="margin:0;font-size:16px;line-height:1.5;"><?php echo wp_kses_post( $the_popup_body ); ?></p>
			</div>
		</div>
		<script>
		jQuery(function($){
			$('#wcpg-modal-close, #wcpg-etransfer-modal').on('click', function(e){
				if(e.target.id === 'wcpg-etransfer-modal' || e.target.id === 'wcpg-modal-close'){
					$('#wcpg-etransfer-modal').hide();
				}
			});
		});
		</script>
		<?php
	}, 99 );
	?>

<?php elseif ( 'url' === $method ) : ?>
	<?php
	// Prevent duplicate output.
	static $url_rendered = false;
	if ( $url_rendered ) {
		return;
	}
	$url_rendered = true;

	$the_payment_url = ! empty( $payment_url ) ? $payment_url : '';
	$the_button_text = isset( $button_text ) ? $button_text : __( 'Complete Payment', 'wc-payment-gateway' );
	$has_url = ! empty( $the_payment_url );
	?>
	<div class="wcpg-etransfer-instructions" style="background:#f8f9fa;border:1px solid #e9ecef;border-left:4px solid #28a745;border-radius:4px;padding:20px;margin:20px 0;">
		<h3 style="margin:0 0 15px;font-size:18px;"><?php esc_html_e( 'Complete Your Payment', 'wc-payment-gateway' ); ?></h3>
		<?php if ( $has_url ) : ?>
			<p style="margin:0 0 15px;font-size:14px;line-height:1.5;"><?php esc_html_e( 'Click the button below to complete your payment:', 'wc-payment-gateway' ); ?></p>
			<a href="<?php echo esc_url( $the_payment_url ); ?>" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:12px 30px;font-size:16px;font-weight:bold;text-decoration:none;background:#0071a1;color:#fff;border-radius:4px;"><?php echo esc_html( $the_button_text ); ?></a>
		<?php else : ?>
			<p style="margin:0;font-size:14px;line-height:1.5;color:#856404;"><?php esc_html_e( 'Your payment link is being prepared. Please check your email or refresh this page in a moment.', 'wc-payment-gateway' ); ?></p>
		<?php endif; ?>
	</div>
	<?php

	// Output popup in footer.
	add_action( 'wp_footer', function() use ( $the_payment_url, $the_button_text, $has_url ) {
		static $footer_url_done = false;
		if ( $footer_url_done ) return;
		$footer_url_done = true;
		?>
		<div class="wcpg-etransfer-popup" id="wcpg-etransfer-modal" style="position:fixed;top:0;left:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;z-index:999999;background:rgba(0,0,0,0.6);">
			<div style="background:#fff;padding:40px;border-radius:8px;max-width:500px;width:90%;position:relative;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,0.2);">
				<button type="button" id="wcpg-modal-close" style="position:absolute;top:10px;right:10px;width:32px;height:32px;font-size:24px;font-weight:bold;background:#f0f0f0;border:none;border-radius:4px;cursor:pointer;color:#333;line-height:32px;padding:0;">&times;</button>
				<h3 style="margin:0 0 15px;font-size:22px;"><?php esc_html_e( 'Complete Your Payment', 'wc-payment-gateway' ); ?></h3>
				<?php if ( $has_url ) : ?>
					<p style="margin:0 0 20px;font-size:16px;line-height:1.5;"><?php esc_html_e( 'Click the button below to complete your payment:', 'wc-payment-gateway' ); ?></p>
					<a href="<?php echo esc_url( $the_payment_url ); ?>" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:15px 40px;font-size:18px;font-weight:bold;text-decoration:none;background:#0071a1;color:#fff;border-radius:4px;"><?php echo esc_html( $the_button_text ); ?></a>
				<?php else : ?>
					<p style="margin:0;font-size:16px;line-height:1.5;color:#856404;"><?php esc_html_e( 'Your payment link is being prepared. Please check your email or refresh this page in a moment.', 'wc-payment-gateway' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<script>
		jQuery(function($){
			$('#wcpg-modal-close, #wcpg-etransfer-modal').on('click', function(e){
				if(e.target.id === 'wcpg-etransfer-modal' || e.target.id === 'wcpg-modal-close'){
					$('#wcpg-etransfer-modal').hide();
				}
			});
		});
		</script>
		<?php
	}, 99 );
	?>

<?php endif; ?>
