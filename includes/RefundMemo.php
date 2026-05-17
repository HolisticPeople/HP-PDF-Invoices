<?php
/**
 * Refund memo PDF document.
 *
 * @package HP_PDF_Invoices
 */
namespace HP_PDFI;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'HP_PDFI\RefundMemo' ) ) :

class RefundMemo {

	/**
	 * @var \WC_Order
	 */
	public $order;

	/**
	 * @var int
	 */
	protected $refund_id;

	/**
	 * @param \WC_Order $order     Original order.
	 * @param int       $refund_id Optional refund ID. Zero includes all refunds.
	 */
	public function __construct( $order, $refund_id = 0 ) {
		$this->order     = $order;
		$this->refund_id = absint( $refund_id );
	}

	/**
	 * Output the refund memo PDF inline.
	 */
	public function output_pdf() {
		$html      = $this->get_html();
		$pdf_maker = new PDFMaker( $html );
		$pdf       = $pdf_maker->output();

		if ( $pdf ) {
			$filename = $this->get_filename_base() . '.pdf';
			header( 'Content-Type: application/pdf' );
			header( 'Content-Disposition: inline; filename="' . $filename . '"' );
			echo $pdf;
		}
	}

	/**
	 * Render the refund memo template to HTML.
	 *
	 * @return string
	 */
	public function get_html() {
		ob_start();
		$order = $this->order;
		$memo  = $this;
		include HP_PDFI_PATH . 'templates/refund-memo.php';
		return ob_get_clean();
	}

	/**
	 * Get the memo number shown on the PDF.
	 *
	 * @return string
	 */
	public function get_memo_number() {
		$suffix = $this->refund_id > 0 ? $this->refund_id : 'all';
		return 'RM-' . $this->order->get_order_number() . '-' . $suffix;
	}

	/**
	 * Get the refund records covered by this memo.
	 *
	 * @return array<int,\WC_Order_Refund>
	 */
	public function get_refunds() {
		$refunds = array();

		foreach ( $this->order->get_refunds() as $refund ) {
			if ( ! $refund instanceof \WC_Order_Refund ) {
				continue;
			}

			if ( $this->refund_id > 0 && (int) $refund->get_id() !== $this->refund_id ) {
				continue;
			}

			$refunds[] = $refund;
		}

		if ( empty( $refunds ) ) {
			wp_die( __( 'No matching refund was found for this order.', 'hp-pdf-invoices' ) );
		}

		return $refunds;
	}

	/**
	 * Build grouped refund data for the template.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_refund_groups() {
		$groups = array();

		foreach ( $this->get_refunds() as $refund ) {
			$groups[] = array(
				'id'                => $refund->get_id(),
				'date'              => $this->format_date( $refund->get_date_created() ),
				'reason'            => (string) $refund->get_reason(),
				'gateway'           => $this->get_refund_meta( $refund, '_eao_refunded_via_gateway' ),
				'gateway_reference' => $this->get_refund_meta( $refund, '_eao_refund_reference' ),
				'cash_amount'       => abs( (float) $refund->get_amount() ),
				'store_credit'      => $this->get_store_credit_refunded( $refund ),
				'points'            => (int) $this->get_refund_meta( $refund, '_eao_points_refunded' ),
				'quantity'          => (int) $this->get_refund_meta( $refund, '_eao_quantity_refunded' ),
				'rows'              => $this->get_refund_rows( $refund ),
			);
		}

		return $groups;
	}

	/**
	 * Build refund summary totals.
	 *
	 * @return array<string,float|int>
	 */
	public function get_summary() {
		$summary = array(
			'cash_amount'  => 0.0,
			'store_credit' => 0.0,
			'points'       => 0,
			'quantity'     => 0,
			'line_total'   => 0.0,
		);

		foreach ( $this->get_refund_groups() as $group ) {
			$summary['cash_amount']  += (float) $group['cash_amount'];
			$summary['store_credit'] += (float) $group['store_credit'];
			$summary['points']       += (int) $group['points'];
			$summary['quantity']     += (int) $group['quantity'];

			foreach ( $group['rows'] as $row ) {
				$summary['line_total'] += (float) $row['amount'];
			}
		}

		$summary['cash_amount']  = round( $summary['cash_amount'], wc_get_price_decimals() );
		$summary['store_credit'] = round( $summary['store_credit'], wc_get_price_decimals() );
		$summary['line_total']   = round( $summary['line_total'], wc_get_price_decimals() );

		return $summary;
	}

