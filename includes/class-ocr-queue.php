<?php
/**
 * Fila de OCR em segundo plano (WP-Cron).
 *
 * O OCR de um PDF escaneado leva minutos; rodar dentro da requisição prendia
 * a tela até terminar. Agora toda origem (quadro do item, lote da página
 * admin, OCR automático no envio) só enfileira, e o worker processa um
 * arquivo por vez, em segundo plano.
 */

namespace Tainacan_OCR_Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Queue {

	const CRON_HOOK = 'tainacan_ocr_search_run_queue';

	/** Situação no anexo: queued | processing | failed (concluído = sem meta + META_PROCESSED). */
	const META_STATUS     = '_tainacan_ocr_status';
	const META_QUEUED_AT  = '_tainacan_ocr_queued_at';
	const META_STARTED_AT = '_tainacan_ocr_started_at';

	/** Lock do worker (uma execução por vez). */
	const LOCK_OPTION = 'tainacan_ocr_search_queue_lock';

	/**
	 * Processamento sem resposta há mais que isso volta para a fila, e lock
	 * mais velho que isso é considerado abandonado. Folgado de propósito: um
	 * relatório escaneado de centenas de páginas leva quase uma hora num
	 * servidor de 1 núcleo, e o lock só é renovado entre um arquivo e outro.
	 */
	const STALE_SECONDS = 10800;

	/** Teto de IDs por chamada de enqueue/progress (o JS manda em partes). */
	const MAX_IDS_PER_CALL = 1000;

	/**
	 * Põe anexos na fila, na ordem recebida, e acorda o worker.
	 *
	 * @param int[] $attachment_ids Anexos (o primeiro é processado primeiro).
	 * @param bool  $force          Refazer também os que já têm texto.
	 * @return int Quantos entraram na fila.
	 */
	public static function enqueue( array $attachment_ids, $force = false ) {
		$count = 0;
		// Milissegundos + posição: arquivos enfileirados no mesmo segundo
		// mantêm a ordem (o documento principal vem primeiro).
		$base = (int) floor( microtime( true ) * 1000 );
		foreach ( array_slice( array_values( array_unique( array_map( 'intval', $attachment_ids ) ) ), 0, self::MAX_IDS_PER_CALL ) as $index => $id ) {
			if ( $id <= 0 || ! Processor::is_supported_attachment( $id ) ) {
				continue;
			}
			$status = self::raw_status( $id );
			if ( 'queued' === $status || 'processing' === $status ) {
				continue;
			}
			// Já tem texto: só volta à fila quando pedem para refazer.
			if ( ! $force && 'failed' !== $status && '' !== (string) get_post_meta( $id, Processor::META_PROCESSED, true ) ) {
				continue;
			}
			update_post_meta( $id, self::META_STATUS, 'queued' );
			update_post_meta( $id, self::META_QUEUED_AT, $base + $index );
			delete_post_meta( $id, self::META_STARTED_AT );
			++$count;
		}

		if ( $count ) {
			self::kick();
		}
		return $count;
	}

	/**
	 * Processa um arquivo agora, marcando a situação (worker, CLI e rota antiga).
	 *
	 * @param int       $attachment_id Anexo.
	 * @param Processor $processor     Processador.
	 * @return array Resultado de Processor::process_attachment().
	 */
	public static function process_one( $attachment_id, Processor $processor ) {
		$attachment_id = (int) $attachment_id;
		$status        = self::raw_status( $attachment_id );
		$started       = (int) get_post_meta( $attachment_id, self::META_STARTED_AT, true );
		if ( 'processing' === $status && time() - $started < self::STALE_SECONDS ) {
			return array(
				'success'     => false,
				'message'     => __( 'Este arquivo já está sendo processado.', 'tainacan-ocr-search' ),
				'text_length' => 0,
			);
		}

		update_post_meta( $attachment_id, self::META_STATUS, 'processing' );
		update_post_meta( $attachment_id, self::META_STARTED_AT, time() );

		try {
			$result = $processor->process_attachment( $attachment_id );
		} catch ( \Throwable $e ) {
			// Erro de outro plugin num gancho não pode travar a fila inteira.
			update_post_meta( $attachment_id, Processor::META_LOG, wp_slash( $e->getMessage() ) );
			$result = array(
				'success'     => false,
				'message'     => $e->getMessage(),
				'text_length' => 0,
			);
		}

		if ( ! empty( $result['success'] ) ) {
			delete_post_meta( $attachment_id, self::META_STATUS );
		} else {
			update_post_meta( $attachment_id, self::META_STATUS, 'failed' );
		}
		delete_post_meta( $attachment_id, self::META_QUEUED_AT );
		delete_post_meta( $attachment_id, self::META_STARTED_AT );

		return $result;
	}

