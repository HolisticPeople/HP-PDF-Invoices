<?php
/**
 * Excel Maker - Generates Excel spreadsheets for invoices
 * 
 * @package HP_PDF_Invoices
 * @version 1.2.0
 * @author Amnon Manneberg
 */
namespace HP_PDFI;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'HP_PDFI\ExcelMaker' ) ) :

class ExcelMaker {

	/**
	 * @var Invoice
	 */
	protected $invoice;

	/**
	 * @var \WC_Order
	 */
	protected $order;

	/**
	 * @var Spreadsheet
	 */
	protected $spreadsheet;

	/**
	 * Constructor
	 *
	 * @param Invoice $invoice
	 */
	public function __construct( Invoice $invoice ) {
		$this->invoice     = $invoice;
		$this->order       = $invoice->order;
		$this->spreadsheet = new Spreadsheet();
	}

	/**
	 * Generate and return the Excel content
	 *
	 * @return string Binary content of the XLSX file
	 */
	public function output() {
		// Create Products sheet (first sheet, already exists)
		$productsSheet = $this->spreadsheet->getActiveSheet();
		$productsSheet->setTitle( __( 'Products', 'hp-pdf-invoices' ) );
		$this->buildProductsSheet( $productsSheet );

		// Create Order Details sheet
		$orderDetailsSheet = $this->spreadsheet->createSheet();
		$orderDetailsSheet->setTitle( __( 'Order Details', 'hp-pdf-invoices' ) );
		$this->buildOrderDetailsSheet( $orderDetailsSheet );

		// Set first sheet as active
		$this->spreadsheet->setActiveSheetIndex( 0 );

		// Save to temp file and return content
		$temp_file = wp_tempnam( 'hp_pdfi_xlsx_' );
		$writer = new Xlsx( $this->spreadsheet );
		$writer->save( $temp_file );

		$content = file_get_contents( $temp_file );
		@unlink( $temp_file );

		return $content;
	}

	/**
	 * Build the Products sheet
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
	 */
	protected function buildProductsSheet( $sheet ) {
		// Header styles
		$headerStyle = array(
			'font' => array(
				'bold' => true,
				'color' => array( 'rgb' => 'FFFFFF' ),
			),
			'fill' => array(
				'fillType' => Fill::FILL_SOLID,
				'startColor' => array( 'rgb' => '4A5568' ),
			),
			'alignment' => array(
				'horizontal' => Alignment::HORIZONTAL_CENTER,
				'vertical' => Alignment::VERTICAL_CENTER,
			),
			'borders' => array(
				'allBorders' => array(
					'borderStyle' => Border::BORDER_THIN,
				),
			),
		);

		$dataStyle = array(
			'borders' => array(
				'allBorders' => array(
					'borderStyle' => Border::BORDER_THIN,
					'color' => array( 'rgb' => 'CCCCCC' ),
				),
			),
		);

		// Set column headers
		$headers = array(
			'A1' => __( 'Product Name', 'hp-pdf-invoices' ),
			'B1' => __( 'SKU', 'hp-pdf-invoices' ),
			'C1' => __( 'Quantity', 'hp-pdf-invoices' ),
			'D1' => __( 'Unit Price', 'hp-pdf-invoices' ),
			'E1' => __( 'Total', 'hp-pdf-invoices' ),
		);

		foreach ( $headers as $cell => $value ) {
			$sheet->setCellValue( $cell, $value );
		}

		// Apply header styles
		$sheet->getStyle( 'A1:E1' )->applyFromArray( $headerStyle );

		// Set column widths
		$sheet->getColumnDimension( 'A' )->setWidth( 40 );
		$sheet->getColumnDimension( 'B' )->setWidth( 15 );
		$sheet->getColumnDimension( 'C' )->setWidth( 10 );
		$sheet->getColumnDimension( 'D' )->setWidth( 15 );
		$sheet->getColumnDimension( 'E' )->setWidth( 15 );

		// Add data rows
		$items = $this->invoice->get_raw_order_items();
		$row = 2;

		foreach ( $items as $item ) {
			$sheet->setCellValue( 'A' . $row, $item['name'] );
			$sheet->setCellValue( 'B' . $row, $item['sku'] );
			$sheet->setCellValue( 'C' . $row, $item['quantity'] );
			$sheet->setCellValue( 'D' . $row, $item['unit_price'] );
			$sheet->setCellValue( 'E' . $row, $item['line_total'] );

			// Format currency columns
			$sheet->getStyle( 'D' . $row )->getNumberFormat()->setFormatCode( '"$"#,##0.00' );
			$sheet->getStyle( 'E' . $row )->getNumberFormat()->setFormatCode( '"$"#,##0.00' );

			$row++;
		}

		// Apply data styles
		if ( $row > 2 ) {
			$sheet->getStyle( 'A2:E' . ( $row - 1 ) )->applyFromArray( $dataStyle );
		}

		// Add totals section
		$row++; // Empty row
		$totals = $this->invoice->get_raw_totals();

		foreach ( $totals as $total ) {
			$sheet->setCellValue( 'D' . $row, $total['label'] );
			$sheet->setCellValue( 'E' . $row, $total['raw_value'] );
			$sheet->getStyle( 'D' . $row )->getFont()->setBold( true );
			$sheet->getStyle( 'E' . $row )->getNumberFormat()->setFormatCode( '"$"#,##0.00' );
			
			if ( $total['key'] === 'total' ) {
				$sheet->getStyle( 'D' . $row . ':E' . $row )->getFont()->setBold( true )->setSize( 12 );
			}
			$row++;
		}
	}

