<?php
/**
 * Processador OCR — encapsula chamadas a Tesseract / OCRmyPDF e
 * a substituição de anexos no WordPress + reindexação no Tainacan.
 */

namespace Tainacan_OCR_Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Processor {

	const META_PROCESSED = '_tainacan_ocr_processed';
	const META_TEXT      = '_tainacan_ocr_text';
	const META_LOG       = '_tainacan_ocr_log';

	/** @var array */
	protected $opts;

	public function __construct() {
		$this->opts = wp_parse_args(
			get_option( 'tainacan_ocr_search_options', array() ),
			array(
				'tesseract_path' => '',
				'ocrmypdf_path'  => '',
				'language'       => 'por',
				'deskew'         => 1,
				'clean'          => 1,
				'force_ocr'      => 0,
			)
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
	 * Verifica se as dependências externas estão disponíveis.
	 *
	 * @return array { tesseract: array{ok,version,path}, ocrmypdf: array{...} }
	 */
	public function check_dependencies() {
		$result = array();

		$tess = $this->opts['tesseract_path'] ?: 'tesseract';
		$result['tesseract'] = $this->probe_command( $tess, '--version' );

		$omp = $this->opts['ocrmypdf_path'] ?: 'ocrmypdf';
		$result['ocrmypdf'] = $this->probe_command( $omp, '--version' );

		// Lista de idiomas instalados no Tesseract.
		if ( $result['tesseract']['ok'] ) {
			$langs = $this->run( $tess, array( '--list-langs' ) );
			if ( $langs['code'] === 0 ) {
				$lines = preg_split( '/\r?\n/', trim( $langs['output'] ) );
				array_shift( $lines ); // primeira linha é o cabeçalho
				$result['tesseract']['languages'] = array_values( array_filter( array_map( 'trim', $lines ) ) );
			}
		}

		return $result;
	}

	protected function probe_command( $bin, $arg ) {
		$out = $this->run( $bin, array( $arg ) );
		return array(
			'ok'      => $out['code'] === 0,
			'path'    => $bin,
			'version' => $out['code'] === 0 ? trim( strtok( $out['output'], "\n" ) ) : '',
			'error'   => $out['code'] === 0 ? '' : trim( $out['output'] ),
		);
	}

	/**
	 * Executa um comando e retorna { code, output }.
	 */
	protected function run( $bin, array $args ) {
		if ( ! function_exists( 'proc_open' ) ) {
			return array( 'code' => 127, 'output' => 'proc_open desabilitado no PHP' );
		}
		$cmd = escapeshellcmd( $bin );
		foreach ( $args as $a ) {
			$cmd .= ' ' . escapeshellarg( $a );
		}
		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$proc = @proc_open( $cmd . ' 2>&1', $descriptors, $pipes );
		if ( ! is_resource( $proc ) ) {
			return array( 'code' => 127, 'output' => 'falha ao executar: ' . $bin );
		}
		$out = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $proc );
		return array( 'code' => $code, 'output' => $this->to_utf8( $out ) );
	}

	/**
	 * Normaliza saída de comandos para UTF-8.
	 * No Windows o CMD usa CP850/CP1252 por padrão e quebra o JSON da REST.
	 */
	protected function to_utf8( $text ) {
		if ( $text === null || $text === '' ) {
			return '';
		}
		if ( function_exists( 'mb_check_encoding' ) && mb_check_encoding( $text, 'UTF-8' ) ) {
			return $text;
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$from = stripos( PHP_OS, 'WIN' ) === 0 ? 'CP850,Windows-1252,ISO-8859-1' : 'ISO-8859-1';
			$converted = @mb_convert_encoding( $text, 'UTF-8', $from );
			if ( $converted !== false ) {
				return $converted;
			}
		}
		// Fallback: remove bytes inválidos.
		return preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]/', '?', $text );
	}

