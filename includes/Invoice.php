<?php
/**
 * Invoice Document Class
 * 
 * @package HP_PDF_Invoices
 * @version 1.2.19
 * @author Amnon Manneberg
 */
namespace HP_PDFI;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'HP_PDFI\Invoice' ) ) :

class Invoice {

	/**
	 * @var \WC_Order
	 */
	public $order;

	public $show_paid_price;
	public $printer_friendly;
	public $show_images;

	public function __construct( $order ) {
		$this->order = $order;

		// Load options from order meta, with overrides from $_GET for immediate preview
		// Default to FALSE (show original prices + discount breakdown like EAO)
		$this->show_paid_price   = $order->get_meta( '_hp_pdfi_show_paid_price' ) === 'yes';
		$this->printer_friendly = $order->get_meta( '_hp_pdfi_printer_friendly' ) === 'yes';
		$this->show_images       = $order->get_meta( '_hp_pdfi_show_images' ) !== 'no';

		if ( isset( $_GET['hp_pdfi_show_paid_price'] ) ) {
			$this->show_paid_price = $_GET['hp_pdfi_show_paid_price'] === 'yes';
		}
		if ( isset( $_GET['hp_pdfi_printer_friendly'] ) ) {
			$this->printer_friendly = $_GET['hp_pdfi_printer_friendly'] === 'yes';
		}
		if ( isset( $_GET['hp_pdfi_show_images'] ) ) {
			$this->show_images = $_GET['hp_pdfi_show_images'] === 'yes';
		}
	}

	/**
	 * Output PDF invoice
	 */
	public function output() {
		$this->output_pdf();
	}

	/**
	 * Get sanitized filename base with order number and customer name
	 *
	 * @return string
	 */
	protected function get_filename_base() {
		$order_number = $this->order->get_order_number();
		$customer_name = trim( $this->order->get_billing_first_name() . ' ' . $this->order->get_billing_last_name() );
		
		// Sanitize customer name for filename (remove special chars, replace spaces with dashes)
		$customer_name = sanitize_file_name( $customer_name );
		$customer_name = preg_replace( '/[^a-zA-Z0-9\-_]/', '', $customer_name );
		
		if ( empty( $customer_name ) ) {
			return 'invoice-' . $order_number;
		}
		
		return 'invoice-' . $order_number . '-' . $customer_name;
	}

	/**
	 * Output PDF invoice
	 */
	public function output_pdf() {
		$html = $this->get_html();
		$pdf_maker = new PDFMaker( $html );
		$pdf = $pdf_maker->output();

		if ( $pdf ) {
			$filename = $this->get_filename_base() . '.pdf';
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: inline; filename="' . $filename . '"' );
			echo $pdf;
		}
	}

	/**
	 * Output DOCX invoice
	 */
	public function output_docx() {
		// Clear any previous output that might corrupt the binary
		if ( ob_get_level() ) {
			ob_end_clean();
		}
		
		$docx_maker = new DOCXMaker( $this );
		$content = $docx_maker->output();

		if ( $content && $content !== false ) {
			$filename = $this->get_filename_base() . '.docx';
			
			// Ensure no output has been sent
			if ( headers_sent( $file, $line ) ) {
				error_log( "HP-PDF-Invoices: Headers already sent in $file on line $line" );
				wp_die( 'Error generating DOCX: Headers already sent.' );
			}
			
			header( 'Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Content-Length: ' . strlen( $content ) );
			header( 'Cache-Control: max-age=0' );
			header( 'Pragma: public' );
			
			echo $content;
			exit;
		} else {
			wp_die( 'Error generating DOCX document. Check the error log for details.' );
		}
	}

	/**
	 * Output Excel invoice
	 */
	public function output_xlsx() {
		$excel_maker = new ExcelMaker( $this );
		$content = $excel_maker->output();

		if ( $content ) {
			$filename = $this->get_filename_base() . '.xlsx';
			header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Content-Length: ' . strlen( $content ) );
			header( 'Cache-Control: max-age=0' );
			echo $content;
		}
	}

	public function get_html() {
		ob_start();
		$order = $this->order;
		$invoice = $this;
		include HP_PDFI_PATH . 'templates/invoice.php';
		return ob_get_clean();
	}

