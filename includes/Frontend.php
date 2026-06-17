<?php
namespace HP_PDFI;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'HP_PDFI\Frontend' ) ) :

class Frontend {

	public function __construct() {
		// Add "Invoice" button to My Account -> Orders
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_my_account_invoice_button' ), 10, 2 );
	}

	/**
	 * Add Invoice button to My Account orders list
	 */
	public function add_my_account_invoice_button( $actions, $order ) {
		// Only show for orders that are paid or completed (or based on status)
		// Usually we show it for all except cancelled/failed
		if ( $order->has_status( array( 'pending', 'failed', 'cancelled' ) ) ) {
			return $actions;
		}

		$order_id = $order->get_id();
		// Route through admin-post.php, NOT admin.php: WooCommerce's
		// prevent_admin_access redirects customer-role users out of wp-admin
		// (to an empty My Account) before our admin_init handler can run, so the
		// admin.php URL silently fails for customers. admin-post.php is exempt
		// from that redirect and fires our admin_post_hp_pdfi_generate handler,
		// whose own permission check already allows the order owner.
		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'                  => 'hp_pdfi_generate', // fires admin_post_hp_pdfi_generate
					'hp_pdfi_action'          => 'generate',
					'order_id'                => $order_id,
					'hp_pdfi_show_paid_price' => 'no', // Full discount details for customers
					'hp_pdfi_show_images'     => 'yes', // Images for customers
					'from'                    => 'my-account'
				),
				admin_url( 'admin-post.php' )
			),
			'generate_invoice'
		);

		$actions['hp_pdfi_invoice'] = array(
			'url'  => $url,
			'name' => __( 'Invoice', 'hp-pdf-invoices' ),
		);

		return $actions;
	}
}

endif;






























