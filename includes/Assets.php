<?php
namespace HP_PDFI;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Assets {

	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	public function enqueue_admin_assets() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' ) ) ) {
			return;
		}

		wp_register_style( 'hp-pdfi-admin', HP_PDFI_URL . 'assets/css/admin.css', array(), HP_PDFI_VERSION );
		wp_enqueue_style( 'hp-pdfi-admin' );
	}
}

