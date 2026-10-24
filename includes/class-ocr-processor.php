<?php
/**
 * Processador OCR — encapsula chamadas a Tesseract / OCRmyPDF, guarda o
 * texto reconhecido no anexo e pede ao Tainacan para reindexar o item.
 */

namespace Tainacan_OCR_Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Processor {

	const META_PROCESSED = '_tainacan_ocr_processed';
	const META_TEXT      = '_tainacan_ocr_text';
	const META_LOG       = '_tainacan_ocr_log';
	const META_PAGES     = '_tainacan_ocr_pages';

	/**
	 * Executáveis aceitos nas configurações. O caminho vem de um campo de
	 * texto e vira linha de comando: sem esta lista, qualquer binário do
	 * servidor poderia ser executado a partir das configurações do plugin.
	 */
	const ALLOWED_BINARIES = array(
		'tesseract' => array( 'tesseract', 'tesseract.exe' ),
		'ocrmypdf'  => array( 'ocrmypdf', 'ocrmypdf.exe', 'ocrmypdf.cmd', 'ocrmypdf.bat' ),
	);

	/** @var array */
	protected $opts;

	/** @var bool|null cache do probe do unpaper */
	protected $has_unpaper = null;

	/** @var bool|null cache do probe do pdftotext */
	protected $has_pdftotext = null;

	public function __construct() {
		$this->opts = wp_parse_args(
			get_option( 'tainacan_ocr_search_options', array() ),
			self::default_options()
		);
	}

	/**
	 * Opções padrão (também usadas na ativação).
	 */
	public static function default_options() {
		return array(
			'tesseract_path'   => '',
			'ocrmypdf_path'    => '',
			'language'         => 'por',
			'auto_process'     => 0,
			'deskew'           => 1,
			'clean'            => 1,
			'force_ocr'        => 0,
			// Preservação: por padrão o arquivo original não é tocado; o texto
			// fica no anexo e chega à busca do Tainacan pelo filtro
			// tainacan-index-pdf. Ligar troca o PDF pela versão com camada de
			// texto (PDF/A gerado pelo OCRmyPDF).
			'replace_original' => 0,
		);
	}

	/**
	 * Tipos de mídia suportados (PDF + imagens raster).
	 */
	public static function supported_mime_types() {
		return array(
			'application/pdf',
			'image/jpeg',
			'image/png',
			'image/tiff',
			'image/bmp',
			'image/webp',
		);
	}

	public static function is_supported_attachment( $attachment_id ) {
		$mime = get_post_mime_type( $attachment_id );
		return in_array( $mime, self::supported_mime_types(), true );
	}

	/**
	 * Idioma no formato do Tesseract: "por", "por+eng", "chi_sim".
	 *
	 * @param string $language Valor informado.
	 * @return bool
	 */
	public static function is_valid_language( $language ) {
		return (bool) preg_match( '/^[a-z]{3}(_[a-z]+)?(\+[a-z]{3}(_[a-z]+)?)*$/i', (string) $language );
	}

	/**
	 * Valida o caminho configurado de um executável.
	 *
	 * Vazio = usar o nome padrão via PATH. Preenchido = caminho absoluto de um
	 * arquivo existente cujo nome está em ALLOWED_BINARIES.
	 *
	 * @param string $tool Chave em ALLOWED_BINARIES.
	 * @param string $path Caminho informado.
	 * @return true|\WP_Error
	 */
	public static function validate_binary_path( $tool, $path ) {
		$path = trim( (string) $path );
		if ( '' === $path ) {
			return true;
		}
		$name = strtolower( wp_basename( str_replace( '\\', '/', $path ) ) );
		if ( ! in_array( $name, self::ALLOWED_BINARIES[ $tool ], true ) ) {
			/* translators: 1: tool name, 2: accepted file names */
			return new \WP_Error( 'invalid_binary', sprintf( __( 'O caminho do %1$s deve apontar para um destes arquivos: %2$s.', 'tainacan-ocr-search' ), $tool, implode( ', ', self::ALLOWED_BINARIES[ $tool ] ) ) );
		}
		if ( ! path_is_absolute( $path ) || ! file_exists( $path ) ) {
			/* translators: %s: tool name */
			return new \WP_Error( 'invalid_binary', sprintf( __( 'O caminho do %s precisa ser absoluto e existir no servidor.', 'tainacan-ocr-search' ), $tool ) );
		}
		return true;
	}

