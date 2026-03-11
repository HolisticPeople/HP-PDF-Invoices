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
			return \HP_Core\Services\OrderPaymentDisplay::get_points_redeemed( $this->order );
		}

		$split = $this->order->get_meta( '_hp_wallet_payment_split', true );
		if ( is_array( $split ) && ! empty( $split['points_redeemed'] ) ) {
			return (int) $split['points_redeemed'];
		}

		$points = (int) ( $this->order->get_meta( '_hp_wallet_points_redeemed' ) ?: $this->order->get_meta( '_hp_rw_points_redeemed' ) );
		if ( $points > 0 ) {
			return $points;
		}

		return (int) $this->order->get_meta( '_ywpar_coupon_points' );
	}

	/**
	 * Get the monetary points discount stored on the order.
	 *
	 * @return float
	 */
	public function get_points_discount_amount() {
		if ( class_exists( '\HP_Core\Services\OrderPaymentDisplay' ) ) {
			return \HP_Core\Services\OrderPaymentDisplay::get_points_discount( $this->order );
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

		return 0.0;
	}

	/**
	 * Get discount breakdown for the order
	 * Fetches Product Discounts from EAO meta and Points Discounts from YITH meta
	 * No complex calculations - just read stored values
	 *
	 * @return array
	 */
	public function get_discount_summary() {
		$summary = array();
		$currency = $this->order->get_currency();

		// 1. Get Product Discount from EAO stored discount percentages
		// EAO stores: _eao_item_discount_percent per item, _eao_global_product_discount_percent on order
		$global_discount_percent = (float) $this->order->get_meta( '_eao_global_product_discount_percent' );
		$product_discount = 0;
		
		foreach ( $this->order->get_items() as $item ) {
			$original_total = (float) $item->get_subtotal();
			
			// Get item-specific discount percent from EAO meta
			$item_discount_percent = (float) $item->get_meta( '_eao_item_discount_percent' );
			$exclude_global = $item->get_meta( '_eao_exclude_global_discount' ) === 'yes';
			
			// Determine which discount to apply
			if ( $item_discount_percent > 0 ) {
				// Item has its own discount percent
				$discount_percent = $item_discount_percent;
			} elseif ( ! $exclude_global && $global_discount_percent > 0 ) {
				// Use global discount
				$discount_percent = $global_discount_percent;
			} else {
				$discount_percent = 0;
			}
			
			if ( $discount_percent > 0 ) {
				$discount_amount = $original_total * ( $discount_percent / 100 );
				$product_discount += $discount_amount;
			}
		}
		
		// Also add negative discount fees, but never wallet-payment fees.
		foreach ( $this->order->get_fees() as $fee ) {
			$fee_total = (float) $fee->get_total();
			if ( $fee_total < 0 && ! $this->is_store_credit_fee( $fee ) && ! $this->is_points_fee( $fee ) ) {
				$product_discount += abs( $fee_total );
			}
		}
		
		// 2. Get Points Discount from stored order metadata (HP-Wallet first, YITH legacy fallback).
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

		// 3. Build the summary array
		if ( $product_discount > 0.01 ) {
			$summary[] = array(
				'label' => __( 'Product Discount:', 'hp-pdf-invoices' ),
				'value' => '-' . \wc_price( $product_discount, array( 'currency' => $currency ) ),
				'raw'   => $product_discount,
			);
		}

		if ( $points_amount > 0.01 ) {
			$points_label = $points_count > 0 
				? sprintf( __( 'Points Discount (%d pts):', 'hp-pdf-invoices' ), $points_count ) 
				: __( 'Points Discount:', 'hp-pdf-invoices' );
			$summary[] = array(
				'label' => $points_label,
				'value' => '-' . \wc_price( $points_amount, array( 'currency' => $currency ) ),
				'raw'   => $points_amount,
			);
		}

		return $summary;
	}

	public function get_totals() {
		$totals = array();
		$currency = $this->order->get_currency();
		
		// 1. Subtotal (Original prices before any discounts)
		// Calculate from item subtotals for accuracy
		$items_subtotal = 0;
		foreach ( $this->order->get_items() as $item ) {
			$items_subtotal += (float) $item->get_subtotal();
		}
		
		$totals['subtotal'] = array(
			'label' => __( 'Subtotal', 'hp-pdf-invoices' ),
			'value' => \wc_price( $items_subtotal, array( 'currency' => $currency ) ),
		);

		// 2. Discounts - Always show if present
		// Track total discounts for grand total calculation
		$discounts = $this->get_discount_summary();
		$total_discount = 0;
		foreach ( $discounts as $index => $discount ) {
			$totals['discount_' . $index] = array(
				'label' => $discount['label'],
				'value' => $discount['value'],
				'class' => 'discount-line',
			);
			$total_discount += $discount['raw'];
		}

		// 3. Shipping
		$shipping_total = (float) $this->order->get_shipping_total();
		if ( $shipping_total > 0 ) {
			// Clean shipping display to remove {{CARRIER}} template markers
			$shipping_display = $this->order->get_shipping_to_display();
			$shipping_display = $this->clean_shipping_method( $shipping_display );
			
			$totals['shipping'] = array(
				'label' => __( 'Shipping', 'hp-pdf-invoices' ),
				'value' => $shipping_display,
			);
		}

		// 4. Taxes
		$tax_total = 0;
		foreach ( $this->order->get_tax_totals() as $code => $tax ) {
			$totals[ 'tax_' . $code ] = array(
				'label' => $tax->label,
				'value' => $tax->formatted_amount,
			);
			$tax_total += (float) $tax->amount;
		}

		// 5. Total paid = subtotal - discounts + shipping + tax.
		$grand_total = $items_subtotal - $total_discount + $shipping_total + $tax_total;
		
		$totals['total'] = array(
			'label' => __( 'Total Paid', 'hp-pdf-invoices' ),
			'value' => \wc_price( $grand_total, array( 'currency' => $currency ) ),
		);

		$store_credit = $this->get_store_credit_applied();
		if ( $store_credit > 0 ) {
			$totals['store_credit_payment'] = array(
				'label' => __( 'Paid with Store Credit', 'hp-pdf-invoices' ),
				'value' => \wc_price( $store_credit, array( 'currency' => $currency ) ),
			);
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
		
		// Subtotal
		$subtotal = 0;
		foreach ( $this->order->get_items() as $item ) {
			$subtotal += (float) $item->get_subtotal();
		}
		$totals[] = array(
			'key'       => 'subtotal',
			'label'     => __( 'Subtotal', 'hp-pdf-invoices' ),
			'raw_value' => round( $subtotal, 2 ),
		);

		// Discounts - use the same classified breakdown as the HTML/DOCX views.
		foreach ( $this->get_discount_summary() as $index => $discount ) {
			$totals[] = array(
				'key'       => 'discount_' . $index,
				'label'     => rtrim( $discount['label'], ':' ),
				'raw_value' => round( -1 * (float) $discount['raw'], 2 ),
			);
		}

		// Shipping
		$shipping = (float) $this->order->get_shipping_total();
		if ( $shipping > 0 ) {
			$totals[] = array(
				'key'       => 'shipping',
				'label'     => __( 'Shipping', 'hp-pdf-invoices' ),
				'raw_value' => round( $shipping, 2 ),
			);
		}

		// Taxes
		foreach ( $this->order->get_tax_totals() as $code => $tax ) {
			$totals[] = array(
				'key'       => 'tax_' . $code,
				'label'     => $tax->label,
				'raw_value' => round( (float) $tax->amount, 2 ),
			);
		}

		// Total paid = order total + store credit applied.
		$total_paid = 0.0;
		foreach ( $totals as $row ) {
			$total_paid += (float) $row['raw_value'];
		}
		$totals[] = array(
			'key'       => 'total',
			'label'     => __( 'Total Paid', 'hp-pdf-invoices' ),
			'raw_value' => round( $total_paid, 2 ),
		);

		$store_credit = $this->get_store_credit_applied();
		if ( $store_credit > 0 ) {
			$totals[] = array(
				'key'       => 'store_credit_payment',
				'label'     => __( 'Paid with Store Credit', 'hp-pdf-invoices' ),
				'raw_value' => round( $store_credit, 2 ),
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
}

endif;
