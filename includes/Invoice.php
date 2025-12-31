<?php
namespace HP_PDFI;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

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

		// Load options from order meta
		$this->show_paid_price   = $order->get_meta( '_hp_pdfi_show_paid_price' ) !== 'no';
		$this->printer_friendly = $order->get_meta( '_hp_pdfi_printer_friendly' ) === 'yes';
		$this->show_images       = $order->get_meta( '_hp_pdfi_show_images' ) !== 'no';

		// If printer friendly is on but show images is also on, show images overrides printer friendly's "no images"
		// However, printer friendly still affects headings and logo.
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

		// 2. Points Redeemed
		$points = $this->order->get_meta( '_ywpar_coupon_points' );
		$points_amount = (float) $this->order->get_meta( '_ywpar_coupon_amount' );

		// 1. Offer & Item Discounts
		// Sum of item discounts + Offer Savings fee
		$item_discounts = 0;
		foreach ( $this->order->get_items() as $item ) {
			$item_discounts += ( (float)$item->get_subtotal() - (float)$item->get_total() );
		}

		// Subtract points amount from item discounts to avoid double counting
		// because WC applies coupons to items.
		if ( $points_amount > 0 ) {
			$item_discounts = max( 0, $item_discounts - $points_amount );
		}

		$offer_savings = 0;
		foreach ( $this->order->get_fees() as $fee ) {
			if ( stripos( $fee->get_name(), 'Offer Savings' ) !== false ) {
				$offer_savings += abs( (float)$fee->get_total() );
			}
		}

		$total_discounts = $item_discounts + $offer_savings;
		if ( $total_discounts > 0 ) {
			$summary[] = array(
				'label' => __( 'Offer & Item Discounts:', 'hp-pdf-invoices' ),
				'value' => '-' . \wc_price( $total_discounts, array( 'currency' => $this->order->get_currency() ) ),
			);
		}

		if ( $points && $points_amount ) {
			$summary[] = array(
				'label' => sprintf( __( 'Points Redeemed (%d pts):', 'hp-pdf-invoices' ), $points ),
				'value' => '-' . \wc_price( $points_amount, array( 'currency' => $this->order->get_currency() ) ),
			);
		}

		return $summary;
	}

	public function get_totals() {
		$totals = array();
		
		// Subtotal
		$totals['subtotal'] = array(
			'label' => __( 'Subtotal', 'hp-pdf-invoices' ),
			'value' => $this->order->get_subtotal_to_display(),
		);

		// Shipping
		if ( (float) $this->order->get_shipping_total() > 0 ) {
			$totals['shipping'] = array(
				'label' => __( 'Shipping', 'hp-pdf-invoices' ),
				'value' => $this->order->get_shipping_to_display(),
			);
		}

		// Taxes
		foreach ( $this->order->get_tax_totals() as $code => $tax ) {
			$totals[ 'tax_' . $code ] = array(
				'label' => $tax->label,
				'value' => $tax->formatted_amount,
			);
		}

		// Total
		$totals['total'] = array(
			'label' => __( 'Total', 'hp-pdf-invoices' ),
			'value' => $this->order->get_formatted_order_total(),
		);

		return $totals;
	}
}