	/**
	 * Executável a usar para a ferramenta (configurado e válido, ou o padrão).
	 *
	 * @param string $tool Chave em ALLOWED_BINARIES.
	 * @return string
	 */
	protected function binary( $tool ) {
		$configured = (string) $this->opts[ $tool . '_path' ];
		return true === self::validate_binary_path( $tool, $configured ) && '' !== trim( $configured )
			? trim( $configured )
			: $tool;
	}

	/**
	 * Verifica se as dependências externas estão disponíveis.
	 *
	 * @return array
	 */
	public function check_dependencies() {
		$result = array();

		$tess                = $this->binary( 'tesseract' );
		$result['tesseract'] = $this->probe_command( $tess, '--version' );

		$result['ocrmypdf'] = $this->probe_command( $this->binary( 'ocrmypdf' ), '--version' );

		// Opcionais: pdftotext extrai o texto com quebras de página (o
		// fallback Smalot perde a página); unpaper habilita a limpeza.
		$result['pdftotext'] = $this->probe_command( 'pdftotext', '-v' );
		$result['unpaper']   = $this->probe_command( 'unpaper', '--version' );

		// Lista de idiomas instalados no Tesseract.
		if ( $result['tesseract']['ok'] ) {
			$langs = $this->run( $tess, array( '--list-langs' ) );
			if ( 0 === $langs['code'] ) {
				$lines = preg_split( '/\r?\n/', trim( $langs['output'] ) );
				array_shift( $lines ); // primeira linha é o cabeçalho
				$result['tesseract']['languages'] = array_values( array_filter( array_map( 'trim', $lines ) ) );
			}
		}

		// Sem esta opção do Tainacan o texto do OCR do documento principal não
		// chega ao document_content_index (busca textual nativa).
		$result['tainacan_pdf_index'] = array(
			'ok' => self::is_core_pdf_index_enabled(),
		);

		return $result;
	}

	/**
	 * A indexação de conteúdo de PDF do Tainacan está ligada?
	 *
	 * @return bool
	 */
	public static function is_core_pdf_index_enabled() {
		if ( class_exists( '\Tainacan\Media' ) && method_exists( '\Tainacan\Media', 'is_index_pdf_content_enabled' ) ) {
			return (bool) \Tainacan\Media::is_index_pdf_content_enabled();
		}
		if ( defined( 'TAINACAN_INDEX_PDF_CONTENT' ) ) {
			return true === TAINACAN_INDEX_PDF_CONTENT;
		}
		return (bool) get_option( 'tainacan_option_index_pdf_content', false );
	}

	/**
	 * Verifica se o utilitário unpaper está disponível no PATH.
	 * Necessário para a flag --clean do OCRmyPDF; sem build Windows oficial.
	 */
	protected function has_unpaper() {
		if ( null !== $this->has_unpaper ) {
			return $this->has_unpaper;
		}
		$probe             = $this->run( 'unpaper', array( '--version' ) );
		$this->has_unpaper = ( 0 === $probe['code'] );
		return $this->has_unpaper;
	}

	protected function probe_command( $bin, $arg ) {
		$out = $this->run( $bin, array( $arg ) );
		// pdftotext -v sai com código != 0 em versões antigas, mas imprime a versão.
		$ok = 0 === $out['code'] || ( 'pdftotext' === $bin && false !== stripos( $out['output'], 'pdftotext' ) );
		return array(
			'ok'      => $ok,
			'path'    => $bin,
			'version' => $ok ? trim( strtok( $out['output'], "\n" ) ) : '',
			'error'   => $ok ? '' : trim( $out['output'] ),
		);
	}

