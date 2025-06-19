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
		if ( ! isset( $_SERVER['HTTP_X_WP_NONCE'] ) || ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ), 'wp_rest' ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid nonce' ], 403 );
		}

		global $wpdb;

		// Load used options from option_optimizer.
		$option_optimizer = get_option( 'option_optimizer', [ 'used_options' => [] ] );
		$used_options     = $option_optimizer['used_options'];

		$query = "
			SELECT option_name
			FROM {$wpdb->options}
			WHERE autoload IN ( '" . implode( "', '", esc_sql( \wp_autoload_values_to_autoload() ) ) . "' )
			AND option_name NOT LIKE '%_transient_%'
		";

		// Search.
		$search = isset( $_GET['search']['value'] ) ? trim( \sanitize_text_field( \wp_unslash( $_GET['search']['value'] ) ) ) : '';
		if ( '' !== $search ) {
			$query .= " AND option_name LIKE '%" . esc_sql( $search ) . "%'";
		}

		// Get autoloaded, non-transient option names.
		$autoloaded_option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$query // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		// Find unused autoloaded option names.
		$autoload_option_keys = array_fill_keys( $autoloaded_option_names, true );
		$unused_keys          = array_diff_key( $autoload_option_keys, $used_options );
		$total_unused         = count( $unused_keys );

		// Sort order.
		$order_column = isset( $_GET['order'][0]['name'] ) ? \sanitize_text_field( \wp_unslash( $_GET['order'][0]['name'] ) ) : 'name';
		$order_dir    = isset( $_GET['order'][0]['dir'] ) ? \strtolower( \sanitize_text_field( \wp_unslash( $_GET['order'][0]['dir'] ) ) ) : 'asc';
		$order_dir    = 'desc' === $order_dir ? SORT_DESC : SORT_ASC;

		// Pagination.
		$offset             = isset( $_GET['start'] ) ? intval( $_GET['start'] ) : 0;
		$limit              = isset( $_GET['length'] ) ? intval( $_GET['length'] ) : 25;
		$paged_option_names = array_keys( $unused_keys );

		// Slice early when default sorting.
		if ( 'name' === $order_column && SORT_ASC === $order_dir ) {
			$paged_option_names = array_slice( $paged_option_names, $offset, $limit );
		}

		$response_data = [];

		if ( ! empty( $paged_option_names ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $paged_option_names ), '%s' ) );
			$value_query  = "
				SELECT option_name, option_value
				FROM {$wpdb->options}
				WHERE option_name IN ( {$placeholders} )
			";

			$results = $wpdb->get_results( $wpdb->prepare( $value_query, ...$paged_option_names ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

			// Format output.
			foreach ( $results as $row ) {
				$response_data[] = [
					'name'     => $row->option_name,
					'plugin'   => $this->map_plugin_to_options->get_plugin_name( $row->option_name ),
					'value'    => htmlentities( $row->option_value, ENT_QUOTES | ENT_SUBSTITUTE ),
					'size'     => number_format( strlen( $row->option_value ) / 1024, 2 ),
					'autoload' => 'yes',
					'row_id'   => 'option_' . $row->option_name,
				];
			}

			// Sorting, skip if "name" column is sorted in ascending order since that is the default.
			if ( ! ( 'name' === $order_column && SORT_ASC === $order_dir ) ) {
				$response_data = $this->sort_response_data_by_column( $response_data, $order_column, $order_dir );

				// Now we can slice after sort.
				$response_data = array_slice( $response_data, $offset, $limit );
			}
		}

		// Return response.
		return new \WP_REST_Response(
			[
				'draw'            => intval( $_GET['draw'] ?? 0 ),
				'recordsTotal'    => $total_unused,
				'recordsFiltered' => $total_unused,
				'data'            => $response_data,
			],
			200
		);
	}

	/**
	 * Get used, but not autoloaded options.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_used_not_autoloaded_options() {
		if ( ! isset( $_SERVER['HTTP_X_WP_NONCE'] ) || ! wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ), 'wp_rest' ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid nonce' ], 403 );
		}

		global $wpdb;

		// Load used options.
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

		// Get all autoloaded, non-transient option names.
		$autoloaded_option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			"
			SELECT option_name
			FROM {$wpdb->options}
			WHERE autoload IN ( '" . implode( "', '", esc_sql( \wp_autoload_values_to_autoload() ) ) . "' )
			AND option_name NOT LIKE '%_transient_%'
		"
		);

		$autoload_option_keys = array_fill_keys( $autoloaded_option_names, true );

		// Find used options not autoloaded.
		$non_autoloaded_used_keys = array_diff_key( $used_options, $autoload_option_keys );

		// Search.
		$search = isset( $_GET['search']['value'] ) ? trim( \sanitize_text_field( \wp_unslash( $_GET['search']['value'] ) ) ) : '';
		if ( '' !== $search ) {
			$non_autoloaded_used_keys = array_filter(
				$non_autoloaded_used_keys,
				function ( $option_name ) use ( $search ) {
					return false !== stripos( $option_name, $search );
				},
				ARRAY_FILTER_USE_KEY
			);
		}

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

		// Pagination.
		$offset             = isset( $_GET['start'] ) ? intval( $_GET['start'] ) : 0;
		$limit              = isset( $_GET['length'] ) ? intval( $_GET['length'] ) : 25;
		$paged_option_names = array_slice( array_keys( $non_autoloaded_used_keys ), $offset, $limit );

		$response_data = [];

		if ( ! empty( $paged_option_names ) ) {

			// Fetch values directly from DB without using get_option().
			$placeholders = implode( ',', array_fill( 0, count( $paged_option_names ), '%s' ) );
			$sql          = "
				SELECT option_name, option_value
				FROM {$wpdb->options}
				WHERE option_name IN ($placeholders)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$results = $wpdb->get_results( $wpdb->prepare( $sql, ...$paged_option_names ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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
		}

		// Return response.
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
	 * Get options that do not exist.
	 * Some of the options that are used but not auto-loaded, may not exist.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_options_that_do_not_exist() {
		if ( ! isset( $_SERVER['HTTP_X_WP_NONCE'] ) || ! wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ), 'wp_rest' ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid nonce' ], 403 );
		}

		global $wpdb;

		// Load used options.
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

		// Get autoloaded, non-transient option names.
		$autoloaded_option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"
			SELECT option_name
			FROM {$wpdb->options}
			WHERE autoload IN ('yes', 'on', 'true', '1')
			AND option_name NOT LIKE '%_transient_%'
		"
		);
		$autoload_option_keys    = array_fill_keys( $autoloaded_option_names, true );

		// Get used options that are not autoloaded.
		$non_autoloaded_keys = array_diff_key( $used_options, $autoload_option_keys );

		// Search.
		$search = isset( $_GET['search']['value'] ) ? trim( \sanitize_text_field( \wp_unslash( $_GET['search']['value'] ) ) ) : '';
		if ( '' !== $search ) {
			$non_autoloaded_keys = array_filter(
				$non_autoloaded_keys,
				function ( $option_name ) use ( $search ) {
					return stripos( $option_name, $search ) !== false;
				},
				ARRAY_FILTER_USE_KEY
			);
		}

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

		// Check which of them actually exist in the options table.
		$option_names = array_keys( $non_autoloaded_keys );
		$placeholders = implode( ',', array_fill( 0, count( $option_names ), '%s' ) );

		$existing_option_names = $wpdb->get_col(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...$option_names
			)
		);
		$existing_keys         = array_fill_keys( $existing_option_names, true );

		// Filter only those that do NOT exist.
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

		// Pagination.
		$offset        = isset( $_GET['start'] ) ? intval( $_GET['start'] ) : 0;
		$limit         = isset( $_GET['length'] ) ? intval( $_GET['length'] ) : 25;
		$response_data = array_slice( $options_that_do_not_exist, $offset, $limit );

		// Return response.
		return new \WP_REST_Response(
			[
				'draw'            => intval( $_GET['draw'] ?? 0 ),
				'recordsTotal'    => count( $options_that_do_not_exist ),
				'recordsFiltered' => count( $options_that_do_not_exist ),
				'data'            => $response_data,
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

	/**
	 * Sort response data array by given column and direction.
	 *
	 * @param array  $data        The data array to sort.
	 * @param string $column      The column key to sort by.
	 * @param int    $direction   SORT_ASC or SORT_DESC.
	 *
	 * @return array The sorted array.
	 */
	protected function sort_response_data_by_column( array $data, string $column, int $direction ): array {

		usort(
			$data,
			function ( $a, $b ) use ( $column, $direction ) {
				$val_a = $a[ $column ] ?? '';
				$val_b = $b[ $column ] ?? '';

				if ( is_numeric( $val_a ) && is_numeric( $val_b ) ) {
					return SORT_DESC === $direction ? $val_b <=> $val_a : $val_a <=> $val_b;
				}

				return SORT_DESC === $direction ? strnatcasecmp( $val_b, $val_a ) : strnatcasecmp( $val_a, $val_b );
			}
		);

		return $data;
	}
}