	/**
	 * Format a money value in the order currency.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public function format_money( $amount ) {
		return \wc_price( (float) $amount, array( 'currency' => $this->order->get_currency() ) );
	}

	/**
	 * Format a WooCommerce date object.
	 *
	 * @param mixed $date Date object/string/null.
	 * @return string
	 */
	public function format_date( $date ) {
		if ( $date instanceof \WC_DateTime ) {
			return $date->date_i18n( get_option( 'date_format' ) );
		}

		if ( $date ) {
			return date_i18n( get_option( 'date_format' ), strtotime( (string) $date ) );
		}

		return date_i18n( get_option( 'date_format' ) );
	}

	/**
	 * Build refunded line rows for one refund.
	 *
	 * @param \WC_Order_Refund $refund Refund.
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_refund_rows( $refund ) {
		$rows = array();

		foreach ( array( 'line_item', 'shipping', 'fee' ) as $type ) {
			foreach ( $refund->get_items( $type ) as $item ) {
				$row = $this->format_refund_item_row( $item, $type );
				if ( $row ) {
					$rows[] = $row;
				}
			}
		}

		if ( empty( $rows ) && abs( (float) $refund->get_amount() ) > 0 ) {
			$rows[] = array(
				'type'   => __( 'Refund', 'hp-pdf-invoices' ),
				'name'   => __( 'Order refund', 'hp-pdf-invoices' ),
				'sku'    => '',
				'qty'    => '',
				'amount' => abs( (float) $refund->get_amount() ),
			);
		}

		return $rows;
	}

	/**
	 * Format one refund item row.
	 *
	 * @param mixed  $item Refund item.
	 * @param string $type Item type.
	 * @return array<string,mixed>|null
	 */
	protected function format_refund_item_row( $item, $type ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_name' ) ) {
			return null;
		}

		$total = method_exists( $item, 'get_total' ) ? (float) $item->get_total() : 0.0;
		$tax   = method_exists( $item, 'get_total_tax' ) ? (float) $item->get_total_tax() : 0.0;
		$qty   = method_exists( $item, 'get_quantity' ) ? abs( (int) $item->get_quantity() ) : 0;

		$label = $item->get_name();
		if ( 'shipping' === $type ) {
			$label = sprintf( __( 'Shipping: %s', 'hp-pdf-invoices' ), $label );
		} elseif ( 'fee' === $type ) {
			$label = sprintf( __( 'Fee: %s', 'hp-pdf-invoices' ), $label );
		}

		return array(
			'type'   => $this->get_type_label( $type ),
			'name'   => $label,
			'sku'    => $this->get_refund_item_sku( $item ),
			'qty'    => $qty > 0 ? $qty : '',
			'amount' => abs( $total + $tax ),
		);
	}

	/**
	 * Resolve the SKU from the original order item when available.
	 *
	 * @param mixed $item Refund item.
	 * @return string
	 */
	protected function get_refund_item_sku( $item ) {
		if ( ! method_exists( $item, 'get_meta' ) ) {
			return '';
		}

		$original_item_id = absint( $item->get_meta( '_refunded_item_id', true ) );
		if ( $original_item_id <= 0 ) {
			return '';
		}

		$original_item = $this->order->get_item( $original_item_id );
		if ( ! $original_item || ! method_exists( $original_item, 'get_product' ) ) {
			return '';
		}

		$product = $original_item->get_product();
		return $product && method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '';
	}

	/**
	 * Get a display label for a refund item type.
	 *
	 * @param string $type Item type.
	 * @return string
	 */
	protected function get_type_label( $type ) {
		if ( 'shipping' === $type ) {
			return __( 'Shipping', 'hp-pdf-invoices' );
		}
		if ( 'fee' === $type ) {
			return __( 'Fee', 'hp-pdf-invoices' );
		}
		return __( 'Product', 'hp-pdf-invoices' );
	}

	/**
	 * Get refund post meta.
	 *
	 * @param \WC_Order_Refund $refund   Refund.
	 * @param string           $meta_key Meta key.
	 * @return mixed
	 */
	protected function get_refund_meta( $refund, $meta_key ) {
		$value = method_exists( $refund, 'get_meta' ) ? $refund->get_meta( $meta_key, true ) : '';
		if ( '' === $value || null === $value ) {
			$value = get_post_meta( $refund->get_id(), $meta_key, true );
		}

		return $value;
	}

	/**
	 * Get all store-credit value restored by a refund.
	 *
	 * @param \WC_Order_Refund $refund Refund.
	 * @return float
	 */
	protected function get_store_credit_refunded( $refund ) {
		$credit = (float) $this->get_refund_meta( $refund, '_eao_credit_refunded' );
		$credit += (float) $this->get_refund_meta( $refund, '_eao_offline_refund_as_credit' );

		return round( max( 0, $credit ), wc_get_price_decimals() );
	}

	/**
	 * Get sanitized filename base.
	 *
	 * @return string
	 */
	protected function get_filename_base() {
		return sanitize_file_name( strtolower( 'refund-memo-' . $this->get_memo_number() ) );
	}
}

endif;