	/**
	 * Executa um comando e retorna { code, output }.
	 */
	protected function run( $bin, array $args ) {
		if ( ! function_exists( 'proc_open' ) ) {
			return array(
				'code'   => 127,
				'output' => __( 'proc_open desabilitado no PHP', 'tainacan-ocr-search' ),
			);
		}
		$cmd = escapeshellcmd( $bin );
		foreach ( $args as $a ) {
			$cmd .= ' ' . escapeshellarg( $a );
		}
		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- proc_open warns when the binary is missing; the failure is reported through the return value instead.
		$proc = @proc_open( $cmd . ' 2>&1', $descriptors, $pipes );
		if ( ! is_resource( $proc ) ) {
			return array(
				'code'   => 127,
				/* translators: %s: executable name */
				'output' => sprintf( __( 'falha ao executar: %s', 'tainacan-ocr-search' ), $bin ),
			);
		}
		$out = stream_get_contents( $pipes[1] );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipes from proc_open, not files; WP_Filesystem does not handle them.
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$code = proc_close( $proc );
		return array(
			'code'   => $code,
			'output' => $this->to_utf8( $out ),
		);
	}

	/**
	 * Normaliza saída de comandos para UTF-8.
	 * No Windows o CMD usa CP850/CP1252 por padrão e quebra o JSON da REST.
	 */
	protected function to_utf8( $text ) {
		if ( null === $text || '' === $text ) {
			return '';
		}
		if ( function_exists( 'mb_check_encoding' ) && mb_check_encoding( $text, 'UTF-8' ) ) {
			return $text;
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$from = stripos( PHP_OS, 'WIN' ) === 0 ? 'CP850,Windows-1252,ISO-8859-1' : 'ISO-8859-1';
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unknown source encodings raise warnings; false is handled below.
			$converted = @mb_convert_encoding( $text, 'UTF-8', $from );
			if ( false !== $converted ) {
				return $converted;
			}
		}
		// Fallback: remove bytes inválidos.
		return preg_replace( '/[^\x09\x0A\x0C\x0D\x20-\x7E]/', '?', $text );
	}

	/**
	 * Lista anexos elegíveis (opcionalmente de uma coleção Tainacan).
	 *
	 * Ficam de fora as miniaturas dos itens: o Tainacan gera uma imagem de
	 * capa para cada documento, e passar OCR nela só duplicaria trabalho.
	 * Exceção: documento em imagem, que o Tainacan usa como a própria
	 * miniatura do item.
	 *
	 * @param int|null $collection_id ID da coleção Tainacan
	 * @param bool     $only_pending Pular já processados
	 */
	public function list_eligible_attachments( $collection_id = null, $only_pending = true ) {
		global $wpdb;

		$mimes        = self::supported_mime_types();
		$placeholders = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
		$params       = $mimes;

		// %% vira % depois do prepare(): LIKE literal, não placeholder.
		$parent_sql = "INNER JOIN {$wpdb->posts} p ON a.post_parent = p.ID AND p.post_type LIKE 'tnc\\_col\\_%%\\_item'";
		if ( $collection_id ) {
			$parent_sql = "INNER JOIN {$wpdb->posts} p ON a.post_parent = p.ID AND p.post_type = %s";
			array_unshift( $params, 'tnc_col_' . (int) $collection_id . '_item' );
		}

		$pending_sql = '';
		if ( $only_pending ) {
			$pending_sql = "AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = a.ID AND pm.meta_key = %s )";
			$params[]    = self::META_PROCESSED;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- JOIN of attachments with their Tainacan item and thumbnail exclusion is not expressible via WP_Query; $parent_sql/$pending_sql are fixed fragments with %s placeholders; IN-clause built with array_fill of %s matching $mimes; admin batch listing, caching would return a stale queue.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT a.ID FROM {$wpdb->posts} a
				 {$parent_sql}
				 LEFT JOIN {$wpdb->postmeta} th ON th.post_id = p.ID AND th.meta_key = '_thumbnail_id' AND th.meta_value = a.ID
				 LEFT JOIN {$wpdb->postmeta} doc ON doc.post_id = p.ID AND doc.meta_key = 'document' AND doc.meta_value = a.ID
				 WHERE a.post_type = 'attachment'
				   AND a.post_mime_type IN ($placeholders)
				   AND ( th.meta_id IS NULL OR doc.meta_id IS NOT NULL )
				   {$pending_sql}
				 ORDER BY a.ID ASC",
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Processa um único anexo: roda o OCRmyPDF, guarda o texto (com quebra
	 * de página "\f") no anexo e reindexa o item-pai. O arquivo original só
	 * é substituído com a opção replace_original ligada.
	 *
	 * @return array { success, message, text_length, pages }
	 */
	public function process_attachment( $attachment_id ) {
		// REST e WP-Cron não carregam wp-admin/includes/* automaticamente;
		// wp_tempnam e wp_generate_attachment_metadata vivem lá.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = (int) $attachment_id;
		$file          = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return $this->fail( $attachment_id, __( 'arquivo não encontrado', 'tainacan-ocr-search' ) );
		}
		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::supported_mime_types(), true ) ) {
			/* translators: %s: MIME type */
			return $this->fail( $attachment_id, sprintf( __( 'tipo MIME não suportado: %s', 'tainacan-ocr-search' ), $mime ) );
		}