	/**
	 * Lista anexos elegíveis (opcionalmente de uma coleção Tainacan).
	 *
	 * @param int|null $collection_id ID da coleção Tainacan
	 * @param bool     $only_pending Pular já processados
	 */
	public function list_eligible_attachments( $collection_id = null, $only_pending = true ) {
		global $wpdb;

		$mime_in = "'" . implode( "','", array_map( 'esc_sql', self::supported_mime_types() ) ) . "'";

		if ( $collection_id ) {
			$post_type = 'tnc_col_' . (int) $collection_id . '_item';
			$sql = $wpdb->prepare(
				"SELECT a.ID FROM {$wpdb->posts} a
				 INNER JOIN {$wpdb->posts} p ON a.post_parent = p.ID
				 WHERE a.post_type = 'attachment'
				   AND a.post_mime_type IN ($mime_in)
				   AND p.post_type = %s
				 ORDER BY a.ID ASC",
				$post_type
			);
		} else {
			$sql = "SELECT ID FROM {$wpdb->posts}
			        WHERE post_type = 'attachment'
			          AND post_mime_type IN ($mime_in)
			        ORDER BY ID ASC";
		}

		$ids = $wpdb->get_col( $sql );

		if ( $only_pending ) {
			$ids = array_values( array_filter( $ids, function ( $id ) {
				return ! get_post_meta( $id, self::META_PROCESSED, true );
			} ) );
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * Processa um único anexo: gera PDF/A com camada OCR e substitui o
	 * arquivo original; em imagens, gera um PDF pesquisável anexo.
	 *
	 * @return array { success, message, text_length }
	 */
	public function process_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return $this->fail( $attachment_id, 'arquivo não encontrado' );
		}
		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::supported_mime_types(), true ) ) {
			return $this->fail( $attachment_id, 'tipo MIME não suportado: ' . $mime );
		}

		$ocrmypdf = $this->opts['ocrmypdf_path'] ?: 'ocrmypdf';
		$lang     = $this->opts['language'] ?: 'por';

		$is_pdf = ( $mime === 'application/pdf' );
		$tmp_in = $is_pdf ? $file : $this->image_to_pdf( $file );
		if ( ! $tmp_in ) {
			return $this->fail( $attachment_id, 'falha ao converter imagem para PDF' );
		}

		$tmp_out = wp_tempnam( 'ocr-out-' . $attachment_id . '.pdf' );

		$args = array( '-l', $lang );
		if ( ! empty( $this->opts['deskew'] ) ) {
			$args[] = '--deskew';
		}
		if ( ! empty( $this->opts['clean'] ) ) {
			$args[] = '--clean';
		}
		if ( ! empty( $this->opts['force_ocr'] ) ) {
			$args[] = '--force-ocr';
		} else {
			$args[] = '--skip-text';
		}
		$args[] = $tmp_in;
		$args[] = $tmp_out;

		$result = $this->run( $ocrmypdf, $args );

		if ( ! $is_pdf && $tmp_in !== $file ) {
			@unlink( $tmp_in );
		}

		if ( $result['code'] !== 0 || ! file_exists( $tmp_out ) || filesize( $tmp_out ) === 0 ) {
			@unlink( $tmp_out );
			return $this->fail( $attachment_id, 'OCRmyPDF falhou: ' . $result['output'] );
		}

		// Substitui o arquivo: PDFs sobrescrevem o original; imagens ganham um PDF irmão.
		if ( $is_pdf ) {
			copy( $tmp_out, $file );
			@unlink( $tmp_out );
			$pdf_path = $file;
		} else {
			$pdf_path = preg_replace( '/\.[^.]+$/', '.ocr.pdf', $file );
			rename( $tmp_out, $pdf_path );
		}

		// Extrai texto puro para metadado pesquisável (Tainacan indexa metadados).
		$text = $this->extract_text_from_pdf( $pdf_path );

		update_post_meta( $attachment_id, self::META_PROCESSED, current_time( 'mysql' ) );
		update_post_meta( $attachment_id, self::META_TEXT, wp_slash( $text ) );
		update_post_meta( $attachment_id, self::META_LOG, wp_slash( substr( $result['output'], -2000 ) ) );

		// Atualiza metadados do anexo (tamanho/MIME) para consistência.
		if ( $is_pdf && function_exists( 'wp_generate_attachment_metadata' ) ) {
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $file ) );
		}

		// Pede ao Tainacan para reindexar o item-pai (se houver).
		$this->reindex_parent_item( $attachment_id );

		return array(
			'success'     => true,
			'message'     => __( 'OCR aplicado com sucesso', 'tainacan-ocr-search' ),
			'text_length' => strlen( $text ),
		);
	}

	protected function fail( $attachment_id, $msg ) {
		update_post_meta( $attachment_id, self::META_LOG, wp_slash( $msg ) );
		return array( 'success' => false, 'message' => $msg, 'text_length' => 0 );
	}

	protected function image_to_pdf( $image_path ) {
		// Tenta img2pdf via Imagick (sem dependência externa adicional).
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}
		try {
			$pdf = wp_tempnam( 'ocr-img-' . wp_basename( $image_path ) . '.pdf' );
			$im = new \Imagick( $image_path );
			$im->setImageFormat( 'pdf' );
			$im->writeImage( $pdf );
			$im->clear();
			return $pdf;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	protected function extract_text_from_pdf( $pdf_path ) {
		// Preferimos o utilitário pdftotext (poppler) se disponível.
		$probe = $this->run( 'pdftotext', array( '-v' ) );
		if ( $probe['code'] === 0 || stripos( $probe['output'], 'pdftotext' ) !== false ) {
			$txt = wp_tempnam( 'ocr-txt.txt' );
			$this->run( 'pdftotext', array( '-layout', $pdf_path, $txt ) );
			if ( file_exists( $txt ) ) {
				$content = file_get_contents( $txt );
				@unlink( $txt );
				return $content ?: '';
			}
		}
		// Fallback: usa Smalot\PdfParser do próprio Tainacan, se carregado.
		if ( class_exists( '\Smalot\PdfParser\Parser' ) ) {
			try {
				$parser = new \Smalot\PdfParser\Parser();
				$pdf = $parser->parseFile( $pdf_path );
				return $pdf->getText();
			} catch ( \Exception $e ) {
				return '';
			}
		}
		return '';
	}

	protected function reindex_parent_item( $attachment_id ) {
		$parent = get_post_field( 'post_parent', $attachment_id );
		if ( ! $parent ) {
			return;
		}
		// O Tainacan dispara a indexação quando o item é "salvo".
		if ( function_exists( 'wp_update_post' ) ) {
			wp_update_post( array( 'ID' => $parent ) );
		}
		do_action( 'tainacan-insert', get_post( $parent ) );
	}
}
