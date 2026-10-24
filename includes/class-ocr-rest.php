<?php
/**
 * Endpoints REST do Tainacan OCR Search.
 * Toda a UI da página admin conversa com estes endpoints.
 */

namespace Tainacan_OCR_Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Controller {

	const NS = 'tainacan-ocr-search/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NS,
			'/check',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'check' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'permission' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/queue',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'queue' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'collection_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'only_pending'  => array(
						'type'     => 'boolean',
						'required' => false,
						'default'  => true,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/process',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'process' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'attachment_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value ) && (int) $value > 0 && 'attachment' === get_post_type( (int) $value );
						},
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/collections',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'collections' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);

		// Quadro do item: situação dos arquivos, aplicar OCR, ler o texto.
		$item_id_arg = array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
			'validate_callback' => static function ( $value ) {
				return is_numeric( $value ) && (int) $value > 0;
			},
		);
		register_rest_route(
			self::NS,
			'/item/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'item_status' ),
				'permission_callback' => array( $this, 'item_permission' ),
				'args'                => array( 'id' => $item_id_arg ),
			)
		);
		register_rest_route(
			self::NS,
			'/item/(?P<id>\d+)/ocr',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'item_enqueue' ),
				'permission_callback' => array( $this, 'item_permission' ),
				'args'                => array(
					'id'             => $item_id_arg,
					'attachment_ids' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'required' => false,
						'default'  => array(),
					),
					'force'          => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/item/(?P<id>\d+)/text/(?P<attachment>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'item_text' ),
				'permission_callback' => array( $this, 'item_permission' ),
				'args'                => array(
					'id'         => $item_id_arg,
					'attachment' => $item_id_arg,
				),
			)
		);

		// Lote da página admin: enfileira e acompanha (sem prender a tela).
		register_rest_route(
			self::NS,
			'/enqueue',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'batch_enqueue' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'attachment_ids' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'required' => true,
					),
					'force'          => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/dequeue',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'batch_dequeue' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'attachment_ids' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/progress',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'batch_progress' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'attachment_ids' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'required' => true,
					),
				),
			)
		);

		// Liga a indexação de conteúdo de PDF do Tainacan (opção do core),
		// sem a qual o OCR do documento principal não chega à busca nativa.
		register_rest_route(
			self::NS,
			'/core-pdf-index',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'enable_core_pdf_index' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	public function permission() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Quem pode editar o item pode ver e aplicar o OCR dos arquivos dele.
	 *
	 * @param \WP_REST_Request $req Requisição.
	 * @return bool
	 */
	public function item_permission( \WP_REST_Request $req ) {
		$item = $this->fetch_item( (int) $req->get_param( 'id' ) );
		return $item instanceof \Tainacan\Entities\Item && $item->can_edit();
	}

	/**
	 * Item do Tainacan por ID (null se não for item).
	 *
	 * @param int $item_id Item.
	 * @return \Tainacan\Entities\Item|null
	 */
	protected function fetch_item( $item_id ) {
		if ( $item_id <= 0 || ! class_exists( '\Tainacan\Repositories\Items' ) ) {
			return null;
		}
		$item = \Tainacan\Repositories\Items::get_instance()->fetch( $item_id );
		return $item instanceof \Tainacan\Entities\Item ? $item : null;
	}

	/**
	 * Situação dos arquivos do item.
	 *
	 * @param \WP_REST_Request $req Requisição.
	 * @return \WP_REST_Response
	 */
	public function item_status( \WP_REST_Request $req ) {
		$files = array();
		foreach ( Queue::item_files( (int) $req->get_param( 'id' ) ) as $file ) {
			$text    = (string) get_post_meta( $file['id'], Processor::META_TEXT, true );
			$preview = trim( (string) preg_replace( '/\s+/u', ' ', str_replace( "\f", ' ', mb_substr( $text, 0, 1200 ) ) ) );
			$files[] = array_merge(
				array(
					'id'       => $file['id'],
					'role'     => $file['role'],
					'name'     => wp_basename( (string) get_attached_file( $file['id'] ) ),
					'url'      => (string) wp_get_attachment_url( $file['id'] ),
					'is_image' => wp_attachment_is_image( $file['id'] ),
					'preview'  => mb_substr( $preview, 0, 400 ),
				),
				Queue::describe( $file['id'], true )
			);
		}

		// Quadro aberto com arquivo na fila: acorda o worker sem esperar visita.
		if ( array_intersect( array( 'queued', 'processing' ), array_column( $files, 'status' ) ) ) {
			Queue::kick();
		}

		$options = get_option( 'tainacan_ocr_search_options', array() );
		return rest_ensure_response(
			array(
				'files'        => $files,
				'auto_process' => ! empty( $options['auto_process'] ),
			)
		);
	}

	/**
	 * Aplica OCR nos arquivos do item (os indicados, ou todos ainda sem texto).
	 *
	 * @param \WP_REST_Request $req Requisição.
	 * @return \WP_REST_Response
	 */
	public function item_enqueue( \WP_REST_Request $req ) {
		$files     = Queue::item_files( (int) $req->get_param( 'id' ) );
		$allowed   = array_column( $files, 'id' );
		$requested = array_map( 'intval', (array) $req->get_param( 'attachment_ids' ) );

		if ( empty( $requested ) ) {
			// Sem lista: os que ainda não têm texto (nunca processados ou com falha).
			foreach ( $allowed as $id ) {
				$status = Queue::describe( $id )['status'];
				if ( 'none' === $status || 'failed' === $status ) {
					$requested[] = $id;
				}
			}
		}

		// Só arquivos deste item: o ID vem do navegador. "Refazer" manda force.
		Queue::enqueue( array_values( array_intersect( $requested, $allowed ) ), (bool) $req->get_param( 'force' ) );

		return $this->item_status( $req );
	}

	/**
	 * Texto reconhecido de um arquivo do item, página a página.
	 *
	 * @param \WP_REST_Request $req Requisição.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function item_text( \WP_REST_Request $req ) {
		$attachment = (int) $req->get_param( 'attachment' );
		if ( ! in_array( $attachment, array_column( Queue::item_files( (int) $req->get_param( 'id' ) ), 'id' ), true ) ) {
			return new \WP_Error( 'not_in_item', __( 'Este arquivo não pertence ao item.', 'tainacan-ocr-search' ), array( 'status' => 404 ) );
		}

		$pages = array();
		foreach ( explode( "\f", (string) get_post_meta( $attachment, Processor::META_TEXT, true ) ) as $index => $page ) {
			if ( '' !== trim( $page ) ) {
				$pages[] = array(
					'page' => $index + 1,
					'text' => mb_substr( trim( $page ), 0, 20000 ),
				);
			}
		}

		return rest_ensure_response( array( 'pages' => $pages ) );
	}

	/**
	 * Lote: enfileira anexos.
	 *
	 * @param \WP_REST_Request $req Requisição.
	 * @return \WP_REST_Response
	 */
	public function batch_enqueue( \WP_REST_Request $req ) {
		$ids = array_map( 'intval', (array) $req->get_param( 'attachment_ids' ) );
		return rest_ensure_response( array( 'queued' => Queue::enqueue( $ids, (bool) $req->get_param( 'force' ) ) ) );
	}

	/**
	 * Lote: tira da fila o que ainda não começou.
	 *
	 * @param \WP_REST_Request $req Requisição.
	 * @return \WP_REST_Response
	 */
	public function batch_dequeue( \WP_REST_Request $req ) {
		return rest_ensure_response( array( 'removed' => Queue::dequeue( array_map( 'intval', (array) $req->get_param( 'attachment_ids' ) ) ) ) );
	}

	/**
	 * Lote: situação de cada anexo.
	 *
	 * @param \WP_REST_Request $req Requisição.
	 * @return \WP_REST_Response
	 */
	public function batch_progress( \WP_REST_Request $req ) {
		$out = array();
		foreach ( array_slice( array_map( 'intval', (array) $req->get_param( 'attachment_ids' ) ), 0, Queue::MAX_IDS_PER_CALL ) as $id ) {
			$out[ $id ] = Queue::describe( $id );
		}
		// O worker anda com as visitas ao site; o acompanhamento também o acorda.
		Queue::kick();
		return rest_ensure_response( $out );
	}

	public function check() {
		$proc = new Processor();
		return rest_ensure_response( $proc->check_dependencies() );
	}

	public function get_settings() {
		return rest_ensure_response( wp_parse_args( get_option( 'tainacan_ocr_search_options', array() ), Processor::default_options() ) );
	}

	public function save_settings( \WP_REST_Request $req ) {
		$in   = $req->get_json_params();
		$opts = wp_parse_args( is_array( $in ) ? $in : array(), wp_parse_args( get_option( 'tainacan_ocr_search_options', array() ), Processor::default_options() ) );

		$clean = array(
			'tesseract_path'   => sanitize_text_field( $opts['tesseract_path'] ?? '' ),
			'ocrmypdf_path'    => sanitize_text_field( $opts['ocrmypdf_path'] ?? '' ),
			'language'         => sanitize_text_field( $opts['language'] ?? 'por' ),
			'auto_process'     => empty( $opts['auto_process'] ) ? 0 : 1,
			'deskew'           => empty( $opts['deskew'] ) ? 0 : 1,
			'clean'            => empty( $opts['clean'] ) ? 0 : 1,
			'force_ocr'        => empty( $opts['force_ocr'] ) ? 0 : 1,
			'replace_original' => empty( $opts['replace_original'] ) ? 0 : 1,
		);

		foreach ( array( 'tesseract', 'ocrmypdf' ) as $tool ) {
			$valid = Processor::validate_binary_path( $tool, $clean[ $tool . '_path' ] );
			if ( is_wp_error( $valid ) ) {
				$valid->add_data( array( 'status' => 400 ) );
				return $valid;
			}
		}
		if ( ! Processor::is_valid_language( $clean['language'] ) ) {
			return new \WP_Error( 'invalid_language', __( 'Idioma inválido. Use códigos do Tesseract, como por, eng ou por+eng.', 'tainacan-ocr-search' ), array( 'status' => 400 ) );
		}

		update_option( 'tainacan_ocr_search_options', $clean );
		return rest_ensure_response(
			array(
				'saved'   => true,
				'options' => $clean,
			)
		);
	}

	public function queue( \WP_REST_Request $req ) {
		$proc = new Processor();
		$ids  = $proc->list_eligible_attachments(
			$req->get_param( 'collection_id' ) ? $req->get_param( 'collection_id' ) : null,
			false !== $req->get_param( 'only_pending' )
		);
		return rest_ensure_response(
			array(
				'count' => count( $ids ),
				'ids'   => $ids,
			)
		);
	}

	public function process( \WP_REST_Request $req ) {
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- OCR of a multi-page scan takes minutes; the default 30 s would cut it mid-document.
			set_time_limit( 900 );
		}
		return rest_ensure_response( Queue::process_one( (int) $req->get_param( 'attachment_id' ), new Processor() ) );
	}

	public function collections() {
		$collections = get_posts(
			array(
				'post_type'      => 'tainacan-collection',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		return rest_ensure_response(
			array_map(
				static function ( $post ) {
					return array(
						'ID'         => (int) $post->ID,
						'post_title' => get_the_title( $post ),
					);
				},
				$collections
			)
		);
	}

	public function enable_core_pdf_index() {
		if ( defined( 'TAINACAN_INDEX_PDF_CONTENT' ) ) {
			return new \WP_Error( 'constant_defined', __( 'A constante TAINACAN_INDEX_PDF_CONTENT está definida no wp-config.php e controla esta opção.', 'tainacan-ocr-search' ), array( 'status' => 409 ) );
		}
		update_option( 'tainacan_option_index_pdf_content', true );
		// Documentos que passaram por OCR com a opção desligada entram no
		// índice agora, em segundo plano.
		if ( ! wp_next_scheduled( 'tainacan_ocr_search_reindex', array( 0 ) ) ) {
			wp_schedule_single_event( time() + 5, 'tainacan_ocr_search_reindex', array( 0 ) );
		}
		return rest_ensure_response( array( 'enabled' => Processor::is_core_pdf_index_enabled() ) );
	}
}