		$lang = self::is_valid_language( $this->opts['language'] ) ? $this->opts['language'] : 'por';

		$is_pdf = ( 'application/pdf' === $mime );
		$tmp_in = $is_pdf ? $file : $this->image_to_pdf( $file );
		if ( ! $tmp_in ) {
			return $this->fail( $attachment_id, __( 'falha ao converter imagem para PDF', 'tainacan-ocr-search' ) );
		}

		$replace = ! empty( $this->opts['replace_original'] );
		$tmp_out = wp_tempnam( 'ocr-out-' . $attachment_id . '.pdf' );

		$args          = array( '-l', $lang );
		$skipped_clean = false;
		if ( ! empty( $this->opts['deskew'] ) ) {
			$args[] = '--deskew';
		}
		if ( ! empty( $this->opts['clean'] ) ) {
			if ( $this->has_unpaper() ) {
				$args[] = '--clean';
			} else {
				$skipped_clean = true;
			}
		}
		if ( ! empty( $this->opts['force_ocr'] ) ) {
			$args[] = '--force-ocr';
		} else {
			$args[] = '--skip-text';
		}
		if ( ! $replace ) {
			// Saída só para extrair o texto: PDF simples é mais rápido que PDF/A.
			$args[] = '--output-type';
			$args[] = 'pdf';
		}
		$args[] = $tmp_in;
		$args[] = $tmp_out;

		$started = microtime( true );
		$result  = $this->run( $this->binary( 'ocrmypdf' ), $args );

		if ( ! $is_pdf && $tmp_in !== $file ) {
			wp_delete_file( $tmp_in );
		}

		if ( 0 !== $result['code'] || ! file_exists( $tmp_out ) || 0 === filesize( $tmp_out ) ) {
			wp_delete_file( $tmp_out );
			/* translators: %s: OCRmyPDF output */
			return $this->fail( $attachment_id, sprintf( __( 'OCRmyPDF falhou: %s', 'tainacan-ocr-search' ), $result['output'] ) );
		}

		// Extrai o texto com quebras de página ("\f"), que o Oráculo e outros
		// consumidores usam para citar a página.
		$text  = $this->extract_text_from_pdf( $tmp_out );
		// pdftotext fecha cada página com "\f", inclusive as em branco do fim.
		$pages = '' === $text ? 0 : max( 1, substr_count( $text, "\f" ) );

