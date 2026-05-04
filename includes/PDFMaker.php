<?php
namespace HP_PDFI;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'HP_PDFI\PDFMaker' ) ) :

class PDFMaker {

	public $html;
	public $settings;

	public function __construct( $html, $settings = array() ) {
		$this->html = $html;
		$default_settings = array(
			'paper_size'        => 'A4',
			'paper_orientation' => 'portrait',
		);
		$this->settings = array_merge( $default_settings, $settings );
	}

	public function output() {
		if ( empty( $this->html ) ) {
			return null;
		}

		$upload_dir = wp_upload_dir();
		$tmp_path = $upload_dir['basedir'] . '/hp-pdfi-temp';
		if ( ! file_exists( $tmp_path ) ) {
			wp_mkdir_p( $tmp_path );
		}

		$dompdf_class  = class_exists( '\\WPO\\IPS\\Vendor\\Dompdf\\Dompdf' ) ? '\\WPO\\IPS\\Vendor\\Dompdf\\Dompdf' : '\\Dompdf\\Dompdf';
		$options_class = class_exists( '\\WPO\\IPS\\Vendor\\Dompdf\\Options' ) ? '\\WPO\\IPS\\Vendor\\Dompdf\\Options' : '\\Dompdf\\Options';

		$options = new $options_class( array(
			'tempDir'              => $tmp_path,
			'fontDir'              => $tmp_path . '/fonts',
			'fontCache'            => $tmp_path . '/fonts',
			'chroot'               => array( ABSPATH, WP_CONTENT_DIR ),
			'isRemoteEnabled'      => true,
			'isHtml5ParserEnabled' => true,
			'defaultFont'          => 'dejavu sans',
		) );

		if ( ! file_exists( $tmp_path . '/fonts' ) ) {
			wp_mkdir_p( $tmp_path . '/fonts' );
		}

		$dompdf = new $dompdf_class( $options );
		$dompdf->loadHtml( $this->html );
		$dompdf->setPaper( $this->settings['paper_size'], $this->settings['paper_orientation'] );
		$dompdf->render();

		return $dompdf->output();
	}
}

endif;
