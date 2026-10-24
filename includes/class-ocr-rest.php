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
		$proc = new Processor();
		return rest_ensure_response( $proc->process_attachment( (int) $req->get_param( 'attachment_id' ) ) );
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