		// Só PDFs são substituídos: um PDF gerado ao lado de uma imagem não
		// seria anexo de nada e ficaria órfão em uploads/.
		if ( $replace && $is_pdf ) {
			copy( $tmp_out, $file );
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $file ) );
		}
		wp_delete_file( $tmp_out );

		$log_output = $result['output'];
		if ( $skipped_clean ) {
			$log_output = __( '[aviso] --clean ignorado: unpaper indisponível neste sistema.', 'tainacan-ocr-search' ) . "\n" . $log_output;
		}

		update_post_meta( $attachment_id, self::META_PROCESSED, current_time( 'mysql' ) );
		update_post_meta( $attachment_id, self::META_TEXT, wp_slash( $text ) );
		update_post_meta( $attachment_id, self::META_PAGES, $pages );
		update_post_meta( $attachment_id, self::META_LOG, wp_slash( substr( $log_output, -2000 ) ) );

		$parent = (int) get_post_field( 'post_parent', $attachment_id );

		/**
		 * Texto de OCR de um anexo foi gravado.
		 *
		 * @param int    $attachment_id Anexo processado.
		 * @param int    $parent        Item Tainacan dono do anexo (0 se nenhum).
		 * @param string $text          Texto reconhecido, páginas separadas por "\f".
		 */
		do_action( 'tainacan_ocr_search_text_updated', $attachment_id, $parent, $text );

		$this->reindex_parent_item( $attachment_id );

		return array(
			'success'     => true,
			'message'     => __( 'OCR aplicado com sucesso', 'tainacan-ocr-search' ),
			'text_length' => strlen( $text ),
			'pages'       => $pages,
			'seconds'     => round( microtime( true ) - $started, 1 ),
		);
	}

	protected function fail( $attachment_id, $msg ) {
		update_post_meta( $attachment_id, self::META_LOG, wp_slash( $msg ) );
		return array(
			'success'     => false,
			'message'     => $msg,
			'text_length' => 0,
		);
	}

	protected function image_to_pdf( $image_path ) {
		// Imagem → PDF via Imagick (sem dependência externa adicional).
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}
		try {
			$pdf = wp_tempnam( 'ocr-img-' . wp_basename( $image_path ) . '.pdf' );
			$im  = new \Imagick( $image_path );
			$im->setImageFormat( 'pdf' );
			$im->writeImage( $pdf );
			$im->clear();
			return $pdf;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	protected function extract_text_from_pdf( $pdf_path ) {
		// Preferimos o utilitário pdftotext (poppler): mantém "\f" entre páginas.
		if ( null === $this->has_pdftotext ) {
			$probe               = $this->run( 'pdftotext', array( '-v' ) );
			$this->has_pdftotext = 0 === $probe['code'] || stripos( $probe['output'], 'pdftotext' ) !== false;
		}
		if ( $this->has_pdftotext ) {
			$txt = wp_tempnam( 'ocr-txt.txt' );
			$this->run( 'pdftotext', array( '-layout', '-enc', 'UTF-8', $pdf_path, $txt ) );
			if ( file_exists( $txt ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temp file written by pdftotext above; not a remote URL.
				$content = file_get_contents( $txt );
				wp_delete_file( $txt );
				return $content ? $this->to_utf8( $content ) : '';
			}
		}
		// Fallback: Smalot\PdfParser do próprio Tainacan, uma página por vez
		// para manter as quebras de página.
		if ( class_exists( '\Smalot\PdfParser\Parser' ) ) {
			try {
				$parser = new \Smalot\PdfParser\Parser();
				$pdf    = $parser->parseFile( $pdf_path );
				$pages  = array();
				foreach ( $pdf->getPages() as $page ) {
					$pages[] = $page->getText();
				}
				return implode( "\f", $pages );
			} catch ( \Exception $e ) {
				return '';
			}
		}
		return '';
	}

	/**
	 * Texto de OCR do PDF que o Tainacan está indexando como documento.
	 *
	 * Ligado ao filtro `tainacan-index-pdf` do core (Media::extract_pdf_content):
	 * quando o documento do item já passou por OCR, o Tainacan grava este
	 * texto no document_content_index em vez de tentar extrair de um PDF que
	 * não tem camada de texto (o original é preservado por padrão).
	 *
	 * @param mixed    $alternate Valor de outro filtro (null = nenhum).
	 * @param string   $file      Caminho do PDF.
	 * @param int|null $item_id   Item do Tainacan.
	 * @return mixed
	 */
	public static function filter_core_pdf_text( $alternate, $file, $item_id = null ) {
		if ( null !== $alternate ) {
			return $alternate;
		}

		$attachment_id = self::attachment_for_file( (string) $file, (int) $item_id );
		if ( ! $attachment_id ) {
			return null;
		}

		$text = self::text_for_index( (string) get_post_meta( $attachment_id, self::META_TEXT, true ) );
		return '' !== $text ? $text : null;
	}

	/**
	 * Texto de OCR no formato aceito pelo índice do core.
	 *
	 * O core 1.4 recusa texto com caracteres de controle
	 * (Pdf_Extracted_Text_Quality) e então APAGA o document_content_index;
	 * a quebra de página "\f" do pdftotext vira linha em branco.
	 *
	 * @param string $text Texto guardado no anexo.
	 * @return string
	 */
	public static function text_for_index( $text ) {
		$text = str_replace( "\f", "\n\n", (string) $text );
		return trim( (string) preg_replace( '/[\x00-\x08\x0B\x0E-\x1F\x7F]/', '', $text ) );
	}

	/**
	 * Anexo dono de um arquivo, preferindo o documento do item.
	 *
	 * @param string $file    Caminho absoluto.
	 * @param int    $item_id Item (0 se desconhecido).
	 * @return int
	 */
	protected static function attachment_for_file( $file, $item_id ) {
		if ( $item_id ) {
			$document = (int) get_post_meta( $item_id, 'document', true );
			if ( $document && get_attached_file( $document ) === $file ) {
				return $document;
			}
		}

		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );
		if ( 0 !== strpos( $file, $base ) ) {
			return 0;
		}

		$relative = substr( $file, strlen( $base ) );
		$found    = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Single lookup by the indexed _wp_attached_file key, only when the item document did not match.
				'meta_query'     => array(
					array(
						'key'   => '_wp_attached_file',
						'value' => $relative,
					),
				),
			)
		);

		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Reindexa itens cujo documento já passou por OCR (lote).
	 *
	 * Usado quando a indexação de PDF do core é ligada depois do OCR: os
	 * anexos já estão marcados como processados e não voltariam à fila.
	 *
	 * @param int $limit  Máximo por chamada.
	 * @param int $offset Deslocamento.
	 * @return int Itens reindexados nesta chamada.
	 */
	public function reindex_processed_documents( $limit = 200, $offset = 0 ) {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Batch maintenance over processed attachments only; runs from WP-CLI or a single cron event.
				'meta_key'       => self::META_PROCESSED,
			)
		);

		foreach ( $ids as $id ) {
			$this->reindex_parent_item( (int) $id );
		}

		return count( $ids );
	}

	protected function reindex_parent_item( $attachment_id ) {
		$parent = (int) get_post_field( 'post_parent', $attachment_id );
		if ( ! $parent ) {
			return;
		}

		// Só o documento principal tem índice de conteúdo no Tainacan; o texto
		// dos demais anexos fica neles (META_TEXT) e quem consome lê de lá.
		// Não se dispara tainacan-insert nem wp_update_post: o item não mudou,
		// e esses ganchos criariam logs e reprocessamentos falsos.
		if ( (int) get_post_meta( $parent, 'document', true ) !== (int) $attachment_id ) {
			return;
		}

		// O OCR já foi salvo com sucesso a esta altura — qualquer falha aqui
		// é "best effort" e não deve invalidar o processamento.
		try {
			$text     = self::text_for_index( (string) get_post_meta( $attachment_id, self::META_TEXT, true ) );
			$is_image = wp_attachment_is_image( $attachment_id );
			$core_14  = class_exists( '\Tainacan\Media' ) && method_exists( '\Tainacan\Media', 'prepare_document_content_index_for_storage' );

			if ( ! $is_image && $core_14 && class_exists( '\Tainacan\Repositories\Items' ) ) {
				$repo = \Tainacan\Repositories\Items::get_instance();
				$item = $repo->fetch( $parent );
				if ( $item instanceof \Tainacan\Entities\Item && method_exists( $repo, 'generate_index_content' ) ) {
					// O core só reextrai o PDF quando nome, tamanho ou data do
					// arquivo mudam; com o original preservado nada muda, então
					// a marca é apagada para o texto do OCR entrar no índice
					// (via filtro tainacan-index-pdf).
					delete_post_meta( $parent, 'document_content_last_index' );
					$repo->generate_index_content( $item );
					return;
				}
			}

			// Documento em imagem (o core não indexa imagens) ou core anterior
			// ao 1.4 (que não grava o valor do filtro): grava direto.
			if ( '' !== $text && self::is_core_pdf_index_enabled() ) {
				if ( $core_14 ) {
					$prepared = \Tainacan\Media::prepare_document_content_index_for_storage( $text );
					$text     = $prepared['content'];
				}
				update_post_meta( $parent, 'document_content_index', wp_slash( $text ) );
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Guarded by WP_DEBUG; surfaces only in dev.
				error_log( 'tainacan-ocr-search: reindex falhou para item ' . $parent . ': ' . $e->getMessage() );
			}
		}
	}
}