	public function get_order_items() {
		$items = $this->order->get_items();
		$data = array();

		foreach ( $items as $item_id => $item ) {
			$product = $item->get_product();
			$line_total = $item->get_total();        // Paid total (after discounts)
			$line_subtotal = $item->get_subtotal();  // Original total (before discounts)
			$quantity = $item->get_quantity();
			
			// Calculate unit prices
			$paid_unit_price = $quantity > 0 ? $line_total / $quantity : 0;
			$original_unit_price = $quantity > 0 ? $line_subtotal / $quantity : 0;
			$has_discount = $line_total < $line_subtotal;

			// Get per-item discount percent from EAO meta (set by HP-Funnels for Special Offers)
			$discount_percent = (float) $item->get_meta( '_eao_item_discount_percent', true );

			$data[] = array(
				'id'               => $item_id,
				'name'             => $item->get_name(),
				'sku'              => $product ? $product->get_sku() : '',
				'quantity'         => $quantity,
				// Unit prices
				'price'            => \wc_price( $paid_unit_price, array( 'currency' => $this->order->get_currency() ) ),
				'original_price'   => \wc_price( $original_unit_price, array( 'currency' => $this->order->get_currency() ) ),
				// Line totals
				'total'            => \wc_price( $line_total, array( 'currency' => $this->order->get_currency() ) ),
				'original_total'   => \wc_price( $line_subtotal, array( 'currency' => $this->order->get_currency() ) ),
				'has_discount'     => $has_discount,
				'discount_percent' => $discount_percent,
				'image'            => $this->show_images ? $this->get_product_image( $product ) : '',
			);
		}

		return $data;
	}

	/**
	 * Clean shipping method name by removing carrier template markers like {{USPS}}
	 *
	 * @param string $method_name
	 * @return string
	 */
	public function clean_shipping_method( $method_name ) {
		return trim( preg_replace( '/\{\{[^}]+\}\}\s*/', '', $method_name ) );
	}

	public function get_product_image( $product ) {
		if ( ! $product ) return '';
		$image_id = $product->get_image_id();
		if ( ! $image_id ) return '';
		
		$image_path = get_attached_file( $image_id );
		if ( ! $image_path || ! file_exists( $image_path ) ) return '';

		// DomPDF needs local path or base64
		$type = pathinfo( $image_path, PATHINFO_EXTENSION );
		$data = file_get_contents( $image_path );
		return 'data:image/' . $type . ';base64,' . base64_encode( $data );
	}

	/**
	 * Get the applied store-credit amount for the order.
	 *
	 * @return float
	 */
	public function get_store_credit_applied() {
		if ( class_exists( '\HP_Core\Services\OrderPaymentDisplay' ) ) {
			return \HP_Core\Services\OrderPaymentDisplay::get_store_credit_applied( $this->order );
		}

		$split = $this->order->get_meta( '_hp_wallet_payment_split', true );
		if ( is_array( $split ) && ! empty( $split['store_credit'] ) ) {
			return round( max( 0, (float) $split['store_credit'] ), wc_get_price_decimals() );
		}

		$credit = (float) ( $this->order->get_meta( '_hp_wallet_credit_applied' ) ?: $this->order->get_meta( '_hp_rw_store_credit_applied' ) );
		if ( $credit > 0 ) {
			return round( $credit, wc_get_price_decimals() );
		}

		$total = 0.0;
		foreach ( $this->order->get_fees() as $fee ) {
			if ( $this->is_store_credit_fee( $fee ) ) {
				$total += abs( (float) $fee->get_total() );
			}
		}

		return round( $total, wc_get_price_decimals() );
	}

	/**
	 * Get the redeemed points count stored on the order.
	 *
	 * @return int
	 */
	public function get_points_redeemed_count() {
		if ( class_exists( '\HP_Core\Services\OrderPaymentDisplay' ) ) {
			$points = \HP_Core\Services\OrderPaymentDisplay::get_points_redeemed( $this->order );
			if ( $points > 0 ) {
				return $points;
			}
			$pending = $this->get_eao_pending_points_intent();
			return $pending['points'];
		}

		$split = $this->order->get_meta( '_hp_wallet_payment_split', true );
		if ( is_array( $split ) && ! empty( $split['points_redeemed'] ) ) {
			return (int) $split['points_redeemed'];
		}

		$points = (int) ( $this->order->get_meta( '_hp_wallet_points_redeemed' ) ?: $this->order->get_meta( '_hp_rw_points_redeemed' ) );
		if ( $points > 0 ) {
			return $points;
		}

		$points = (int) $this->order->get_meta( '_ywpar_coupon_points' );
		if ( $points > 0 ) {
			return $points;
		}

		$pending = $this->get_eao_pending_points_intent();
		return $pending['points'];
	}

