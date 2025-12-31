<?php
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
		$this->show_paid_price   = $order->get_meta( '_hp_pdfi_show_paid_price' ) !== 'no';
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

	public function output() {
		$html = $this->get_html();
		$pdf_maker = new PDFMaker( $html );
		$pdf = $pdf_maker->output();

		if ( $pdf ) {
			$filename = 'invoice-' . $this->order->get_order_number() . '.pdf';
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: inline; filename="' . $filename . '"' );
			echo $pdf;
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
			$line_total = $item->get_total();
			$line_subtotal = $item->get_subtotal();
			$quantity = $item->get_quantity();
			
			// Actual paid price per unit
			$unit_price = $line_total / $quantity;
			$original_unit_price = $line_subtotal / $quantity;

			$data[] = array(
				'id'            => $item_id,
				'name'          => $item->get_name(),
				'sku'           => $product ? $product->get_sku() : '',
				'quantity'      => $quantity,
				'price'         => \wc_price( $unit_price, array( 'currency' => $this->order->get_currency() ) ),
				'original_price'=> \wc_price( $original_unit_price, array( 'currency' => $this->order->get_currency() ) ),
				'total'         => \wc_price( $line_total, array( 'currency' => $this->order->get_currency() ) ),
				'has_discount'  => $line_total < $line_subtotal,
				'image'         => $this->show_images ? $this->get_product_image( $product ) : '',
			);
		}

		return $data;
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

	public function get_discount_summary() {
		if ( $this->show_paid_price ) {
			return array();
		}

		$summary = array();

		// 1. Points Redeemed
		$points = $this->order->get_meta( '_ywpar_coupon_points' );
		$points_amount = (float) $this->order->get_meta( '_ywpar_coupon_amount' );

		// 2. Offer & Item Discounts
		// We want to capture:
		// - Item level discounts (difference between subtotal and total)
		// - Fees that are negative (like Offer Savings)
		// - Coupons that are NOT points coupons
		
		$item_discounts = 0;
		foreach ( $this->order->get_items() as $item ) {
			$item_discounts += ( (float)$item->get_subtotal() - (float)$item->get_total() );
		}

		// Also check for "Offer Savings" or any negative fees
		$negative_fees = 0;
		foreach ( $this->order->get_fees() as $fee ) {
			$fee_total = (float) $fee->get_total();
			if ( $fee_total < 0 ) {
				$negative_fees += abs( $fee_total );
			}
		}

		// Calculate "Offer & Item Discounts"
		// This is (Total Item Discounts + Negative Fees) - Points Amount
		// because points amount is usually applied as an item discount by WC.
		$offer_and_other_discounts = ( $item_discounts + $negative_fees ) - $points_amount;
		
		// Ensure it's not negative due to rounding
		$offer_and_other_discounts = max( 0, $offer_and_other_discounts );

		if ( $offer_and_other_discounts > 0.001 ) {
			$summary[] = array(
				'label' => __( 'Offer & Item Discounts:', 'hp-pdf-invoices' ),
				'value' => '-' . \wc_price( $offer_and_other_discounts, array( 'currency' => $this->order->get_currency() ) ),
			);
		}

		if ( $points_amount > 0.001 ) {
			$points_label = $points ? sprintf( __( 'Points Redeemed (%d pts):', 'hp-pdf-invoices' ), $points ) : __( 'Points Redeemed:', 'hp-pdf-invoices' );
			$summary[] = array(
				'label' => $points_label,
				'value' => '-' . \wc_price( $points_amount, array( 'currency' => $this->order->get_currency() ) ),
			);
		}

		return $summary;
	}

	public function get_totals() {
		$totals = array();
		
		// 1. Subtotal (Before any discounts)
		$totals['subtotal'] = array(
			'label' => __( 'Subtotal', 'hp-pdf-invoices' ),
			'value' => $this->order->get_subtotal_to_display(),
		);

		// 2. Discounts (If show_paid_price is false)
		if ( ! $this->show_paid_price ) {
			$discounts = $this->get_discount_summary();
			foreach ( $discounts as $index => $discount ) {
				$totals['discount_' . $index] = array(
					'label' => $discount['label'],
					'value' => $discount['value'],
					'class' => 'discount-line',
				);
			}
		}

		// 3. Shipping
		if ( (float) $this->order->get_shipping_total() > 0 ) {
			$totals['shipping'] = array(
				'label' => __( 'Shipping', 'hp-pdf-invoices' ),
				'value' => $this->order->get_shipping_to_display(),
			);
		}

		// 4. Taxes
		foreach ( $this->order->get_tax_totals() as $code => $tax ) {
			$totals[ 'tax_' . $code ] = array(
				'label' => $tax->label,
				'value' => $tax->formatted_amount,
			);
		}

		// 5. Total
		$totals['total'] = array(
			'label' => __( 'Total', 'hp-pdf-invoices' ),
			'value' => $this->order->get_formatted_order_total(),
		);

		return $totals;
	}
}

endif;