	/**
	 * Tira da fila o que ainda não começou ("Pausar" do lote).
	 *
	 * @param int[] $attachment_ids Anexos.
	 * @return int Quantos saíram.
	 */
	public static function dequeue( array $attachment_ids ) {
		$count = 0;
		foreach ( array_unique( array_map( 'intval', $attachment_ids ) ) as $id ) {
			if ( 'queued' === self::raw_status( $id ) ) {
				delete_post_meta( $id, self::META_STATUS );
				delete_post_meta( $id, self::META_QUEUED_AT );
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Agenda o worker para já e dispara o WP-Cron sem esperar a próxima visita.
	 */
	public static function kick() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Worker: processa a fila, um arquivo por vez, por até ~10 minutos.
	 */
	public static function run() {
		// Lock atômico (add_option falha se já existe) com dono: cada execução
		// só apaga o próprio lock.
		$token = wp_generate_uuid4();
		if ( ! add_option( self::LOCK_OPTION, time() . '|' . $token, '', false ) ) {
			list( $since ) = explode( '|', (string) get_option( self::LOCK_OPTION, '0|' ) . '|' );
			if ( time() - (int) $since < self::STALE_SECONDS ) {
				return; // outra execução em andamento
			}
			delete_option( self::LOCK_OPTION );
			if ( ! add_option( self::LOCK_OPTION, time() . '|' . $token, '', false ) ) {
				return;
			}
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Background OCR worker; a long scanned report takes much longer than the default 30 s.
			set_time_limit( 0 );
		}

		$started   = time();
		$processor = new Processor();

		try {
			while ( time() - $started < 600 ) {
				$next = self::next_queued();
				if ( ! $next ) {
					break;
				}
				update_option( self::LOCK_OPTION, time() . '|' . $token, false );
				self::process_one( $next, $processor );
			}
		} finally {
			$current = (string) get_option( self::LOCK_OPTION, '' );
			if ( substr( $current, -strlen( $token ) ) === $token ) {
				delete_option( self::LOCK_OPTION );
			}
		}

		if ( self::next_queued() ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	/**
	 * Próximo anexo da fila (mais antigo primeiro); recupera processamentos travados.
	 *
	 * @return int 0 se a fila está vazia.
	 */
	protected static function next_queued() {
		$stuck = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 20,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Queue lookup by the plugin's own meta key; runs only in the background worker.
				'meta_query'     => array(
					array(
						'key'   => self::META_STATUS,
						'value' => 'processing',
					),
				),
			)
		);
		foreach ( $stuck as $id ) {
			if ( time() - (int) get_post_meta( $id, self::META_STARTED_AT, true ) > self::STALE_SECONDS ) {
				update_post_meta( $id, self::META_STATUS, 'queued' );
			}
		}

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Queue lookup by the plugin's own meta keys; runs only in the background worker.
				'meta_key'       => self::META_QUEUED_AT,
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'   => self::META_STATUS,
						'value' => 'queued',
					),
				),
				// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Situação gravada no anexo (sem interpretar).
	 *
	 * @param int $attachment_id Anexo.
	 * @return string
	 */
	protected static function raw_status( $attachment_id ) {
		return (string) get_post_meta( $attachment_id, self::META_STATUS, true );
	}

	/**
	 * Situação de um anexo para a interface.
	 *
	 * @param int  $attachment_id Anexo.
	 * @param bool $with_position Calcular a posição na fila (consulta extra; só o quadro do item usa).
	 * @return array{status: string, pages: int, chars: int, processed_at: string, error: string, position: int}
	 */
	public static function describe( $attachment_id, $with_position = false ) {
		$attachment_id = (int) $attachment_id;
		$raw           = self::raw_status( $attachment_id );
		$text          = (string) get_post_meta( $attachment_id, Processor::META_TEXT, true );
		$processed     = (string) get_post_meta( $attachment_id, Processor::META_PROCESSED, true );

		if ( in_array( $raw, array( 'queued', 'processing', 'failed' ), true ) ) {
			$status = $raw;
		} elseif ( '' !== $processed ) {
			$status = '' !== trim( $text ) ? 'done' : 'empty';
		} else {
			$status = 'none';
		}

		return array(
			'status'       => $status,
			'pages'        => (int) get_post_meta( $attachment_id, Processor::META_PAGES, true ),
			'chars'        => mb_strlen( $text ),
			'processed_at' => $processed,
			'started_at'   => (int) get_post_meta( $attachment_id, self::META_STARTED_AT, true ),
			'error'        => 'failed' === $status ? wp_strip_all_tags( (string) get_post_meta( $attachment_id, Processor::META_LOG, true ) ) : '',
			'position'     => $with_position && 'queued' === $status ? self::position( $attachment_id ) : 0,
		);
	}

	/**
	 * Posição na fila (1 = próximo).
	 *
	 * @param int $attachment_id Anexo.
	 * @return int
	 */
	protected static function position( $attachment_id ) {
		$mine  = (int) get_post_meta( $attachment_id, self::META_QUEUED_AT, true );
		$ahead = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small queue lookup by the plugin's own meta keys; only for the status panel.
				'meta_query'     => array(
					array(
						'key'   => self::META_STATUS,
						'value' => 'queued',
					),
					array(
						'key'     => self::META_QUEUED_AT,
						'value'   => $mine,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		return count( $ahead ) + 1;
	}

	/**
	 * Arquivos do item que podem passar por OCR (documento primeiro).
	 *
	 * Fica de fora a miniatura que o Tainacan gera para o item, salvo quando
	 * o próprio documento é a imagem.
	 *
	 * @param int $item_id Item.
	 * @return array<int, array{id: int, role: string}>
	 */
	public static function item_files( $item_id ) {
		$item_id     = (int) $item_id;
		$document_id = 'attachment' === get_post_meta( $item_id, 'document_type', true ) ? (int) get_post_meta( $item_id, 'document', true ) : 0;
		$thumbnail   = (int) get_post_thumbnail_id( $item_id );

		$files = array();
		if ( $document_id && Processor::is_supported_attachment( $document_id ) ) {
			$files[] = array(
				'id'   => $document_id,
				'role' => 'document',
			);
		}

		$children = get_children(
			array(
				'post_parent' => $item_id,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'orderby'     => 'menu_order ID',
				'order'       => 'ASC',
				'fields'      => 'ids',
			)
		);
		foreach ( (array) $children as $id ) {
			$id = (int) $id;
			if ( $id === $document_id || $id === $thumbnail || ! Processor::is_supported_attachment( $id ) ) {
				continue;
			}
			$files[] = array(
				'id'   => $id,
				'role' => 'attachment',
			);
		}

		return $files;
	}
}
