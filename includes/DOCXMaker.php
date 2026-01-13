<?php
/**
 * DOCX Maker - Generates Word documents for invoices
 * 
 * @package HP_PDF_Invoices
 * @version 1.2.0
 * @author Amnon Manneberg
 */
namespace HP_PDFI;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Font;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'HP_PDFI\DOCXMaker' ) ) :

class DOCXMaker {

	/**
	 * @var Invoice
	 */
	protected $invoice;

	/**
	 * @var \WC_Order
	 */
	protected $order;

	/**
	 * @var PhpWord
	 */
	protected $phpWord;

	/**
	 * Constructor
	 *
	 * @param Invoice $invoice
	 */
	public function __construct( Invoice $invoice ) {
		$this->invoice = $invoice;
		$this->order   = $invoice->order;
		$this->phpWord = new PhpWord();

		// Set default font
		$this->phpWord->setDefaultFontName( 'Arial' );
		$this->phpWord->setDefaultFontSize( 10 );
	}

	/**
	 * Generate and return the DOCX content
	 *
	 * @return string Binary content of the DOCX file
	 */
	public function output() {
		$section = $this->phpWord->addSection();

		$this->addHeader( $section );
		$this->addInvoiceTitle( $section );
		$this->addAddressesAndOrderInfo( $section );
		$this->addProductsTable( $section );
		$this->addTotalsTable( $section );
		$this->addCustomerNotes( $section );

		// Save to temp file and return content
		$temp_file = wp_tempnam( 'hp_pdfi_docx_' );
		$writer = IOFactory::createWriter( $this->phpWord, 'Word2007' );
		$writer->save( $temp_file );

		$content = file_get_contents( $temp_file );
		@unlink( $temp_file );

		return $content;
	}

	/**
	 * Add header with logo and shop info
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section
	 */
	protected function addHeader( $section ) {
		$logo_id = get_option( 'hp_pdfi_logo' );
		if ( ! $logo_id ) {
			$wpo_settings = get_option( 'wpo_wcpdf_settings_general' );
			$logo_id = isset( $wpo_settings['header_logo'] ) ? $wpo_settings['header_logo'] : '';
		}

		$shop_name = get_option( 'hp_pdfi_shop_name' ) ?: get_bloginfo( 'name' );
		$shop_address = $this->getShopAddress();

		// Create header table
		$table = $section->addTable();
		$table->addRow();

		// Logo cell
		$cell1 = $table->addCell( 4500 );
		if ( $logo_id ) {
			$logo_path = get_attached_file( $logo_id );
			if ( $logo_path && file_exists( $logo_path ) ) {
				// Get image dimensions to maintain aspect ratio
				$image_info = @getimagesize( $logo_path );
				$max_width = 150; // Max width in points
				
				if ( $image_info ) {
					$orig_width = $image_info[0];
					$orig_height = $image_info[1];
					$ratio = $orig_height / $orig_width;
					$height = $max_width * $ratio;
					
					$cell1->addImage( $logo_path, array(
						'width'  => $max_width,
						'height' => $height,
					) );
				} else {
					// Fallback if can't get dimensions
					$cell1->addImage( $logo_path, array(
						'width' => $max_width,
					) );
				}
			} else {
				$cell1->addText( $shop_name, array( 'bold' => true, 'size' => 14 ) );
			}
		} else {
			$cell1->addText( $shop_name, array( 'bold' => true, 'size' => 14 ) );
		}

		// Shop info cell
		$cell2 = $table->addCell( 4500 );
		$cell2->addText( $shop_name, array( 'bold' => true ), array( 'alignment' => Jc::END ) );
		
		$address_lines = explode( "\n", $shop_address );
		foreach ( $address_lines as $line ) {
			$cell2->addText( trim( $line ), array(), array( 'alignment' => Jc::END ) );
		}

		$section->addTextBreak( 1 );
	}

	/**
	 * Add invoice title
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section
	 */
	protected function addInvoiceTitle( $section ) {
		$section->addText(
			__( 'INVOICE', 'hp-pdf-invoices' ),
			array( 'bold' => true, 'size' => 24 ),
			array( 'alignment' => Jc::START )
		);
		$section->addTextBreak( 1 );
	}

