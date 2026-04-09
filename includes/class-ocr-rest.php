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
		register_rest_route( self::NS, '/check', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'check' ),
			'permission_callback' => array( $this, 'permission' ),
		) );

		register_rest_route( self::NS, '/settings', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'permission' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_settings' ),
				'permission_callback' => array( $this, 'permission' ),
			),
		) );

		register_rest_route( self::NS, '/queue', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'queue' ),
			'permission_callback' => array( $this, 'permission' ),
			'args'                => array(
				'collection_id' => array( 'type' => 'integer', 'required' => false ),
				'only_pending'  => array( 'type' => 'boolean', 'required' => false, 'default' => true ),
			),
		) );

		register_rest_route( self::NS, '/process', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'process' ),
			'permission_callback' => array( $this, 'permission' ),
			'args'                => array(
				'attachment_id' => array( 'type' => 'integer', 'required' => true ),
			),
		) );

		register_rest_route( self::NS, '/collections', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'collections' ),
			'permission_callback' => array( $this, 'permission' ),
		) );
	}

	// IMPORTANTE: callbacks REST do WP devem ser PUBLIC.
	public function permission() {
		return current_user_can( 'manage_options' );
	}

	public function check() {
		$proc = new Processor();
		return rest_ensure_response( $proc->check_dependencies() );
	}

	public function get_settings() {
		return rest_ensure_response( get_option( 'tainacan_ocr_search_options', array() ) );
	}

	public function save_settings( \WP_REST_Request $req ) {
		$in   = $req->get_json_params();
		$opts = wp_parse_args( (array) $in, get_option( 'tainacan_ocr_search_options', array() ) );

		$clean = array(
			'tesseract_path' => sanitize_text_field( $opts['tesseract_path'] ?? '' ),
			'ocrmypdf_path'  => sanitize_text_field( $opts['ocrmypdf_path'] ?? '' ),
			'language'       => sanitize_text_field( $opts['language'] ?? 'por' ),
			'auto_process'   => empty( $opts['auto_process'] ) ? 0 : 1,
			'deskew'         => empty( $opts['deskew'] ) ? 0 : 1,
			'clean'          => empty( $opts['clean'] ) ? 0 : 1,
			'force_ocr'      => empty( $opts['force_ocr'] ) ? 0 : 1,
		);
		update_option( 'tainacan_ocr_search_options', $clean );
		return rest_ensure_response( array( 'saved' => true, 'options' => $clean ) );
	}

	public function queue( \WP_REST_Request $req ) {
		$proc = new Processor();
		$ids = $proc->list_eligible_attachments(
			$req->get_param( 'collection_id' ) ?: null,
			$req->get_param( 'only_pending' ) !== false
		);
		return rest_ensure_response( array(
			'count' => count( $ids ),
			'ids'   => $ids,
		) );
	}

	public function process( \WP_REST_Request $req ) {
		@set_time_limit( 300 );
		$proc = new Processor();
		$res  = $proc->process_attachment( (int) $req->get_param( 'attachment_id' ) );
		return rest_ensure_response( $res );
	}

	public function collections() {
		global $wpdb;
		// Coleções Tainacan são post_type 'tainacan-collection'.
		$rows = $wpdb->get_results(
			"SELECT ID, post_title FROM {$wpdb->posts}
			 WHERE post_type = 'tainacan-collection' AND post_status = 'publish'
			 ORDER BY post_title ASC"
		);
		return rest_ensure_response( $rows ?: array() );
	}
}
