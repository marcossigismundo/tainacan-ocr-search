<?php
/**
 * Plugin Name: Tainacan OCR Search
 * Plugin URI:  https://github.com/marcossigismundo/tainacan-ocr-search
 * Description: Torna pesquisáveis no Tainacan o conteúdo de PDFs e imagens escaneadas (fichas médicas, relatórios etc.) usando OCR livre (Tesseract + OCRmyPDF). Adiciona uma página administrativa integrada ao Tainacan com fluxo guiado: verifica dependências, processa documentos em lote, reindexa o conteúdo e habilita a busca textual nativa.
 * Version:     1.2.0
 * Author:      Marcos Sigismundo
 * License:     GPL-2.0-or-later
 * Text Domain: tainacan-ocr-search
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TAINACAN_OCR_SEARCH_VERSION', '1.2.0' );
define( 'TAINACAN_OCR_SEARCH_FILE', __FILE__ );
define( 'TAINACAN_OCR_SEARCH_DIR', plugin_dir_path( __FILE__ ) );
define( 'TAINACAN_OCR_SEARCH_URL', plugin_dir_url( __FILE__ ) );

require_once TAINACAN_OCR_SEARCH_DIR . 'includes/class-ocr-processor.php';
require_once TAINACAN_OCR_SEARCH_DIR . 'includes/class-ocr-rest.php';
require_once TAINACAN_OCR_SEARCH_DIR . 'includes/class-ocr-queue.php';

// Worker da fila de OCR (segundo plano).
add_action( \Tainacan_OCR_Search\Queue::CRON_HOOK, array( '\Tainacan_OCR_Search\Queue', 'run' ) );

/**
 * Bootstrap: a página admin só é registrada quando o Tainacan está carregado,
 * porque ela estende \Tainacan\Pages.
 */
add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'tainacan-ocr-search', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( ! class_exists( '\Tainacan\Pages' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( ! current_user_can( 'manage_options' ) ) {
						return;
					}
					echo '<div class="notice notice-error"><p><strong>Tainacan OCR Search:</strong> ';
					esc_html_e( 'o plugin Tainacan precisa estar instalado e ativado.', 'tainacan-ocr-search' );
					echo '</p></div>';
				}
			);
			return;
		}

		require_once TAINACAN_OCR_SEARCH_DIR . 'includes/class-ocr-admin-page.php';
		\Tainacan_OCR_Search\Admin_Page::get_instance();
		new \Tainacan_OCR_Search\REST_Controller();

		// Quadro de OCR dentro da edição do item.
		require_once TAINACAN_OCR_SEARCH_DIR . 'includes/class-ocr-item-hook.php';
		new \Tainacan_OCR_Search\Item_Hook();
	},
	20
);

// O texto do OCR do documento principal entra no document_content_index do
// item pelo filtro do core, sem exigir que o PDF original seja reescrito.
add_filter( 'tainacan-index-pdf', array( '\Tainacan_OCR_Search\Processor', 'filter_core_pdf_text' ), 10, 3 );

/**
 * Hook opcional: processa automaticamente PDFs e imagens recém-enviados
 * (apenas se o usuário habilitou nas configurações).
 */
add_action(
	'add_attachment',
	function ( $attachment_id ) {
		$opts = get_option( 'tainacan_ocr_search_options', array() );
		if ( empty( $opts['auto_process'] ) ) {
			return;
		}
		if ( ! \Tainacan_OCR_Search\Processor::is_supported_attachment( $attachment_id ) ) {
			return;
		}
		wp_schedule_single_event( time() + 5, 'tainacan_ocr_search_process_single', array( $attachment_id ) );
	}
);

add_action(
	'tainacan_ocr_search_process_single',
	function ( $attachment_id ) {
		// Só o que a fila consideraria elegível: anexo de item Tainacan, fora a
		// miniatura gerada pelo Tainacan (salvo documento em imagem).
		$parent = (int) get_post_field( 'post_parent', $attachment_id );
		if ( ! $parent || ! preg_match( '/^tnc_col_\d+_item$/', (string) get_post_type( $parent ) ) ) {
			return;
		}
		$is_document = (int) get_post_meta( $parent, 'document', true ) === (int) $attachment_id;
		if ( ! $is_document && (int) get_post_thumbnail_id( $parent ) === (int) $attachment_id ) {
			return;
		}
		// Vai para a fila (um OCR por vez, com a situação visível no item).
		\Tainacan_OCR_Search\Queue::enqueue( array( (int) $attachment_id ) );
	}
);

// Reindex em lotes de 200, reagendado enquanto houver documentos.
add_action(
	'tainacan_ocr_search_reindex',
	function ( $offset ) {
		$processor = new \Tainacan_OCR_Search\Processor();
		$done      = $processor->reindex_processed_documents( 200, (int) $offset );
		if ( 200 === $done ) {
			wp_schedule_single_event( time() + 5, 'tainacan_ocr_search_reindex', array( (int) $offset + 200 ) );
		}
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once TAINACAN_OCR_SEARCH_DIR . 'includes/class-ocr-cli.php';
	\WP_CLI::add_command( 'tainacan-ocr', '\Tainacan_OCR_Search\CLI' );
}

register_activation_hook(
	__FILE__,
	function () {
		$existing = get_option( 'tainacan_ocr_search_options', array() );
		update_option( 'tainacan_ocr_search_options', wp_parse_args( $existing, \Tainacan_OCR_Search\Processor::default_options() ) );
	}
);
