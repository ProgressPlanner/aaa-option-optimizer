<?php
/**
 * REST functionality for AAA Option Optimizer.
 *
 * @package Emilia\OptionOptimizer
 */

namespace Emilia\OptionOptimizer;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST functionality of AAA Option Optimizer.
 */
class REST {

	/**
	 * The map plugin to options class.
	 *
	 * @var Map_Plugin_To_Options
	 */
	private $map_plugin_to_options;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		$this->map_plugin_to_options = new Map_Plugin_To_Options();

		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/**
	 * Register the REST API routes.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		\register_rest_route(
			'aaa-option-optimizer/v1',
			'/update-autoload',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_option_autoload' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => [
					'option_name' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'autoload'    => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		\register_rest_route(
			'aaa-option-optimizer/v1',
			'/delete-option',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'delete_option' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => [
					'option_name' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		\register_rest_route(
			'aaa-option-optimizer/v1',
			'/create-option-false',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_option_false' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => [
					'option_name' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		\register_rest_route(
			'aaa-option-optimizer/v1',
			'/all-options',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_all_options' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);

		\register_rest_route(
			'aaa-option-optimizer/v1',
			'/reset',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'reset_stats' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);

		\register_rest_route(
			'aaa-option-optimizer/v1',
			'/unused-options',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_unused_options' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);

		\register_rest_route(
			'aaa-option-optimizer/v1',
			'/used-not-autoloaded-options',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_used_not_autoloaded_options' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);

		\register_rest_route(
			'aaa-option-optimizer/v1',
			'/options-that-do-not-exist',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_options_that_do_not_exist' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			]
		);
	}

	/**
	 * Update autoload status of an option.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function reset_stats() {
		Plugin::get_instance()->reset();
		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Update autoload status of an option.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_all_options() {
		global $wpdb;

		$output = [];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- We need to query all options.
		$options = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM $wpdb->options" );
		foreach ( $options as $option ) {
			$output[] = [
				'name'     => $option->option_name,
				'plugin'   => $this->map_plugin_to_options->get_plugin_name( $option->option_name ),
				'value'    => htmlentities( $option->option_value, ENT_QUOTES | ENT_SUBSTITUTE ),
				'size'     => number_format( strlen( $option->option_value ) / 1024, 2 ),
				'autoload' => $option->autoload,
				'row_id'   => 'option_' . $option->option_name,
			];
		}
		return new \WP_REST_Response( [ 'data' => $output ], 200 );
	}

	/**
	 * Get unused options.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_unused_options() {
		if ( ! isset( $_SERVER['HTTP_X_WP_NONCE'] ) || ! wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ), 'wp_rest' ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid nonce' ], 403 );
		}

		global $wpdb;

		// 1. Load used options from option_optimizer
		$option_optimizer = get_option( 'option_optimizer', [ 'used_options' => [] ] );
		$used_options     = $option_optimizer['used_options'];

		// 3. Get autoloaded, non-transient option names
		$autoloaded_option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"
			SELECT option_name
			FROM {$wpdb->options}
			WHERE autoload IN ( '" . implode( "', '", esc_sql( \wp_autoload_values_to_autoload() ) ) . "' )
			AND option_name NOT LIKE '%_transient_%'
		"
		);

		// 4. Find unused autoloaded option names
		$autoload_option_keys = array_fill_keys( $autoloaded_option_names, true );
		$unused_keys          = array_diff_key( $autoload_option_keys, $used_options );
		$total_unused         = count( $unused_keys );

		// 5. Pagination
		$offset             = isset( $_GET['start'] ) ? intval( $_GET['start'] ) : 0;
		$limit              = isset( $_GET['length'] ) ? intval( $_GET['length'] ) : 25;
		$paged_option_names = array_slice( array_keys( $unused_keys ), $offset, $limit );

		$unused_options = [];

		if ( ! empty( $paged_option_names ) ) {
			// 6. Prepare placeholders and SQL query
			$placeholders = implode( ',', array_fill( 0, count( $paged_option_names ), '%s' ) );

			$query = "
				SELECT option_name, option_value
				FROM {$wpdb->options}
				WHERE option_name IN ( {$placeholders} )
			";

			$results = $wpdb->get_results( $wpdb->prepare( $query, ...$paged_option_names ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

			// 7. Format output
			foreach ( $results as $row ) {
				$unused_options[] = [
					'name'     => $row->option_name,
					'plugin'   => $this->map_plugin_to_options->get_plugin_name( $row->option_name ),
					'value'    => htmlentities( $row->option_value, ENT_QUOTES | ENT_SUBSTITUTE ),
					'size'     => number_format( strlen( $row->option_value ) / 1024, 2 ),
					'autoload' => 'yes',
					'row_id'   => 'option_' . $row->option_name,
				];
			}
		}

		// 8. Return response
		return new \WP_REST_Response(
			[
				'draw'            => intval( $_GET['draw'] ?? 0 ),
				'recordsTotal'    => $total_unused,
				'recordsFiltered' => $total_unused,
				'data'            => $unused_options,
			],
			200
		);
	}

	/**
	 * WIP: Get used, but not autoloaded options.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_used_not_autoloaded_options() {
		if ( ! isset( $_SERVER['HTTP_X_WP_NONCE'] ) || ! wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ), 'wp_rest' ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid nonce' ], 403 );
		}

		global $wpdb;

		// 1. Load and normalize used options
		$option_optimizer = get_option( 'option_optimizer', [ 'used_options' => [] ] );
		$used_options     = $option_optimizer['used_options'];

		if ( empty( $used_options ) ) {
			return new \WP_REST_Response(
				[
					'draw'            => intval( $_GET['draw'] ?? 0 ),
					'recordsTotal'    => 0,
					'recordsFiltered' => 0,
					'data'            => [],
				],
				200
			);
		}

		// 2. Get all autoloaded, non-transient option names
		$autoloaded_keys = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			"
			SELECT option_name
			FROM {$wpdb->options}
			WHERE autoload IN ( 'yes', 'on', 'true', '1' )
			AND option_name NOT LIKE '%_transient_%'
		"
		);

		$autoloaded_keys = array_fill_keys( $autoloaded_keys, true );

		// 3. Find used options not autoloaded
		$non_autoloaded_used_keys = array_diff_key( $used_options, $autoloaded_keys );

		if ( empty( $non_autoloaded_used_keys ) ) {
			return new \WP_REST_Response(
				[
					'draw'            => intval( $_GET['draw'] ?? 0 ),
					'recordsTotal'    => 0,
					'recordsFiltered' => 0,
					'data'            => [],
				],
				200
			);
		}

		// 4. Pagination
		$offset = isset( $_GET['start'] ) ? intval( $_GET['start'] ) : 0;
		$limit  = isset( $_GET['length'] ) ? intval( $_GET['length'] ) : 25;

		$keys_to_fetch = array_slice( array_keys( $non_autoloaded_used_keys ), $offset, $limit );

		// 5. Fetch values directly from DB without touching get_option()
		$placeholders = implode( ',', array_fill( 0, count( $keys_to_fetch ), '%s' ) );
		$sql          = "
			SELECT option_name, option_value
			FROM {$wpdb->options}
			WHERE option_name IN ($placeholders)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$keys_to_fetch ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 6. Format response
		$response_data = [];

		foreach ( $results as $row ) {
			$response_data[] = [
				'name'     => $row->option_name,
				'plugin'   => $this->map_plugin_to_options->get_plugin_name( $row->option_name ),
				'value'    => htmlentities( maybe_serialize( $row->option_value ), ENT_QUOTES | ENT_SUBSTITUTE ),
				'size'     => number_format( strlen( $row->option_value ) / 1024, 2 ),
				'autoload' => 'no',
				'count'    => $used_options[ $row->option_name ] ?? 0,
			];
		}

		// 7. Return final response
		return new \WP_REST_Response(
			[
				'draw'            => intval( $_GET['draw'] ?? 0 ),
				'recordsTotal'    => count( $non_autoloaded_used_keys ),
				'recordsFiltered' => count( $non_autoloaded_used_keys ),
				'data'            => $response_data,
			],
			200
		);
	}

	/**
	 * WIP: Get used, but not autoloaded options.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_options_that_do_not_exist() {
		if ( ! isset( $_SERVER['HTTP_X_WP_NONCE'] ) || ! wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ), 'wp_rest' ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid nonce' ], 403 );
		}

		global $wpdb;

		// 1. Load and normalize used options
		$option_optimizer = get_option( 'option_optimizer', [ 'used_options' => [] ] );
		$used_options     = $option_optimizer['used_options'] ?? [];

		if ( empty( $used_options ) ) {
			return new \WP_REST_Response(
				[
					'draw'            => intval( $_GET['draw'] ?? 0 ),
					'recordsTotal'    => 0,
					'recordsFiltered' => 0,
					'data'            => [],
				],
				200
			);
		}

		// 2. Get autoloaded, non-transient options
		$autoloaded_option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"
			SELECT option_name
			FROM {$wpdb->options}
			WHERE autoload IN ('yes', 'on', 'true', '1')
			AND option_name NOT LIKE '%_transient_%'
		"
		);
		$autoloaded_option_keys  = array_fill_keys( $autoloaded_option_names, true );

		// 3. Get used options that are not autoloaded
		$non_autoloaded_keys = array_diff_key( $used_options, $autoloaded_option_keys );

		if ( empty( $non_autoloaded_keys ) ) {
			return new \WP_REST_Response(
				[
					'draw'            => intval( $_GET['draw'] ?? 0 ),
					'recordsTotal'    => 0,
					'recordsFiltered' => 0,
					'data'            => [],
				],
				200
			);
		}

		// 4. Check which of them actually exist in the options table
		$option_names = array_keys( $non_autoloaded_keys );
		$placeholders = implode( ',', array_fill( 0, count( $option_names ), '%s' ) );

		$existing_option_names = $wpdb->get_col(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...$option_names
			)
		);
		$existing_keys         = array_fill_keys( $existing_option_names, true );

		// 5. Filter only those that do NOT exist
		$options_that_do_not_exist = [];
		foreach ( $non_autoloaded_keys as $option => $count ) {
			if ( ! isset( $existing_keys[ $option ] ) ) {
				$options_that_do_not_exist[] = [
					'name'        => $option,
					'plugin'      => $this->map_plugin_to_options->get_plugin_name( $option ),
					'count'       => $count,
					'option_name' => $option,
				];
			}
		}

		// 6. Pagination
		$offset     = isset( $_GET['start'] ) ? intval( $_GET['start'] ) : 0;
		$limit      = isset( $_GET['length'] ) ? intval( $_GET['length'] ) : 25;
		$paged_data = array_slice( $options_that_do_not_exist, $offset, $limit );

		// 7. Return response
		return new \WP_REST_Response(
			[
				'draw'            => intval( $_GET['draw'] ?? 0 ),
				'recordsTotal'    => count( $options_that_do_not_exist ),
				'recordsFiltered' => count( $options_that_do_not_exist ),
				'data'            => $paged_data,
			],
			200
		);
	}


	/**
	 * Update autoload status of an option.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function update_option_autoload( $request ) {
		$option_name  = $request['option_name'];
		$autoload     = $request['autoload'];
		$option_value = get_option( $option_name );

		if ( ! in_array( $autoload, [ 'yes', 'on', 'no', 'off','auto', 'auto-on', 'auto-off' ], true ) ) {
			return new \WP_Error( 'invalid_autoload_value', 'Invalid autoload value', [ 'status' => 400 ] );
		}

		if ( false === $option_value ) {
			return new \WP_Error( 'option_not_found', 'Option does not exist', [ 'status' => 404 ] );
		}

		delete_option( $option_name );
		$autoload_values = \wp_autoload_values_to_autoload();
		$bool_autoload   = false;
		if ( in_array( $autoload, $autoload_values, true ) ) {
			$bool_autoload = true;
		}
		$succeeded = add_option( $option_name, $option_value, '', $bool_autoload );

		if ( ! $succeeded ) {
			return new \WP_Error( 'update_failed', 'Updating the option failed', [ 'status' => 400 ] );
		}
		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Delete an option.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_option( $request ) {
		$option_name = $request['option_name'];
		if ( delete_option( $option_name ) ) {
			return new \WP_REST_Response( [ 'success' => true ], 200 );
		}
		return new \WP_Error( 'option_not_found_or_deleted', 'Option does not exist or could not be deleted', [ 'status' => 404 ] );
	}

	/**
	 * Create an option with a false value.
	 *
	 * @param \WP_REST_Request $request  The REST request object.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function create_option_false( $request ) {
		$option_name = $request['option_name'];
		if ( add_option( $option_name, false, '', false ) ) {
			return new \WP_REST_Response( [ 'success' => true ], 200 );
		}
		return new \WP_Error( 'option_not_created', 'Option could not be created', [ 'status' => 400 ] );
	}
}
