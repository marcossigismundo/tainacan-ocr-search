<?php
/**
 * Plugin Name: Tainacan OCR Search
 * Plugin URI:  https://github.com/marcossigismundo/tainacan-ocr-search
 * Description: Torna pesquisáveis no Tainacan o conteúdo de PDFs e imagens escaneadas (fichas médicas, relatórios etc.) usando OCR livre (Tesseract + OCRmyPDF). Adiciona uma página administrativa integrada ao Tainacan com fluxo guiado: verifica dependências, processa documentos em lote, reindexa o conteúdo e habilita a busca textual nativa.
 * Version:     1.0.1
 * Author:      Marcos Sigismundo
 * License:     GPL-2.0-or-later
 * Text Domain: tainacan-ocr-search
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TAINACAN_OCR_SEARCH_VERSION', '1.0.1' );
define( 'TAINACAN_OCR_SEARCH_FILE', __FILE__ );
define( 'TAINACAN_OCR_SEARCH_DIR', plugin_dir_path( __FILE__ ) );
define( 'TAINACAN_OCR_SEARCH_URL', plugin_dir_url( __FILE__ ) );

require_once TAINACAN_OCR_SEARCH_DIR . 'includes/class-ocr-processor.php';
require_once TAINACAN_OCR_SEARCH_DIR . 'includes/class-ocr-rest.php';

/**
 * Bootstrap: a página admin só é registrada quando o Tainacan está carregado,
 * porque ela estende \Tainacan\Pages.
 */
add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'tainacan-ocr-search', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( '\Tainacan\Pages' ) ) {
		add_action( 'admin_notices', function () {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p><strong>Tainacan OCR Search:</strong> ';
			esc_html_e( 'o plugin Tainacan precisa estar instalado e ativado.', 'tainacan-ocr-search' );
			echo '</p></div>';
		} );
		return;
	}

	require_once TAINACAN_OCR_SEARCH_DIR . 'includes/class-ocr-admin-page.php';
	\Tainacan_OCR_Search\Admin_Page::get_instance();
	new \Tainacan_OCR_Search\REST_Controller();
}, 20 );

/**
 * Hook opcional: processa automaticamente PDFs e imagens recém-enviados
 * (apenas se o usuário habilitou nas configurações).
 */
add_action( 'add_attachment', function ( $attachment_id ) {
	$opts = get_option( 'tainacan_ocr_search_options', array() );
	if ( empty( $opts['auto_process'] ) ) {
		return;
	}
	if ( ! \Tainacan_OCR_Search\Processor::is_supported_attachment( $attachment_id ) ) {
		return;
	}
	wp_schedule_single_event( time() + 5, 'tainacan_ocr_search_process_single', array( $attachment_id ) );
} );

add_action( 'tainacan_ocr_search_process_single', function ( $attachment_id ) {
	$processor = new \Tainacan_OCR_Search\Processor();
	$processor->process_attachment( (int) $attachment_id );
} );

register_activation_hook( __FILE__, function () {
	$defaults = array(
		'tesseract_path' => '',
		'ocrmypdf_path'  => '',
		'language'       => 'por',
		'auto_process'   => 0,
		'deskew'         => 1,
		'clean'          => 1,
		'force_ocr'      => 0,
	);
	$existing = get_option( 'tainacan_ocr_search_options', array() );
	update_option( 'tainacan_ocr_search_options', wp_parse_args( $existing, $defaults ) );
} );