	/**
	 * Build the Order Details sheet
	 *
	 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
	 */
	protected function buildOrderDetailsSheet( $sheet ) {
		$order = $this->order;
		$prefix = get_option( 'hp_pdfi_invoice_prefix', '' );
		$shop_name = get_option( 'hp_pdfi_shop_name' ) ?: get_bloginfo( 'name' );

		// Header style
		$headerStyle = array(
			'font' => array( 'bold' => true ),
			'fill' => array(
				'fillType' => Fill::FILL_SOLID,
				'startColor' => array( 'rgb' => 'E2E8F0' ),
			),
		);

		$sectionStyle = array(
			'font' => array( 'bold' => true, 'size' => 12 ),
		);

		// Set column widths
		$sheet->getColumnDimension( 'A' )->setWidth( 25 );
		$sheet->getColumnDimension( 'B' )->setWidth( 40 );

		$row = 1;

		// Shop Info Section
		$sheet->setCellValue( 'A' . $row, __( 'Shop Information', 'hp-pdf-invoices' ) );
		$sheet->getStyle( 'A' . $row )->applyFromArray( $sectionStyle );
		$row++;

		$sheet->setCellValue( 'A' . $row, __( 'Shop Name:', 'hp-pdf-invoices' ) );
		$sheet->setCellValue( 'B' . $row, $shop_name );
		$sheet->getStyle( 'A' . $row )->applyFromArray( $headerStyle );
		$row++;

		$row++; // Empty row

		// Invoice Info Section
		$sheet->setCellValue( 'A' . $row, __( 'Invoice Information', 'hp-pdf-invoices' ) );
		$sheet->getStyle( 'A' . $row )->applyFromArray( $sectionStyle );
		$row++;

		$invoiceData = array(
			__( 'Invoice Number:', 'hp-pdf-invoices' ) => $prefix . $order->get_order_number(),
			__( 'Invoice Date:', 'hp-pdf-invoices' )   => date_i18n( get_option( 'date_format' ) ),
			__( 'Order Number:', 'hp-pdf-invoices' )   => $order->get_order_number(),
			__( 'Order Date:', 'hp-pdf-invoices' )     => date_i18n( get_option( 'date_format' ), strtotime( $order->get_date_created() ) ),
			__( 'Order Status:', 'hp-pdf-invoices' )   => wc_get_order_status_name( $order->get_status() ),
			__( 'Payment Method:', 'hp-pdf-invoices' ) => $order->get_payment_method_title(),
		);

		foreach ( $invoiceData as $label => $value ) {
			$sheet->setCellValue( 'A' . $row, $label );
			$sheet->setCellValue( 'B' . $row, $value );
			$sheet->getStyle( 'A' . $row )->applyFromArray( $headerStyle );
			$row++;
		}

		$row++; // Empty row

		// Billing Address Section
		$sheet->setCellValue( 'A' . $row, __( 'Billing Address', 'hp-pdf-invoices' ) );
		$sheet->getStyle( 'A' . $row )->applyFromArray( $sectionStyle );
		$row++;

		$billingData = array(
			__( 'Name:', 'hp-pdf-invoices' )    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			__( 'Company:', 'hp-pdf-invoices' ) => $order->get_billing_company(),
			__( 'Address:', 'hp-pdf-invoices' ) => $order->get_billing_address_1() . ( $order->get_billing_address_2() ? ', ' . $order->get_billing_address_2() : '' ),
			__( 'City:', 'hp-pdf-invoices' )    => $order->get_billing_city(),
			__( 'State:', 'hp-pdf-invoices' )   => $order->get_billing_state(),
			__( 'Postcode:', 'hp-pdf-invoices' ) => $order->get_billing_postcode(),
			__( 'Country:', 'hp-pdf-invoices' ) => $order->get_billing_country(),
			__( 'Email:', 'hp-pdf-invoices' )   => $order->get_billing_email(),
			__( 'Phone:', 'hp-pdf-invoices' )   => $order->get_billing_phone(),
		);

		foreach ( $billingData as $label => $value ) {
			if ( ! empty( $value ) ) {
				$sheet->setCellValue( 'A' . $row, $label );
				$sheet->setCellValue( 'B' . $row, $value );
				$sheet->getStyle( 'A' . $row )->applyFromArray( $headerStyle );
				$row++;
			}
		}

		$row++; // Empty row

		// Shipping Address Section
		$sheet->setCellValue( 'A' . $row, __( 'Shipping Address', 'hp-pdf-invoices' ) );
		$sheet->getStyle( 'A' . $row )->applyFromArray( $sectionStyle );
		$row++;

		$shippingData = array(
			__( 'Name:', 'hp-pdf-invoices' )    => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
			__( 'Company:', 'hp-pdf-invoices' ) => $order->get_shipping_company(),
			__( 'Address:', 'hp-pdf-invoices' ) => $order->get_shipping_address_1() . ( $order->get_shipping_address_2() ? ', ' . $order->get_shipping_address_2() : '' ),
			__( 'City:', 'hp-pdf-invoices' )    => $order->get_shipping_city(),
			__( 'State:', 'hp-pdf-invoices' )   => $order->get_shipping_state(),
			__( 'Postcode:', 'hp-pdf-invoices' ) => $order->get_shipping_postcode(),
			__( 'Country:', 'hp-pdf-invoices' ) => $order->get_shipping_country(),
		);

		foreach ( $shippingData as $label => $value ) {
			if ( ! empty( trim( $value ) ) ) {
				$sheet->setCellValue( 'A' . $row, $label );
				$sheet->setCellValue( 'B' . $row, $value );
				$sheet->getStyle( 'A' . $row )->applyFromArray( $headerStyle );
				$row++;
			}
		}

		$row++; // Empty row

		// Totals Section
		$sheet->setCellValue( 'A' . $row, __( 'Order Totals', 'hp-pdf-invoices' ) );
		$sheet->getStyle( 'A' . $row )->applyFromArray( $sectionStyle );
		$row++;

		$totals = $this->invoice->get_raw_totals();
		foreach ( $totals as $total ) {
			$sheet->setCellValue( 'A' . $row, $total['label'] );
			$sheet->setCellValue( 'B' . $row, $total['raw_value'] );
			$sheet->getStyle( 'A' . $row )->applyFromArray( $headerStyle );
			$sheet->getStyle( 'B' . $row )->getNumberFormat()->setFormatCode( '"$"#,##0.00' );
			$row++;
		}

		$row++; // Empty row

		// Customer Notes Section
		$notes = $order->get_customer_note();
		if ( ! empty( $notes ) ) {
			$sheet->setCellValue( 'A' . $row, __( 'Customer Notes', 'hp-pdf-invoices' ) );
			$sheet->getStyle( 'A' . $row )->applyFromArray( $sectionStyle );
			$row++;

			$sheet->setCellValue( 'A' . $row, $notes );
			$sheet->mergeCells( 'A' . $row . ':B' . $row );
			$sheet->getStyle( 'A' . $row )->getAlignment()->setWrapText( true );
		}
	}
}

endif;

