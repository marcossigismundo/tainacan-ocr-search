<?php
/**
 * Página administrativa integrada ao Tainacan.
 * Estende \Tainacan\Pages conforme documentação oficial:
 * https://tainacan.github.io/tainacan-wiki/#/dev/creating-tainacan-admin-pages
 */

namespace Tainacan_OCR_Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Page extends \Tainacan\Pages {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// Constructor of \Tainacan\Pages is protected; we keep the same visibility
	// and expose creation via get_instance().
	protected function __construct() {
		parent::__construct();
	}

	protected function get_page_slug(): string {
		return 'tainacan-ocr-search';
	}

	public function add_admin_menu() {
		$suffix = add_submenu_page(
			$this->tainacan_root_menu_slug,
			__( 'OCR Search', 'tainacan-ocr-search' ),
			__( 'OCR Search', 'tainacan-ocr-search' ),
			'manage_options',
			$this->get_page_slug(),
			array( $this, 'render_page' ),
			15
		);
		// Wire the parent's load_page() so admin_enqueue_css/js are called.
		if ( $suffix ) {
			add_action( 'load-' . $suffix, array( $this, 'load_page' ) );
		}
	}

	public function admin_enqueue_css() {
		wp_enqueue_style(
			'tainacan-ocr-search-admin',
			TAINACAN_OCR_SEARCH_URL . 'assets/admin.css',
			array(),
			TAINACAN_OCR_SEARCH_VERSION
		);
	}

	public function admin_enqueue_js() {
		wp_enqueue_script(
			'tainacan-ocr-search-admin',
			TAINACAN_OCR_SEARCH_URL . 'assets/admin.js',
			array( 'wp-api-fetch', 'wp-i18n' ),
			TAINACAN_OCR_SEARCH_VERSION,
			true
		);
		wp_localize_script( 'tainacan-ocr-search-admin', 'TainacanOCRSearch', array(
			'restNamespace' => 'tainacan-ocr-search/v1',
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'i18n'          => array(
				'checking'        => __( 'Verificando dependências…', 'tainacan-ocr-search' ),
				'installed'       => __( 'instalado', 'tainacan-ocr-search' ),
				'missing'         => __( 'não encontrado', 'tainacan-ocr-search' ),
				'processing'      => __( 'Processando', 'tainacan-ocr-search' ),
				'done'            => __( 'Concluído', 'tainacan-ocr-search' ),
				'noPending'       => __( 'Nenhum documento pendente nesta seleção.', 'tainacan-ocr-search' ),
				'confirmRun'      => __( 'Iniciar OCR em %d documentos?', 'tainacan-ocr-search' ),
				'savedSettings'   => __( 'Configurações salvas.', 'tainacan-ocr-search' ),
				'errorPrefix'     => __( 'Erro: ', 'tainacan-ocr-search' ),
			),
		) );
	}