	/**
	 * Get the monetary points discount stored on the order.
	 *
	 * @return float
	 */
	public function get_points_discount_amount() {
		if ( class_exists( '\HP_Core\Services\OrderPaymentDisplay' ) ) {
			$amount = \HP_Core\Services\OrderPaymentDisplay::get_points_discount( $this->order );
			if ( $amount > 0 ) {
				return $amount;
			}
			$pending = $this->get_eao_pending_points_intent();
			return $pending['amount'];
		}

		$split = $this->order->get_meta( '_hp_wallet_payment_split', true );
		if ( is_array( $split ) && ! empty( $split['points_discount'] ) ) {
			return round( max( 0, (float) $split['points_discount'] ), wc_get_price_decimals() );
		}

		$points_amount = (float) (
			$this->order->get_meta( '_hp_wallet_points_discount' )
			?: $this->order->get_meta( '_hp_rw_points_discount' )
			?: $this->order->get_meta( '_ywpar_coupon_amount' )
		);
		if ( $points_amount > 0 ) {
			return round( $points_amount, wc_get_price_decimals() );
		}

		foreach ( $this->order->get_fees() as $fee ) {
			if ( $this->is_points_fee( $fee ) ) {
				return round( abs( (float) $fee->get_total() ), wc_get_price_decimals() );
			}
		}

		$pending = $this->get_eao_pending_points_intent();
		return $pending['amount'];
	}

	/**
	 * Get the EAO "items total (gross)" amount.
	 *
	 * @return float
	 */
	public function get_items_total_gross_amount() {
		$items_total = 0.0;

		foreach ( $this->order->get_items() as $item ) {
			$items_total += (float) $item->get_subtotal();
		}

		return round( $items_total, wc_get_price_decimals() );
	}

	/**
	 * Get the EAO total product discount amount.
	 *
	 * This reflects item/global product discounts from EAO and excludes wallet
	 * payments so the invoice matches the order-editor summary.
	 *
	 * @return float
	 */
	public function get_total_product_discount_amount() {
		if ( function_exists( 'eao_calculate_total_item_level_discounts' ) ) {
			return round( max( 0, (float) eao_calculate_total_item_level_discounts( $this->order ) ), wc_get_price_decimals() );
		}

		$global_discount_percent = (float) $this->order->get_meta( '_eao_global_product_discount_percent' );
		$product_discount = 0.0;

		foreach ( $this->order->get_items() as $item ) {
			$original_total = (float) $item->get_subtotal();
			$item_discount_percent = (float) $item->get_meta( '_eao_item_discount_percent' );
			$exclude_global = $item->get_meta( '_eao_exclude_global_discount' ) === 'yes'
				|| $item->get_meta( '_eao_exclude_from_global_discount' ) === 'yes';

			if ( $item_discount_percent > 0 ) {
				$discount_percent = $item_discount_percent;
			} elseif ( ! $exclude_global && $global_discount_percent > 0 ) {
				$discount_percent = $global_discount_percent;
			} else {
				$discount_percent = 0;
			}

			if ( $discount_percent > 0 ) {
				$product_discount += $original_total * ( $discount_percent / 100 );
			}
		}

		return round( max( 0, $product_discount ), wc_get_price_decimals() );
	}

	/**
	 * Get additional non-wallet admin discounts stored as fee lines.
	 *
	 * @return float
	 */
	public function get_admin_discount_amount() {
		if ( function_exists( 'eao_points_get_non_wallet_discount_fee_total' ) ) {
			return round( max( 0, (float) eao_points_get_non_wallet_discount_fee_total( $this->order ) ), wc_get_price_decimals() );
		}

		$discount_total = 0.0;
		foreach ( $this->order->get_fees() as $fee ) {
			$fee_total = (float) $fee->get_total() + (float) $fee->get_total_tax();
			if ( $fee_total < 0 && ! $this->is_store_credit_fee( $fee ) && ! $this->is_points_fee( $fee ) ) {
				$discount_total += abs( $fee_total );
			}
		}

		return round( max( 0, $discount_total ), wc_get_price_decimals() );
	}

