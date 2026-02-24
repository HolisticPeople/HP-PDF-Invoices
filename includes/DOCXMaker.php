<?php
/**
 * DOCX Maker - Generates Word documents for invoices
 * 
 * @package HP_PDF_Invoices
 * @version 1.2.21
 * @author Amnon Manneberg
 * Fix: escape & < > for XML (PhpWord does not escape ampersands in text - breaks DOCX).
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

	/** @var bool Whether to log every text segment for debugging order-specific corruption */
	protected $debug_docx = false;

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
		
		// Set default paragraph style for tighter line spacing
		$this->phpWord->setDefaultParagraphStyle( array(
			'spaceAfter'  => 0,
			'spaceBefore' => 0,
			'lineHeight'  => 1.0,
		) );
	}

	/**
	 * Generate and return the DOCX content
	 *
	 * @return string Binary content of the DOCX file
	 */
	public function output() {
		try {
			// Debug: add ?hp_pdfi_docx_debug=1 or ?hp_pdfi_docx_debug=128627 to the DOCX export URL
			$this->debug_docx = isset( $_GET['hp_pdfi_docx_debug'] ) && (
				$_GET['hp_pdfi_docx_debug'] === '1' || (int) $_GET['hp_pdfi_docx_debug'] === (int) $this->order->get_id()
			);

			error_log( 'HP-PDF-Invoices DOCX: Starting generation for order ' . $this->order->get_id() . ( $this->debug_docx ? ' (debug on)' : '' ) );
			
			$section = $this->phpWord->addSection();

			error_log( 'HP-PDF-Invoices DOCX: Adding header' );
			$this->addHeader( $section );
			
			error_log( 'HP-PDF-Invoices DOCX: Adding title' );
			$this->addInvoiceTitle( $section );
			
			error_log( 'HP-PDF-Invoices DOCX: Adding addresses' );
			$this->addAddressesAndOrderInfo( $section );
			
			error_log( 'HP-PDF-Invoices DOCX: Adding products' );
			$this->addProductsTable( $section );
			
			error_log( 'HP-PDF-Invoices DOCX: Adding totals (show_paid_price=' . ($this->invoice->show_paid_price ? 'yes' : 'no') . ')' );
			$this->addTotalsTable( $section );
			
			error_log( 'HP-PDF-Invoices DOCX: Adding notes' );
			$this->addCustomerNotes( $section );

			// Save to temp file and return content
			$temp_file = sys_get_temp_dir() . '/hp_pdfi_docx_' . time() . '_' . wp_rand() . '.docx';
			error_log( 'HP-PDF-Invoices DOCX: Saving to ' . $temp_file );
			
			$writer = IOFactory::createWriter( $this->phpWord, 'Word2007' );
			$writer->save( $temp_file );

			if ( ! file_exists( $temp_file ) ) {
				error_log( 'HP-PDF-Invoices DOCX: Temp file was not created!' );
				return false;
			}

			$content = file_get_contents( $temp_file );
			$size = strlen( $content );
			@unlink( $temp_file );

			error_log( 'HP-PDF-Invoices DOCX: Generated successfully, size=' . $size . ' bytes' );
			return $content;
			
		} catch ( \Exception $e ) {
			error_log( 'HP-PDF-Invoices DOCX Error: ' . $e->getMessage() );
			error_log( 'HP-PDF-Invoices DOCX Error Trace: ' . $e->getTraceAsString() );
			return false;
		}
	}

	/**
	 * Sanitize text for DOCX output
	 * Removes HTML entities and characters invalid in XML 1.0 / Word OOXML
	 *
	 * @param string $text
	 * @param string $label Optional label for debug logging (when hp_pdfi_docx_debug=1).
	 * @return string
	 */
	protected function sanitizeText( $text, $label = '' ) {
		$raw = $text;
		if ( $text === null || $text === '' ) {
			return '';
		}
		
		// Convert to string if not already
		$text = (string) $text;
		
		// Use WordPress functions for better entity handling
		$text = wp_strip_all_tags( $text );
		
		// Decode ALL HTML entities including numeric ones like &#36;
		$text = wp_specialchars_decode( $text, ENT_QUOTES );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		
		// Handle numeric entities that might remain (decode to valid UTF-8; chr() would corrupt > 127)
		$text = preg_replace_callback( '/&#(\d+);/', function( $matches ) {
			$codepoint = (int) $matches[1];
			if ( $codepoint < 0 || $codepoint > 0x10FFFF ) {
				return '';
			}
			if ( $codepoint < 128 ) {
				return chr( $codepoint );
			}
			return \mb_chr( $codepoint, 'UTF-8' );
		}, $text );
		
		// Replace non-breaking spaces with regular spaces
		$text = str_replace( array( "\xC2\xA0", '&nbsp;' ), ' ', $text );
		
		// Remove control characters except newlines and tabs
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		
		// Remove XML 1.0 invalid codepoints (surrogates, U+FFFE, U+FFFF)
		$text = preg_replace( '/[\x{D800}-\x{DFFF}\x{FFFE}\x{FFFF}]/u', '', $text );
		
		// Normalize whitespace
		$text = preg_replace( '/\s+/u', ' ', $text );
		
		// Trim
		$text = trim( $text );

		// Ensure valid UTF-8 for Word/XML (strip any invalid sequences)
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}

		// Escape XML special chars so PhpWord output is valid (PhpWord does not escape & in text)
		$text = str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );

		if ( $this->debug_docx && $label !== '' ) {
			$raw_str = is_scalar( $raw ) ? (string) $raw : '';
			$raw_repr = mb_check_encoding( $raw_str, 'UTF-8' ) ? $raw_str : '[invalid UTF-8]';
			$hex = bin2hex( $raw_repr );
			error_log( 'HP-PDF-Invoices DOCX debug: ' . $label . ' | raw=' . json_encode( $raw_repr ) . ' | sanitized=' . json_encode( $text ) . ( $hex !== '' ? ' | hex=' . substr( $hex, 0, 200 ) : '' ) );
		}
		
		return $text;
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
				$max_width = 50; // Max width in points (matching PDF proportions)
				
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

		// Shop info cell - tight line spacing
		$tightPara = array( 'alignment' => Jc::END, 'spaceAfter' => 0, 'spaceBefore' => 0 );
		$cell2 = $table->addCell( 4500 );
		$cell2->addText( $this->sanitizeText( $shop_name, 'header_shop_name' ), array( 'bold' => true ), $tightPara );
		
		$address_lines = explode( "\n", $shop_address );
		foreach ( $address_lines as $i => $line ) {
			$cell2->addText( $this->sanitizeText( trim( $line ), 'header_address_' . $i ), array(), $tightPara );
		}
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
			array( 'alignment' => Jc::START, 'spaceAfter' => 60 )
		);
	}

	/**
	 * Add addresses and order information
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section
	 */
	protected function addAddressesAndOrderInfo( $section ) {
		$prefix = get_option( 'hp_pdfi_invoice_prefix', '' );
		$order = $this->order;

		// Tight paragraph style - no extra spacing between lines
		$tight = array( 'spaceAfter' => 0, 'spaceBefore' => 0 );
		$tightBold = array( 'spaceAfter' => 20, 'spaceBefore' => 0 ); // Small gap after headers

		// Create 3-column table for addresses and order info
		$table = $section->addTable( array(
			'borderSize' => 0,
			'cellMargin' => 80,
		) );

		$table->addRow();

		// Billing Address
		$cell1 = $table->addCell( 3000 );
		$cell1->addText( __( 'Billing Address', 'hp-pdf-invoices' ), array( 'bold' => true, 'size' => 11 ), $tightBold );
		$billing_lines = explode( '<br/>', $order->get_formatted_billing_address() );
		foreach ( $billing_lines as $i => $line ) {
			$cell1->addText( $this->sanitizeText( $line, 'billing_line_' . $i ), array(), $tight );
		}
		$cell1->addText( $this->sanitizeText( $order->get_billing_email(), 'billing_email' ), array(), $tight );
		$cell1->addText( $this->sanitizeText( $order->get_billing_phone(), 'billing_phone' ), array(), $tight );

		// Shipping Address
		$cell2 = $table->addCell( 3000 );
		$cell2->addText( __( 'Shipping Address', 'hp-pdf-invoices' ), array( 'bold' => true, 'size' => 11 ), $tightBold );
		$shipping_lines = explode( '<br/>', $order->get_formatted_shipping_address() );
		foreach ( $shipping_lines as $i => $line ) {
			$cell2->addText( $this->sanitizeText( $line, 'shipping_line_' . $i ), array(), $tight );
		}

		// Order Info
		$cell3 = $table->addCell( 3000 );
		$cell3->addText( __( 'Invoice Number:', 'hp-pdf-invoices' ) . ' ' . $prefix . $order->get_order_number(), array(), $tight );
		$cell3->addText( __( 'Invoice Date:', 'hp-pdf-invoices' ) . ' ' . date_i18n( get_option( 'date_format' ) ), array(), $tight );
		$cell3->addText( __( 'Order Number:', 'hp-pdf-invoices' ) . ' ' . $order->get_order_number(), array(), $tight );
		$cell3->addText( __( 'Order Date:', 'hp-pdf-invoices' ) . ' ' . date_i18n( get_option( 'date_format' ), strtotime( $order->get_date_created() ) ), array(), $tight );
		$cell3->addText( __( 'Payment Method:', 'hp-pdf-invoices' ) . ' ' . $this->sanitizeText( $order->get_payment_method_title(), 'payment_method' ), array(), $tight );
	}

	/**
	 * Add products table
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section
	 */
	protected function addProductsTable( $section ) {
		$printer_friendly = $this->invoice->printer_friendly;
		$show_paid_price = $this->invoice->show_paid_price;
		$currency = $this->order->get_currency();

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
		$table->addCell( 4000, $headerStyle )->addText( __( 'Product', 'hp-pdf-invoices' ), $boldFont );
		$table->addCell( 1200, $headerStyle )->addText( __( 'SKU', 'hp-pdf-invoices' ), $boldFont );
		$table->addCell( 800, $headerStyle )->addText( __( 'Qty', 'hp-pdf-invoices' ), $boldFont, array( 'alignment' => Jc::CENTER ) );
		$table->addCell( 1500, $headerStyle )->addText( __( 'Price', 'hp-pdf-invoices' ), $boldFont, array( 'alignment' => Jc::END ) );
		$table->addCell( 1500, $headerStyle )->addText( __( 'Total', 'hp-pdf-invoices' ), $boldFont, array( 'alignment' => Jc::END ) );

		// Data rows
		foreach ( $this->order->get_items() as $item ) {
			$product = $item->get_product();
			$quantity = $item->get_quantity();
			$line_subtotal = (float) $item->get_subtotal(); // Original total
			$line_total = (float) $item->get_total(); // Paid total (after discounts)
			
			$original_unit = $quantity > 0 ? $line_subtotal / $quantity : 0;
			$paid_unit = $quantity > 0 ? $line_total / $quantity : 0;
			$has_discount = $line_total < $line_subtotal;
			$discount_percent = (float) $item->get_meta( '_eao_item_discount_percent', true );
			
			$table->addRow();
			$table->addCell( 4000 )->addText( $this->sanitizeText( $item->get_name(), 'product_name' ) );
			$table->addCell( 1200 )->addText( $product ? $this->sanitizeText( $product->get_sku(), 'product_sku' ) : '' );
			$table->addCell( 800 )->addText( $quantity, array(), array( 'alignment' => Jc::CENTER ) );
			
			// Price cell - show both prices when item has a discount
			$priceCell = $table->addCell( 1500 );
			if ( $has_discount ) {
				// Show paid price, then original with strikethrough
				$priceCell->addText( $this->formatMoney( $paid_unit, $currency ), array(), array( 'alignment' => Jc::END ) );
				$priceCell->addText( $this->formatMoney( $original_unit, $currency ), array( 'strikethrough' => true, 'color' => '999999', 'size' => 8 ), array( 'alignment' => Jc::END ) );
				if ( $discount_percent > 0 ) {
					$priceCell->addText( '(' . round( $discount_percent ) . '% off)', array( 'color' => '4CAF50', 'size' => 8, 'italic' => true ), array( 'alignment' => Jc::END ) );
				}
			} elseif ( ! $show_paid_price ) {
				$priceCell->addText( $this->formatMoney( $original_unit, $currency ), array(), array( 'alignment' => Jc::END ) );
			} else {
				$priceCell->addText( $this->formatMoney( $paid_unit, $currency ), array(), array( 'alignment' => Jc::END ) );
			}
			
			// Line Total cell - show both when discounted
			$totalCell = $table->addCell( 1500 );
			if ( $has_discount ) {
				$totalCell->addText( $this->formatMoney( $line_total, $currency ), array(), array( 'alignment' => Jc::END ) );
				$totalCell->addText( $this->formatMoney( $line_subtotal, $currency ), array( 'strikethrough' => true, 'color' => '999999', 'size' => 8 ), array( 'alignment' => Jc::END ) );
			} elseif ( $show_paid_price ) {
				$totalCell->addText( $this->formatMoney( $line_total, $currency ), array(), array( 'alignment' => Jc::END ) );
			} else {
				$totalCell->addText( $this->formatMoney( $line_subtotal, $currency ), array(), array( 'alignment' => Jc::END ) );
			}
		}

		$section->addTextBreak( 1 );
	}

	/**
	 * Add totals table
	 *
	 * @param \PhpOffice\PhpWord\Element\Section $section
	 */
	protected function addTotalsTable( $section ) {
		$order = $this->order;
		$currency = $order->get_currency();
		
		$table = $section->addTable( array(
			'borderSize'  => 0,
			'cellMargin'  => 80,
			'alignment'   => Jc::END,
		) );

		// Build totals from order data with full discount breakdown like EAO
		$rows = array();
		
		// Calculate subtotal from original item prices
		$subtotal = 0;
		foreach ( $order->get_items() as $item ) {
			$subtotal += (float) $item->get_subtotal(); // Original prices
		}
		
		$rows[] = array( 'label' => __( 'Subtotal', 'hp-pdf-invoices' ), 'value' => $this->formatMoney( $subtotal, $currency ) );
		
		// Get discount breakdown from Invoice class and track total discounts
		$discounts = $this->invoice->get_discount_summary();
		$total_discount = 0;
		foreach ( $discounts as $discount ) {
			$rows[] = array( 
				'label' => rtrim( $discount['label'], ':' ), 
				'value' => '-' . $this->formatMoney( $discount['raw'], $currency ), 
				'italic' => true 
			);
			$total_discount += $discount['raw'];
		}
		
		// Shipping - include method name, cleaned of {{}} markers
		$shipping = (float) $order->get_shipping_total();
		if ( $shipping > 0 ) {
			$shipping_method = $order->get_shipping_method();
			// Clean {{CARRIER}} template markers from shipping method name
			$shipping_method = trim( preg_replace( '/\{\{[^}]+\}\}\s*/', '', $shipping_method ) );
			$shipping_label = __( 'Shipping', 'hp-pdf-invoices' );
			if ( ! empty( $shipping_method ) ) {
				$shipping_label .= ' (' . $shipping_method . ')';
			}
			$rows[] = array( 'label' => $shipping_label, 'value' => $this->formatMoney( $shipping, $currency ) );
		}
		
		// Tax
		$tax = (float) $order->get_total_tax();
		if ( $tax > 0 ) {
			$rows[] = array( 'label' => __( 'Tax', 'hp-pdf-invoices' ), 'value' => $this->formatMoney( $tax, $currency ) );
		}
		
		// Calculate Grand Total the same way EAO does:
		// Grand Total = Subtotal - Product Discount - Points Discount + Shipping + Tax
		$grand_total = $subtotal - $total_discount + $shipping + $tax;
		$rows[] = array( 'label' => __( 'Total', 'hp-pdf-invoices' ), 'value' => $this->formatMoney( $grand_total, $currency ), 'bold' => true );

		// Add rows to table (sanitize labels/values for XML safety, e.g. discount labels, currency symbols)
		foreach ( $rows as $row ) {
			$table->addRow();
			$table->addCell( 5000 ); // Empty spacer cell
			
			$labelStyle = isset( $row['italic'] ) ? array( 'italic' => true ) : array();
			$valueStyle = isset( $row['bold'] ) ? array( 'bold' => true, 'size' => 12 ) : ( isset( $row['italic'] ) ? array( 'italic' => true ) : array( 'bold' => true ) );
			
			$table->addCell( 2500 )->addText( $this->sanitizeText( $row['label'], 'totals_label' ), $labelStyle, array( 'alignment' => Jc::END ) );
			$table->addCell( 1500 )->addText( $this->sanitizeText( $row['value'], 'totals_value' ), $valueStyle, array( 'alignment' => Jc::END ) );
		}

		$section->addTextBreak( 1 );
	}
	
	/**
	 * Format money without HTML
	 *
	 * @param float $amount
	 * @param string $currency
	 * @return string
	 */
	protected function formatMoney( $amount, $currency = 'USD' ) {
		$symbol = get_woocommerce_currency_symbol( $currency );
		return $symbol . number_format( $amount, 2 );
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
		$section->addText( $this->sanitizeText( $notes, 'customer_note' ) );
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

