<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Gateway_ETransfer_Email extends WC_Gateway_ETransfer_Base {
	public function __construct() {
		$this->id          = 'digipay_etransfer_email';
		$this->has_fields  = true;
		$this->enabled     = $this->get_master_setting( 'enabled', 'no' );
		$this->title       = $this->get_master_setting( 'title_api', __( 'Interac e-Transfer (Request Money)', self::TEXT_DOMAIN ) );
		$this->description = $this->get_master_setting( 'description_api', __( 'Pay securely via Interac e-Transfer. A payment link will be sent to your email.', self::TEXT_DOMAIN ) );
	}

	public function get_delivery_method() {
		return self::DELIVERY_EMAIL;
	}
}