	/**
	 * Get the EAO "products total (net)" amount.
	 *
	 * @return float
	 */
	public function get_products_total_net_amount() {
		if ( function_exists( 'eao_calculate_products_total' ) ) {
			return round( max( 0, (float) eao_calculate_products_total( $this->order ) ), wc_get_price_decimals() );
		}

		return round(
			max( 0, $this->get_items_total_gross_amount() - $this->get_total_product_discount_amount() ),
			wc_get_price_decimals()
		);
	}

	/**
	 * Get discount breakdown for the order.
	 *
	 * @return array
	 */
	public function get_discount_summary() {
		$summary = array();
		$currency = $this->order->get_currency();

		$product_discount = $this->get_total_product_discount_amount();
		$admin_discount = $this->get_admin_discount_amount();
		$points_count = $this->get_points_redeemed_count();
		$points_amount = $this->get_points_discount_amount();
		
		// Fallback: check for YITH discount coupons if meta not found
		if ( $points_amount < 0.01 ) {
			$used_coupons = $this->order->get_coupon_codes();
			foreach ( $used_coupons as $coupon_code ) {
				if ( strpos( $coupon_code, 'ywpar_discount_' ) === 0 ) {
					$coupon = new \WC_Coupon( $coupon_code );
					if ( $coupon->get_id() && ! empty( $coupon->get_meta( 'ywpar_coupon' ) ) ) {
						$points_amount = (float) $coupon->get_amount();
						$points_count = (int) ( $points_amount * 10 ); // 10 points = $1
						break;
					}
				}
			}
		}

		if ( $product_discount > 0.01 ) {
			$summary[] = array(
				'label' => __( 'Total Product Discount:', 'hp-pdf-invoices' ),
				'value' => '-' . \wc_price( $product_discount, array( 'currency' => $currency ) ),
				'raw'   => $product_discount,
			);
		}

		if ( $admin_discount > 0.01 ) {
			$summary[] = array(
				'label' => __( 'Admin Discount:', 'hp-pdf-invoices' ),
				'value' => '-' . \wc_price( $admin_discount, array( 'currency' => $currency ) ),
				'raw'   => $admin_discount,
			);
		}

		if ( $points_amount > 0.01 ) {
			$points_label = $points_count > 0 
				? sprintf( __( 'Points Discount (%d points):', 'hp-pdf-invoices' ), $points_count ) 
				: __( 'Points Discount:', 'hp-pdf-invoices' );
			$summary[] = array(
				'label' => $points_label,
				'value' => '-' . \wc_price( $points_amount, array( 'currency' => $currency ) ),
				'raw'   => $points_amount,
			);
		}

		return $summary;
	}

