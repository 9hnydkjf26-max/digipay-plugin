<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Gateway_ETransfer_Manual extends WC_Gateway_ETransfer_Base {
	public function __construct() {
		$this->id          = 'digipay_etransfer_manual';
		$this->has_fields  = true;
		$this->enabled     = $this->get_master_setting( 'enabled', 'no' );
		$this->title       = $this->get_master_setting( 'title_manual', __( 'Interac e-Transfer (Send Money)', self::TEXT_DOMAIN ) );
		$this->description = $this->get_master_setting( 'description_manual', __( 'Pay securely via Interac e-Transfer. Send money using the provided instructions.', self::TEXT_DOMAIN ) );
	}

	public function get_delivery_method() {
		return self::DELIVERY_MANUAL;
	}
}