	public function render_page_content() {
		?>
		<div class="tainacan-ocr-search-wrap">

			<header class="tnc-ocr-header">
				<h1><?php esc_html_e( 'Tainacan OCR Search', 'tainacan-ocr-search' ); ?></h1>
				<p class="tnc-ocr-lead">
					<?php esc_html_e( 'Torne pesquisáveis fichas médicas, relatórios e outros documentos escaneados. O plugin aplica OCR (Tesseract + OCRmyPDF) nos PDFs e imagens das suas coleções, adiciona uma camada de texto invisível e reindexa o conteúdo no Tainacan — sem alterar a aparência dos arquivos originais.', 'tainacan-ocr-search' ); ?>
				</p>
			</header>

			<!-- PASSO 1: dependências -->
			<section class="tnc-ocr-card" data-step="1">
				<h2><span class="tnc-step">1</span> <?php esc_html_e( 'Verificar dependências', 'tainacan-ocr-search' ); ?></h2>
				<p class="tnc-ocr-help">
					<?php esc_html_e( 'O plugin precisa de dois utilitários livres instalados no servidor: o Tesseract OCR e o OCRmyPDF. Clique em verificar; se algo faltar, copie os comandos sugeridos.', 'tainacan-ocr-search' ); ?>
				</p>
				<button type="button" class="button button-secondary" id="tnc-ocr-check">
					<?php esc_html_e( 'Verificar agora', 'tainacan-ocr-search' ); ?>
				</button>
				<div id="tnc-ocr-check-result" class="tnc-ocr-result"></div>

				<details class="tnc-ocr-howto">
					<summary><?php esc_html_e( 'Como instalar?', 'tainacan-ocr-search' ); ?></summary>
					<p><strong>Linux (Debian/Ubuntu):</strong></p>
					<pre>sudo apt install tesseract-ocr tesseract-ocr-por ocrmypdf poppler-utils</pre>
					<p><strong>macOS:</strong></p>
					<pre>brew install tesseract tesseract-lang ocrmypdf poppler</pre>
					<p><strong>Windows:</strong> instale o <a href="https://github.com/UB-Mannheim/tesseract/wiki" target="_blank" rel="noopener">Tesseract UB-Mannheim</a> e o OCRmyPDF via <code>pip install ocrmypdf</code>. Informe os caminhos completos dos executáveis no passo 2.</p>
				</details>
			</section>

			<!-- PASSO 2: configurações -->
			<section class="tnc-ocr-card" data-step="2">
				<h2><span class="tnc-step">2</span> <?php esc_html_e( 'Configurações', 'tainacan-ocr-search' ); ?></h2>
				<form id="tnc-ocr-settings" class="tnc-ocr-form">
					<label>
						<?php esc_html_e( 'Caminho do Tesseract (opcional)', 'tainacan-ocr-search' ); ?>
						<input type="text" name="tesseract_path" placeholder="tesseract">
						<small><?php esc_html_e( 'Deixe em branco se já estiver no PATH do sistema.', 'tainacan-ocr-search' ); ?></small>
					</label>
					<label>
						<?php esc_html_e( 'Caminho do OCRmyPDF (opcional)', 'tainacan-ocr-search' ); ?>
						<input type="text" name="ocrmypdf_path" placeholder="ocrmypdf">
					</label>
					<label>
						<?php esc_html_e( 'Idioma do OCR', 'tainacan-ocr-search' ); ?>
						<input type="text" name="language" value="por" maxlength="32">
						<small><?php esc_html_e( 'Códigos do Tesseract: por, eng, spa, por+eng. O passo 1 lista os idiomas instalados.', 'tainacan-ocr-search' ); ?></small>
					</label>
					<fieldset class="tnc-ocr-checks">
						<label><input type="checkbox" name="deskew"> <?php esc_html_e( 'Deskew (corrigir inclinação)', 'tainacan-ocr-search' ); ?></label>
						<label><input type="checkbox" name="clean"> <?php esc_html_e( 'Limpeza de ruído (recomendado para fichas escaneadas)', 'tainacan-ocr-search' ); ?></label>
						<label><input type="checkbox" name="force_ocr"> <?php esc_html_e( 'Forçar OCR mesmo em PDFs que já contêm texto', 'tainacan-ocr-search' ); ?></label>
						<label><input type="checkbox" name="auto_process"> <?php esc_html_e( 'Processar automaticamente novos uploads', 'tainacan-ocr-search' ); ?></label>
					</fieldset>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Salvar', 'tainacan-ocr-search' ); ?></button>
					<span id="tnc-ocr-settings-msg" class="tnc-ocr-msg"></span>
				</form>
			</section>

			<!-- PASSO 3: lote -->
			<section class="tnc-ocr-card" data-step="3">
				<h2><span class="tnc-step">3</span> <?php esc_html_e( 'Processar documentos em lote', 'tainacan-ocr-search' ); ?></h2>
				<p class="tnc-ocr-help">
					<?php esc_html_e( 'Selecione a coleção (ou todas) e calcule a fila. O processamento roda no seu servidor — nenhum documento sai do ambiente. Você pode acompanhar o progresso e pausar a qualquer momento.', 'tainacan-ocr-search' ); ?>
				</p>
				<div class="tnc-ocr-row">
					<label>
						<?php esc_html_e( 'Coleção', 'tainacan-ocr-search' ); ?>
						<select id="tnc-ocr-collection">
							<option value=""><?php esc_html_e( '— Todas as coleções —', 'tainacan-ocr-search' ); ?></option>
						</select>
					</label>
					<label class="tnc-ocr-inline">
						<input type="checkbox" id="tnc-ocr-only-pending" checked>
						<?php esc_html_e( 'Apenas documentos ainda não processados', 'tainacan-ocr-search' ); ?>
					</label>
				</div>
				<div class="tnc-ocr-actions">
					<button type="button" class="button" id="tnc-ocr-queue"><?php esc_html_e( 'Calcular fila', 'tainacan-ocr-search' ); ?></button>
					<button type="button" class="button button-primary" id="tnc-ocr-start" disabled><?php esc_html_e( 'Iniciar OCR', 'tainacan-ocr-search' ); ?></button>
					<button type="button" class="button" id="tnc-ocr-stop" disabled><?php esc_html_e( 'Pausar', 'tainacan-ocr-search' ); ?></button>
				</div>

				<div id="tnc-ocr-progress-wrap" hidden>
					<div class="tnc-ocr-progress"><div class="tnc-ocr-bar"></div></div>
					<p class="tnc-ocr-counter"><span id="tnc-ocr-done">0</span> / <span id="tnc-ocr-total">0</span></p>
					<ul id="tnc-ocr-log" class="tnc-ocr-log"></ul>
				</div>
			</section>

			<!-- PASSO 4: como buscar -->
			<section class="tnc-ocr-card" data-step="4">
				<h2><span class="tnc-step">4</span> <?php esc_html_e( 'Pronto! Como pesquisar', 'tainacan-ocr-search' ); ?></h2>
				<p>
					<?php esc_html_e( 'Após o OCR, o conteúdo dos PDFs fica disponível para a busca textual nativa do Tainacan. Para indexar o acervo inteiro de uma vez (recomendado após o primeiro lote), execute via WP-CLI:', 'tainacan-ocr-search' ); ?>
				</p>
				<pre>wp tainacan index-content --collection=all</pre>
				<p>
					<?php esc_html_e( 'Em coleções públicas com dados sensíveis, lembre-se de revisar permissões e considere anonimizar campos pessoais antes de publicar.', 'tainacan-ocr-search' ); ?>
				</p>
			</section>
		</div>
		<?php
	}
}