	/**
	 * Build the EAO-style totals rows used across invoice formats.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_eao_totals_rows() {
		$rows = array();

		$items_gross = $this->get_items_total_gross_amount();
		$product_discount = $this->get_total_product_discount_amount();
		$admin_discount = $this->get_admin_discount_amount();
		$products_total_net = $this->get_products_total_net_amount();
		$points_discount = $this->get_points_discount_amount();
		$points_count = $this->get_points_redeemed_count();
		$store_credit = $this->get_store_credit_applied();
		$shipping_total = round( (float) $this->order->get_shipping_total(), wc_get_price_decimals() );
		$tax_total = round( (float) $this->order->get_total_tax(), wc_get_price_decimals() );
		$grand_total = round( (float) $this->order->get_total(), wc_get_price_decimals() );

		$rows[] = array(
			'key'       => 'items_total_gross',
			'label'     => __( 'Items Total (Gross):', 'hp-pdf-invoices' ),
			'raw_value' => $items_gross,
		);

		if ( $product_discount > 0.01 ) {
			$rows[] = array(
				'key'       => 'total_product_discount',
				'label'     => __( 'Total Product Discount:', 'hp-pdf-invoices' ),
				'raw_value' => -1 * $product_discount,
				'class'     => 'discount-line',
			);
		}

		if ( $admin_discount > 0.01 ) {
			$rows[] = array(
				'key'       => 'admin_discount',
				'label'     => __( 'Admin Discount:', 'hp-pdf-invoices' ),
				'raw_value' => -1 * $admin_discount,
				'class'     => 'discount-line',
			);
		}

		$rows[] = array(
			'key'       => 'products_total_net',
			'label'     => __( 'Products Total (Net):', 'hp-pdf-invoices' ),
			'raw_value' => $products_total_net,
		);

		if ( $points_discount > 0.01 ) {
			$rows[] = array(
				'key'       => 'points_discount',
				'label'     => $points_count > 0
					? sprintf( __( 'Points Discount (%d points):', 'hp-pdf-invoices' ), $points_count )
					: __( 'Points Discount:', 'hp-pdf-invoices' ),
				'raw_value' => -1 * $points_discount,
				'class'     => 'discount-line',
			);
		}

		if ( $store_credit > 0.01 ) {
			$rows[] = array(
				'key'       => 'store_credit_applied',
				'label'     => __( 'Store Credit Applied:', 'hp-pdf-invoices' ),
				'raw_value' => -1 * $store_credit,
				'class'     => 'discount-line',
			);
		}

		$rows[] = array(
			'key'       => 'shipping',
			'label'     => __( 'Shipping:', 'hp-pdf-invoices' ),
			'raw_value' => $shipping_total,
		);

		if ( $tax_total > 0.01 ) {
			$rows[] = array(
				'key'       => 'tax',
				'label'     => __( 'Tax:', 'hp-pdf-invoices' ),
				'raw_value' => $tax_total,
			);
		}

		$known_grand_total = $products_total_net - $admin_discount - $points_discount - $store_credit + $shipping_total + $tax_total;
		$known_grand_total = round( $known_grand_total, wc_get_price_decimals() );
		$adjustment = round( $grand_total - $known_grand_total, wc_get_price_decimals() );

		if ( abs( $adjustment ) > 0.01 ) {
			$rows[] = array(
				'key'       => 'additional_adjustment',
				'label'     => $adjustment < 0
					? __( 'Additional Discount:', 'hp-pdf-invoices' )
					: __( 'Additional Charge:', 'hp-pdf-invoices' ),
				'raw_value' => $adjustment,
				'class'     => $adjustment < 0 ? 'discount-line' : '',
			);
		}

		$rows[] = array(
			'key'       => 'total',
			'label'     => __( 'Grand Total:', 'hp-pdf-invoices' ),
			'raw_value' => $grand_total,
			'bold'      => true,
		);

		return $rows;
	}

	public function get_totals() {
		$totals = array();
		$currency = $this->order->get_currency();

		foreach ( $this->get_eao_totals_rows() as $row ) {
			$raw_value = (float) $row['raw_value'];
			$formatted_value = \wc_price( abs( $raw_value ), array( 'currency' => $currency ) );
			if ( $raw_value < 0 ) {
				$formatted_value = '-' . $formatted_value;
			}

			$totals[ $row['key'] ] = array(
				'label' => $row['label'],
				'value' => $formatted_value,
			);

			if ( ! empty( $row['class'] ) ) {
				$totals[ $row['key'] ]['class'] = $row['class'];
			}
		}

		return $totals;
	}

	/**
	 * Get raw order items data for Excel export
	 * Returns unformatted numeric values suitable for spreadsheet calculations
	 *
	 * @return array
	 */
	public function get_raw_order_items() {
		$items = $this->order->get_items();
		$data = array();

		foreach ( $items as $item_id => $item ) {
			$product = $item->get_product();
			$line_total = (float) $item->get_total();
			$line_subtotal = (float) $item->get_subtotal();
			$quantity = (int) $item->get_quantity();
			
			// Actual paid price per unit
			$unit_price = $quantity > 0 ? $line_total / $quantity : 0;
			$original_unit_price = $quantity > 0 ? $line_subtotal / $quantity : 0;

			// Get per-item discount percent from EAO meta
			$discount_percent = (float) $item->get_meta( '_eao_item_discount_percent', true );

			$data[] = array(
				'id'                  => $item_id,
				'name'                => $item->get_name(),
				'sku'                 => $product ? $product->get_sku() : '',
				'quantity'            => $quantity,
				'unit_price'          => round( $unit_price, 2 ),
				'original_unit_price' => round( $original_unit_price, 2 ),
				'line_total'          => round( $line_total, 2 ),
				'line_subtotal'       => round( $line_subtotal, 2 ),
				'has_discount'        => $line_total < $line_subtotal,
				'discount_percent'    => $discount_percent,
			);
		}

		return $data;
	}