	/**
	 * Add addresses and order information
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section
	 */
	protected function addAddressesAndOrderInfo( $section ) {
		$prefix = get_option( 'hp_pdfi_invoice_prefix', '' );
		$order = $this->order;

		// Create 3-column table for addresses and order info
		$table = $section->addTable( array(
			'borderSize' => 0,
			'cellMargin' => 80,
		) );

		$table->addRow();

		// Billing Address
		$cell1 = $table->addCell( 3000 );
		$cell1->addText( __( 'Billing Address', 'hp-pdf-invoices' ), array( 'bold' => true, 'size' => 11 ) );
		$billing_lines = explode( '<br/>', $order->get_formatted_billing_address() );
		foreach ( $billing_lines as $line ) {
			$cell1->addText( strip_tags( $line ) );
		}
		$cell1->addText( $order->get_billing_email() );
		$cell1->addText( $order->get_billing_phone() );

		// Shipping Address
		$cell2 = $table->addCell( 3000 );
		$cell2->addText( __( 'Shipping Address', 'hp-pdf-invoices' ), array( 'bold' => true, 'size' => 11 ) );
		$shipping_lines = explode( '<br/>', $order->get_formatted_shipping_address() );
		foreach ( $shipping_lines as $line ) {
			$cell2->addText( strip_tags( $line ) );
		}

		// Order Info
		$cell3 = $table->addCell( 3000 );
		$cell3->addText( __( 'Invoice Number:', 'hp-pdf-invoices' ) . ' ' . $prefix . $order->get_order_number() );
		$cell3->addText( __( 'Invoice Date:', 'hp-pdf-invoices' ) . ' ' . date_i18n( get_option( 'date_format' ) ) );
		$cell3->addText( __( 'Order Number:', 'hp-pdf-invoices' ) . ' ' . $order->get_order_number() );
		$cell3->addText( __( 'Order Date:', 'hp-pdf-invoices' ) . ' ' . date_i18n( get_option( 'date_format' ), strtotime( $order->get_date_created() ) ) );
		$cell3->addText( __( 'Payment Method:', 'hp-pdf-invoices' ) . ' ' . $order->get_payment_method_title() );

		$section->addTextBreak( 1 );
	}

	/**
	 * Add products table
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section
	 */
	protected function addProductsTable( $section ) {
		$show_images = $this->invoice->show_images;
		$printer_friendly = $this->invoice->printer_friendly;

		$tableStyle = array(
			'borderSize'  => 6,
			'borderColor' => $printer_friendly ? '000000' : 'CCCCCC',
			'cellMargin'  => 80,
		);

		$headerStyle = array(
			'bgColor' => $printer_friendly ? 'FFFFFF' : 'EEEEEE',
		);

		$boldFont = array( 'bold' => true );

		$table = $section->addTable( $tableStyle );

		// Header row
		$table->addRow();
		if ( $show_images ) {
			$table->addCell( 1000, $headerStyle )->addText( '', $boldFont ); // Image column
		}
		$table->addCell( $show_images ? 4000 : 5000, $headerStyle )->addText( __( 'Product', 'hp-pdf-invoices' ), $boldFont );
		$table->addCell( 1500, $headerStyle )->addText( __( 'SKU', 'hp-pdf-invoices' ), $boldFont );
		$table->addCell( 1000, $headerStyle )->addText( __( 'Qty', 'hp-pdf-invoices' ), $boldFont, array( 'alignment' => Jc::CENTER ) );
		$table->addCell( 1500, $headerStyle )->addText( __( 'Price', 'hp-pdf-invoices' ), $boldFont, array( 'alignment' => Jc::END ) );

		// Data rows - get directly from order to access product
		$order_items = $this->order->get_items();
		$formatted_items = $this->invoice->get_order_items();
		
		$index = 0;
		foreach ( $order_items as $item_id => $order_item ) {
			$table->addRow();
			$product = $order_item->get_product();
			$formatted = isset( $formatted_items[ $index ] ) ? $formatted_items[ $index ] : null;
			
			// Add product image if enabled
			if ( $show_images ) {
				$cell = $table->addCell( 1000 );
				if ( $product ) {
					$image_id = $product->get_image_id();
					if ( $image_id ) {
						$image_path = get_attached_file( $image_id );
						if ( $image_path && file_exists( $image_path ) ) {
							try {
								$cell->addImage( $image_path, array(
									'width'  => 40,
									'height' => 40,
								) );
							} catch ( \Exception $e ) {
								// Skip image if error
							}
						}
					}
				}
			}
			
			$name = $formatted ? $formatted['name'] : $order_item->get_name();
			$sku = $formatted ? $formatted['sku'] : ( $product ? $product->get_sku() : '' );
			$qty = $formatted ? $formatted['quantity'] : $order_item->get_quantity();
			$price = $formatted ? $formatted['price'] : \wc_price( $order_item->get_total() / $order_item->get_quantity() );
			
			$table->addCell( $show_images ? 4000 : 5000 )->addText( strip_tags( $name ) );
			$table->addCell( 1500 )->addText( $sku );
			$table->addCell( 1000 )->addText( $qty, array(), array( 'alignment' => Jc::CENTER ) );
			$table->addCell( 1500 )->addText( strip_tags( $price ), array(), array( 'alignment' => Jc::END ) );
			
			$index++;
		}

		$section->addTextBreak( 1 );
	}

