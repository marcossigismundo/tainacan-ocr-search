<?php
/**
 * Quadro "Texto dos arquivos (OCR)" no formulário de edição do item.
 *
 * O catalogador vê a situação de cada arquivo do item e aplica o OCR sem
 * sair da edição. O quadro é servido pelo gancho de formulário do Tainacan
 * e preenchido pelo assets/item-panel.js via REST (namespace do plugin).
 */

namespace Tainacan_OCR_Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Item_Hook {

	public function __construct() {
		add_action( 'tainacan-register-admin-hooks', array( $this, 'register_hook' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registra o quadro no topo da coluna esquerda do formulário do item.
	 */
	public function register_hook() {
		if ( function_exists( 'tainacan_register_admin_hook' ) ) {
			tainacan_register_admin_hook( 'item', array( $this, 'render_form' ), 'begin-left' );
		}
	}

	/**
	 * Marcação do quadro (o conteúdo vem do JS).
	 *
	 * @return string
	 */
	public function render_form() {
		ob_start();
		?>
		<div class="field tnc-ocr-item" id="tnc-ocr-item" hidden>
			<div class="tnc-ocr-item__head">
				<span class="dashicons dashicons-media-text" aria-hidden="true"></span>
				<h4 class="tnc-ocr-item__title"><?php esc_html_e( 'Texto dos arquivos (OCR)', 'tainacan-ocr-search' ); ?></h4>
				<span class="tnc-ocr-item__summary" id="tnc-ocr-item-summary"></span>
			</div>
			<p class="tnc-ocr-item__help">
				<?php esc_html_e( 'Reconhece o texto de PDFs e imagens digitalizados para que o conteúdo apareça nas buscas. O arquivo original não é alterado, e você pode continuar editando ou salvar o item enquanto o OCR roda.', 'tainacan-ocr-search' ); ?>
			</p>
			<ul class="tnc-ocr-item__files" id="tnc-ocr-item-files" aria-live="polite"></ul>
			<div class="tnc-ocr-item__actions" id="tnc-ocr-item-actions"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Carrega JS/CSS só na tela da aplicação do Tainacan, para quem pode usar.
	 */
	public function enqueue_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'admin_page_tainacan_admin' !== $screen->base ) {
			return;
		}

		wp_enqueue_style(
			'tainacan-ocr-search-item',
			TAINACAN_OCR_SEARCH_URL . 'assets/item-panel.css',
			array(),
			TAINACAN_OCR_SEARCH_VERSION
		);
		wp_enqueue_script(
			'tainacan-ocr-search-item',
			TAINACAN_OCR_SEARCH_URL . 'assets/item-panel.js',
			array( 'wp-api-fetch', 'wp-hooks' ),
			TAINACAN_OCR_SEARCH_VERSION,
			true
		);
		wp_localize_script(
			'tainacan-ocr-search-item',
			'TainacanOCRItem',
			array(
				// O nonce do wp_rest vem do middleware que o core já põe no wp-api-fetch.
				'restPath' => '/tainacan-ocr-search/v1/',
				'i18n'     => array(
					'document'      => __( 'Documento principal', 'tainacan-ocr-search' ),
					'attachment'    => __( 'Anexo', 'tainacan-ocr-search' ),
					'none'          => __( 'Ainda sem OCR', 'tainacan-ocr-search' ),
					'queued'        => __( 'Na fila', 'tainacan-ocr-search' ),
					/* translators: %d: position in the OCR queue */
					'queuedPos'     => __( 'Na fila (posição %d)', 'tainacan-ocr-search' ),
					'processing'    => __( 'Reconhecendo o texto…', 'tainacan-ocr-search' ),
					/* translators: %d: minutes since OCR started */
					'processingFor' => __( 'Reconhecendo o texto… há %d min', 'tainacan-ocr-search' ),
					'done'          => __( 'Texto reconhecido', 'tainacan-ocr-search' ),
					'empty'         => __( 'Nenhum texto encontrado', 'tainacan-ocr-search' ),
					'failed'        => __( 'Falhou', 'tainacan-ocr-search' ),
					/* translators: 1: pages, 2: characters */
					'stats'         => __( '%1$s pág. · %2$s caracteres', 'tainacan-ocr-search' ),
					'runAll'        => __( 'Aplicar OCR', 'tainacan-ocr-search' ),
					/* translators: %d: number of files */
					'runCount'      => __( 'Aplicar OCR em %d arquivo(s)', 'tainacan-ocr-search' ),
					'redo'          => __( 'Refazer', 'tainacan-ocr-search' ),
					'retry'         => __( 'Tentar de novo', 'tainacan-ocr-search' ),
					'showText'      => __( 'Ver texto', 'tainacan-ocr-search' ),
					'hideText'      => __( 'Ocultar texto', 'tainacan-ocr-search' ),
					/* translators: %d: page number */
					'page'          => __( 'Página %d', 'tainacan-ocr-search' ),
					'noText'        => __( 'O OCR não encontrou texto neste arquivo (página em branco ou só imagem).', 'tainacan-ocr-search' ),
					'noFiles'       => __( 'Este item ainda não tem PDF nem imagem. Envie o documento ou anexos e o quadro aparece aqui.', 'tainacan-ocr-search' ),
					'allDone'       => __( 'OCR concluído em todos os arquivos.', 'tainacan-ocr-search' ),
					'working'       => __( 'O OCR roda no servidor: pode continuar editando ou salvar o item. Leva cerca de 2 a 3 minutos a cada 16 páginas.', 'tainacan-ocr-search' ),
					'auto'          => __( 'O OCR automático está ligado: arquivos novos entram na fila sozinhos.', 'tainacan-ocr-search' ),
					/* translators: 1: files with text, 2: total files */
					'summary'       => __( '%1$d de %2$d com texto', 'tainacan-ocr-search' ),
					'error'         => __( 'Não foi possível consultar o OCR. Tente de novo em instantes.', 'tainacan-ocr-search' ),
					'runError'      => __( 'Não foi possível pôr os arquivos na fila. Recarregue a página e tente de novo.', 'tainacan-ocr-search' ),
					'confirmRedo'   => __( 'Refazer o OCR deste arquivo? O texto atual será substituído quando o novo terminar.', 'tainacan-ocr-search' ),
					'confirmYes'    => __( 'Refazer', 'tainacan-ocr-search' ),
					'confirmNo'     => __( 'Cancelar', 'tainacan-ocr-search' ),
				),
			)
		);
	}
}
