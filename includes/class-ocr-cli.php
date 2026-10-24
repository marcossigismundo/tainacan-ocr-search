<?php
/**
 * Comandos WP-CLI: OCR em lote fora do navegador.
 */

namespace Tainacan_OCR_Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aplica OCR nos anexos das coleções Tainacan.
 */
class CLI {

	/**
	 * Processa os anexos pendentes (ou todos com --all).
	 *
	 * ## OPTIONS
	 *
	 * [--collection=<id>]
	 * : Só esta coleção Tainacan.
	 *
	 * [--all]
	 * : Reprocessa também os anexos já processados.
	 *
	 * [--limit=<n>]
	 * : Máximo de anexos nesta execução.
	 *
	 * ## EXAMPLES
	 *
	 *     wp tainacan-ocr process --collection=42
	 *
	 * @param array $args       Posicionais.
	 * @param array $assoc_args Nomeados.
	 */
	public function process( $args, $assoc_args ) {
		$proc  = new Processor();
		$ids   = $proc->list_eligible_attachments( isset( $assoc_args['collection'] ) ? absint( $assoc_args['collection'] ) : null, empty( $assoc_args['all'] ) );
		$limit = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0;
		if ( $limit ) {
			$ids = array_slice( $ids, 0, $limit );
		}

		if ( empty( $ids ) ) {
			\WP_CLI::success( __( 'Nenhum anexo pendente.', 'tainacan-ocr-search' ) );
			return;
		}

		$ok = 0;
		foreach ( $ids as $index => $id ) {
			$res = Queue::process_one( $id, $proc );
			$tag = sprintf( '[%d/%d] #%d %s', $index + 1, count( $ids ), $id, wp_basename( (string) get_attached_file( $id ) ) );
			if ( $res['success'] ) {
				++$ok;
				/* translators: 1: attachment label, 2: pages, 3: characters, 4: seconds */
				\WP_CLI::log( sprintf( __( '%1$s: %2$d páginas, %3$d caracteres, %4$ss', 'tainacan-ocr-search' ), $tag, $res['pages'], $res['text_length'], $res['seconds'] ) );
			} else {
				\WP_CLI::warning( $tag . ': ' . $res['message'] );
			}
		}

		/* translators: 1: processed, 2: total */
		\WP_CLI::success( sprintf( __( '%1$d de %2$d anexos processados.', 'tainacan-ocr-search' ), $ok, count( $ids ) ) );
	}

	/**
	 * Regrava no índice do Tainacan o texto dos documentos já processados.
	 *
	 * Útil depois de ligar a indexação de conteúdo de PDF do Tainacan.
	 *
	 * @param array $args       Posicionais.
	 * @param array $assoc_args Nomeados.
	 */
	public function reindex( $args, $assoc_args ) {
		$proc   = new Processor();
		$offset = 0;
		do {
			$done    = $proc->reindex_processed_documents( 200, $offset );
			$offset += $done;
		} while ( 200 === $done );
		/* translators: %d: number of processed attachments visited */
		\WP_CLI::success( sprintf( __( '%d anexos processados verificados.', 'tainacan-ocr-search' ), $offset ) );
	}

	/**
	 * Mostra dependências e o tamanho da fila.
	 *
	 * [--collection=<id>]
	 * : Só esta coleção Tainacan.
	 *
	 * @param array $args       Posicionais.
	 * @param array $assoc_args Nomeados.
	 */
	public function status( $args, $assoc_args ) {
		$proc       = new Processor();
		$collection = isset( $assoc_args['collection'] ) ? absint( $assoc_args['collection'] ) : null;

		foreach ( $proc->check_dependencies() as $name => $dep ) {
			\WP_CLI::log( sprintf( '%-20s %s %s', $name, empty( $dep['ok'] ) ? '--' : 'ok', $dep['version'] ?? '' ) );
		}
		/* translators: 1: pending, 2: eligible */
		\WP_CLI::log( sprintf( __( 'Pendentes: %1$d de %2$d anexos elegíveis', 'tainacan-ocr-search' ), count( $proc->list_eligible_attachments( $collection, true ) ), count( $proc->list_eligible_attachments( $collection, false ) ) ) );
	}
}
