<?php
/**
 * Plugin Name: HP PDF Invoices
 * Description: Tailored PDF invoices for Holistic People. Supports PDF, Word (DOCX), and Excel (XLSX) export formats.
 * Version:     1.2.0
 * Author:      Holistic People
 * Text Domain: hp-pdf-invoices
 * WC requires at least: 3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

define( 'HP_PDFI_VERSION', '1.2.0' );
define( 'HP_PDFI_PATH', plugin_dir_path( __FILE__ ) );
define( 'HP_PDFI_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main Plugin Class
 */
class HP_PDF_Invoices {

	protected static $_instance = null;

	public $admin;
	public $assets;
	public $frontend;

	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function __construct() {
		// Simple autoloader
		spl_autoload_register( array( $this, 'autoloader' ) );

		add_action( 'init', array( $this, 'init' ) );
	}

	public function autoloader( $class ) {
		if ( strpos( $class, 'HP_PDFI\\' ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, 8 );
		$file = HP_PDFI_PATH . 'includes/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Load Composer if it exists
		if ( file_exists( HP_PDFI_PATH . 'vendor/autoload.php' ) ) {
			require_once HP_PDFI_PATH . 'vendor/autoload.php';
		}

		// Load Strauss autoloader if it exists (for DomPDF and other prefixed vendors)
		if ( file_exists( HP_PDFI_PATH . 'vendor/strauss/autoload.php' ) ) {
			require_once HP_PDFI_PATH . 'vendor/strauss/autoload.php';
		}

		$this->admin    = new HP_PDFI\Admin();
		$this->assets   = new HP_PDFI\Assets();
		$this->frontend = new HP_PDFI\Frontend();
	}
}

function HP_PDFI() {
	return HP_PDF_Invoices::instance();
}

// Start the plugin
HP_PDFI();