	/**
	 * Add totals table
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section
	 */
	protected function addTotalsTable( $section ) {
		$printer_friendly = $this->invoice->printer_friendly;
		
		$table = $section->addTable( array(
			'borderSize'  => 0,
			'cellMargin'  => 80,
			'alignment'   => Jc::END,
		) );

		// get_totals() already respects show_paid_price option
		$totals = $this->invoice->get_totals();

		foreach ( $totals as $key => $total ) {
			$table->addRow();
			$table->addCell( 5000 ); // Empty spacer cell
			
			// Check if this is a discount line
			$isDiscount = isset( $total['class'] ) && $total['class'] === 'discount-line';
			$isTotal = ( $key === 'total' );
			
			$labelStyle = $isDiscount ? array( 'italic' => true ) : array();
			$valueStyle = $isTotal ? array( 'bold' => true, 'size' => 12 ) : ( $isDiscount ? array( 'italic' => true, 'color' => $printer_friendly ? '000000' : '666666' ) : array( 'bold' => true ) );
			
			$table->addCell( 2500 )->addText( strip_tags( $total['label'] ), $labelStyle, array( 'alignment' => Jc::END ) );
			$table->addCell( 1500 )->addText( strip_tags( $total['value'] ), $valueStyle, array( 'alignment' => Jc::END ) );
		}

		$section->addTextBreak( 1 );
	}

	/**
	 * Add customer notes
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section
	 */
	protected function addCustomerNotes( $section ) {
		$notes = $this->order->get_customer_note();
		if ( empty( $notes ) ) {
			return;
		}

		$section->addText(
			__( 'Customer Notes', 'hp-pdf-invoices' ),
			array( 'bold' => true, 'size' => 11 )
		);
		$section->addText( $notes );
	}

	/**
	 * Get shop address
	 *
	 * @return string
	 */
	protected function getShopAddress() {
		$shop_address = get_option( 'hp_pdfi_shop_address' );
		
		if ( ! $shop_address ) {
			$wpo_settings = get_option( 'wpo_wcpdf_settings_general' );
			if ( isset( $wpo_settings['shop_address_line_1']['default'] ) ) {
				$shop_address = $wpo_settings['shop_address_line_1']['default'];
				if ( ! empty( $wpo_settings['shop_address_line_2']['default'] ) ) {
					$shop_address .= "\n" . $wpo_settings['shop_address_line_2']['default'];
				}
				$city_line = array_filter( array(
					$wpo_settings['shop_address_city']['default'] ?? '',
					$wpo_settings['shop_address_state']['default'] ?? '',
					$wpo_settings['shop_address_postcode']['default'] ?? '',
				) );
				if ( ! empty( $city_line ) ) {
					$shop_address .= "\n" . implode( ' ', $city_line );
				}
				if ( ! empty( $wpo_settings['shop_address_country']['default'] ) ) {
					$shop_address .= "\n" . $wpo_settings['shop_address_country']['default'];
				}
			}
		}

		return $shop_address ?: '';
	}
}

endif;

