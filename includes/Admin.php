<?php
/**
 * Admin Class - Handles admin UI and document generation
 * 
 * @package HP_PDF_Invoices
 * @version 1.2.0
 * @author Amnon Manneberg
 */
namespace HP_PDFI;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'HP_PDFI\Admin' ) ) :

class Admin {

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_meta_box_data' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		
		// Add order action for the invoice
		add_filter( 'woocommerce_admin_order_actions', array( $this, 'add_order_listing_actions' ), 10, 2 );
		
		// Handle the document generation request (PDF, DOCX, XLSX)
		add_action( 'admin_init', array( $this, 'handle_document_request' ) );
		add_action( 'admin_post_hp_pdfi_generate', array( $this, 'handle_document_request' ) );
	}

	public function add_meta_boxes() {
		$screens = array(
			'shop_order',
			'edit-shop_order',
			'woocommerce_page_wc-orders',
			'toplevel_page_eao_custom_order_editor_page',
			'admin_page_eao_custom_order_editor_page'
		);

		if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && \wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ) {
			$screens[] = \wc_get_page_screen_id( 'shop-order' );
		}

		foreach ( $screens as $screen ) {
			add_meta_box(
				'hp_pdfi_invoice_box',
				__( 'HP PDF Invoice', 'hp-pdf-invoices' ),
				array( $this, 'render_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	public function render_meta_box( $post_or_order ) {
		$order = ( $post_or_order instanceof \WP_Post ) ? \wc_get_order( $post_or_order->ID ) : $post_or_order;
		$order_id = $order->get_id();

		// Get current values
		$show_paid_price   = $order->get_meta( '_hp_pdfi_show_paid_price' );
		$printer_friendly = $order->get_meta( '_hp_pdfi_printer_friendly' );
		$show_images       = $order->get_meta( '_hp_pdfi_show_images' );

		// Set defaults if not set
		if ( '' === $show_paid_price ) $show_paid_price = 'yes';
		if ( '' === $printer_friendly ) $printer_friendly = 'no';
		if ( '' === $show_images ) $show_images = 'yes';

		// Base URLs for each format
		$base_url = admin_url( 'admin.php?hp_pdfi_action=generate&order_id=' . $order_id );
		$pdf_url  = wp_nonce_url( $base_url . '&format=pdf', 'generate_invoice' );
		$docx_url = wp_nonce_url( $base_url . '&format=docx', 'generate_invoice' );
		$xlsx_url = wp_nonce_url( $base_url . '&format=xlsx', 'generate_invoice' );

		wp_nonce_field( 'hp_pdfi_meta_box', 'hp_pdfi_meta_box_nonce' );
		?>
		<div class="hp-pdfi-meta-box">
			<p>
				<label>
					<input type="checkbox" class="hp-pdfi-option" name="hp_pdfi_show_paid_price" id="hp_pdfi_show_paid_price" value="yes" <?php checked( $show_paid_price, 'yes' ); ?>>
					<?php _e( 'Show only paid price', 'hp-pdf-invoices' ); ?>
				</label>
			</p>
			<p>
				<label>
					<input type="checkbox" class="hp-pdfi-option" name="hp_pdfi_printer_friendly" id="hp_pdfi_printer_friendly" value="yes" <?php checked( $printer_friendly, 'yes' ); ?>>
					<?php _e( 'Printer friendly', 'hp-pdf-invoices' ); ?>
				</label>
			</p>
			<p>
				<label>
					<input type="checkbox" class="hp-pdfi-option" name="hp_pdfi_show_images" id="hp_pdfi_show_images" value="yes" <?php checked( $show_images, 'yes' ); ?>>
					<?php _e( 'Show images', 'hp-pdf-invoices' ); ?>
				</label>
			</p>
			<hr>
			<p style="margin-bottom: 8px;">
				<strong><?php _e( 'Export Invoice:', 'hp-pdf-invoices' ); ?></strong>
			</p>
			<p class="hp-pdfi-export-buttons" style="display: flex; gap: 5px; flex-wrap: wrap;">
				<a href="<?php echo esc_url( $pdf_url ); ?>" id="hp-pdfi-pdf-btn" class="button button-primary" target="_blank" title="<?php esc_attr_e( 'Download PDF', 'hp-pdf-invoices' ); ?>">
					<span class="dashicons dashicons-pdf" style="margin-top: 3px;"></span> PDF
				</a>
				<a href="<?php echo esc_url( $docx_url ); ?>" id="hp-pdfi-docx-btn" class="button" target="_blank" title="<?php esc_attr_e( 'Download Word Document', 'hp-pdf-invoices' ); ?>">
					<span class="dashicons dashicons-media-document" style="margin-top: 3px;"></span> DOCX
				</a>
				<a href="<?php echo esc_url( $xlsx_url ); ?>" id="hp-pdfi-xlsx-btn" class="button" target="_blank" title="<?php esc_attr_e( 'Download Excel Spreadsheet', 'hp-pdf-invoices' ); ?>">
					<span class="dashicons dashicons-media-spreadsheet" style="margin-top: 3px;"></span> Excel
				</a>
			</p>
		</div>
		<script>
		(function($) {
			function updateLinks() {
				var params = {
					'hp_pdfi_show_paid_price': $('#hp_pdfi_show_paid_price').is(':checked') ? 'yes' : 'no',
					'hp_pdfi_printer_friendly': $('#hp_pdfi_printer_friendly').is(':checked') ? 'yes' : 'no',
					'hp_pdfi_show_images': $('#hp_pdfi_show_images').is(':checked') ? 'yes' : 'no'
				};

				$('#hp-pdfi-pdf-btn, #hp-pdfi-docx-btn, #hp-pdfi-xlsx-btn').each(function() {
					var $btn = $(this);
					var url = new URL($btn.attr('href'));
					$.each(params, function(key, value) {
						url.searchParams.set(key, value);
					});
					$btn.attr('href', url.toString());
				});
			}
			$('.hp-pdfi-option').on('change', updateLinks);
			updateLinks();
		})(jQuery);
		</script>
		<style>
		.hp-pdfi-export-buttons .button .dashicons {
			font-size: 16px;
			width: 16px;
			height: 16px;
		}
		</style>
		<?php
	}

	public function save_meta_box_data( $order_id ) {
		// Nonce check
		if ( ! isset( $_POST['hp_pdfi_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['hp_pdfi_meta_box_nonce'], 'hp_pdfi_meta_box' ) ) {
			return;
		}

		$order = \wc_get_order( $order_id );
		if ( ! $order ) return;

		// Save meta data
		$order->update_meta_data( '_hp_pdfi_show_paid_price', isset( $_POST['hp_pdfi_show_paid_price'] ) ? 'yes' : 'no' );
		$order->update_meta_data( '_hp_pdfi_printer_friendly', isset( $_POST['hp_pdfi_printer_friendly'] ) ? 'yes' : 'no' );
		$order->update_meta_data( '_hp_pdfi_show_images', isset( $_POST['hp_pdfi_show_images'] ) ? 'yes' : 'no' );
		$order->save_meta_data();
	}

	public function add_settings_page() {
		add_submenu_page(
			'tools.php',
			__( 'HP PDF Invoices', 'hp-pdf-invoices' ),
			__( 'HP Invoices', 'hp-pdf-invoices' ),
			'manage_options',
			'hp-pdf-invoices',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'hp_pdfi_settings', 'hp_pdfi_logo' );
		register_setting( 'hp_pdfi_settings', 'hp_pdfi_shop_name' );
		register_setting( 'hp_pdfi_settings', 'hp_pdfi_shop_address' );
		register_setting( 'hp_pdfi_settings', 'hp_pdfi_invoice_prefix' );
	}

	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php _e( 'HP PDF Invoices Settings', 'hp-pdf-invoices' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'hp_pdfi_settings' );
				do_settings_sections( 'hp_pdfi_settings' );
				?>
				<table class="form-table">
					<tr>
						<th scope="row"><?php _e( 'Logo Attachment ID', 'hp-pdf-invoices' ); ?></th>
						<td><input type="number" name="hp_pdfi_logo" value="<?php echo esc_attr( get_option( 'hp_pdfi_logo' ) ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><?php _e( 'Shop Name', 'hp-pdf-invoices' ); ?></th>
						<td><input type="text" name="hp_pdfi_shop_name" value="<?php echo esc_attr( get_option( 'hp_pdfi_shop_name' ) ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><?php _e( 'Shop Address', 'hp-pdf-invoices' ); ?></th>
						<td><textarea name="hp_pdfi_shop_address" rows="5" class="large-text"><?php echo esc_textarea( get_option( 'hp_pdfi_shop_address' ) ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php _e( 'Invoice Prefix', 'hp-pdf-invoices' ); ?></th>
						<td><input type="text" name="hp_pdfi_invoice_prefix" value="<?php echo esc_attr( get_option( 'hp_pdfi_invoice_prefix' ) ); ?>" class="regular-text"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function add_order_listing_actions( $actions, $order ) {
		$actions['hp_pdfi_invoice'] = array(
			'url'    => wp_nonce_url( admin_url( 'admin.php?hp_pdfi_action=generate&order_id=' . $order->get_id() ), 'generate_invoice' ),
			'name'   => __( 'PDF Invoice', 'hp-pdf-invoices' ),
			'action' => 'hp-pdfi-invoice',
		);
		return $actions;
	}

	/**
	 * Handle document generation request (PDF, DOCX, XLSX)
	 */
	public function handle_document_request() {
		$action = isset( $_GET['hp_pdfi_action'] ) ? sanitize_text_field( $_GET['hp_pdfi_action'] ) : ( isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '' );
		if ( 'generate' !== $action && 'hp_pdfi_generate' !== $action ) {
			return;
		}

		if ( ! isset( $_GET['order_id'] ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( $_GET['_wpnonce'] ), 'generate_invoice' ) ) {
			return;
		}

		$order_id = absint( $_GET['order_id'] );
		$order = \wc_get_order( $order_id );

		if ( ! $order ) {
			wp_die( __( 'Invalid order.', 'hp-pdf-invoices' ) );
		}

		// Permission check: admin OR order owner
		$user_id = get_current_user_id();
		$can_edit = current_user_can( 'manage_woocommerce_orders' ) || current_user_can( 'edit_shop_orders' );
		$is_owner = $user_id && (int) $user_id === (int) $order->get_customer_id();

		if ( ! $can_edit && ! $is_owner ) {
			wp_die( __( 'You do not have permission to view this invoice.', 'hp-pdf-invoices' ) );
		}

		// Determine format (default to PDF)
		$format = isset( $_GET['format'] ) ? sanitize_text_field( $_GET['format'] ) : 'pdf';
		$allowed_formats = array( 'pdf', 'docx', 'xlsx' );
		
		if ( ! in_array( $format, $allowed_formats, true ) ) {
			$format = 'pdf';
		}

		$invoice = new Invoice( $order );

		switch ( $format ) {
			case 'docx':
				$invoice->output_docx();
				break;
			case 'xlsx':
				$invoice->output_xlsx();
				break;
			case 'pdf':
			default:
				$invoice->output_pdf();
				break;
		}

		exit;
	}

	/**
	 * Legacy method name for backwards compatibility
	 * @deprecated Use handle_document_request() instead
	 */
	public function handle_pdf_request() {
		$this->handle_document_request();
	}
}

endif;