	/**
	 * Get raw totals data for Excel export
	 * Returns unformatted numeric values suitable for spreadsheet calculations
	 *
	 * @return array
	 */
	public function get_raw_totals() {
		$totals = array();

		foreach ( $this->get_eao_totals_rows() as $row ) {
			$totals[] = array(
				'key'       => $row['key'],
				'label'     => rtrim( $row['label'], ':' ),
				'raw_value' => round( (float) $row['raw_value'], 2 ),
			);
		}

		return $totals;
	}

	/**
	 * Check whether a fee item represents store credit.
	 *
	 * @param mixed $fee Fee item.
	 * @return bool
	 */
	private function is_store_credit_fee( $fee ) {
		if ( class_exists( '\HP_Core\Services\OrderPaymentDisplay' ) ) {
			return \HP_Core\Services\OrderPaymentDisplay::is_store_credit_fee( $fee );
		}

		return $fee instanceof \WC_Order_Item_Fee
			&& (
				(string) $fee->get_meta( '_hp_wallet_fee_type' ) === 'store_credit'
				|| strpos( strtolower( (string) $fee->get_name() ), 'store credit' ) !== false
			);
	}

	/**
	 * Check whether a fee item represents redeemed points.
	 *
	 * @param mixed $fee Fee item.
	 * @return bool
	 */
	private function is_points_fee( $fee ) {
		if ( class_exists( '\HP_Core\Services\OrderPaymentDisplay' ) ) {
			return \HP_Core\Services\OrderPaymentDisplay::is_points_fee( $fee );
		}

		return $fee instanceof \WC_Order_Item_Fee
			&& (
				(string) $fee->get_meta( '_hp_wallet_fee_type' ) === 'points'
				|| strpos( strtolower( (string) $fee->get_name() ), 'points' ) !== false
			);
	}

	/**
	 * Use EAO's saved pending points selection for draft/unpaid admin orders.
	 *
	 * This keeps invoice exports aligned with the EAO summary before wallet/coupon
	 * metadata has been written to the order.
	 *
	 * @return array{points:int,amount:float}
	 */
	private function get_eao_pending_points_intent() {
		if ( ! $this->should_use_eao_pending_points_intent() ) {
			return array(
				'points' => 0,
				'amount' => 0.0,
			);
		}

		$snapshot = $this->order->get_meta( '_eao_current_points_discount', true );
		if ( is_array( $snapshot ) ) {
			$points = isset( $snapshot['points'] ) ? max( 0, (int) $snapshot['points'] ) : 0;
			$amount = isset( $snapshot['amount'] ) ? round( max( 0, (float) $snapshot['amount'] ), wc_get_price_decimals() ) : 0.0;

			if ( $points > 0 || $amount > 0 ) {
				if ( $points <= 0 && $amount > 0 ) {
					$points = (int) round( $amount * 10 );
				}
				if ( $amount <= 0 && $points > 0 ) {
					$amount = round( $points / 10, wc_get_price_decimals() );
				}

				return array(
					'points' => $points,
					'amount' => $amount,
				);
			}
		}

		$pending_points = max( 0, (int) $this->order->get_meta( '_eao_pending_points_to_redeem', true ) );
		if ( $pending_points > 0 ) {
			return array(
				'points' => $pending_points,
				'amount' => round( $pending_points / 10, wc_get_price_decimals() ),
			);
		}

		return array(
			'points' => 0,
			'amount' => 0.0,
		);
	}

	/**
	 * Pending EAO points are only authoritative before the order is finalized.
	 *
	 * @return bool
	 */
	private function should_use_eao_pending_points_intent() {
		return ! in_array( $this->order->get_status(), array( 'processing', 'completed', 'shipped', 'delivered' ), true );
	}
}

endif;
