<?php
/**
 * REST functionality for AAA Autoload Optimizer.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Core REST authentication checks cookie nonces; application passwords need no nonce.

/**
 * REST functionality of AAA Autoload Optimizer.
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
	 * Describe an option identifier without changing its contents.
	 *
	 * @return array<string, mixed>
	 */
	protected function get_option_name_arg() {
		return [
			'required'          => true,
			'type'              => 'string',
			'minLength'         => 1,
			'maxLength'         => 191,
			'validate_callback' => [ $this, 'validate_option_name' ],
		];
	}

	/**
	 * Reject identifiers that WordPress would trim or that cannot exist.
	 *
	 * @param mixed $value Submitted identifier.
	 * @return bool
	 */
	public function validate_option_name( $value ) {
		return is_string( $value ) && '' !== $value && trim( $value ) === $value
			&& false === strpos( $value, "\0" ) && mb_strlen( $value ) <= 191;
	}

	/**
	 * Describe a bounded list of exact option identifiers.
	 *
	 * @return array<string, mixed>
	 */
	protected function get_option_names_arg() {
		return [
			'required'          => true,
			'type'              => 'array',
			'minItems'          => 1,
			'maxItems'          => 500,
			'items'             => [
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 191,
			],
			'validate_callback' => function ( $value ) {
				if ( ! is_array( $value ) || ! $value || count( $value ) > 500 || array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
					return false;
				}
				foreach ( $value as $name ) {
					if ( ! $this->validate_option_name( $name ) ) {
						return false;
					}
				}
				return true;
			},
		];
	}

	/**
	 * Describe supported autoload states.
	 *
	 * @return array<string, mixed>
	 */
	protected function get_autoload_arg() {
		return [
			'required' => true,
			'type'     => 'string',
			'enum'     => [ 'yes', 'on', 'no', 'off', 'auto', 'auto-on', 'auto-off' ],
		];
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
					'option_name' => $this->get_option_name_arg(),
					'autoload'    => $this->get_autoload_arg(),
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
					'option_name' => $this->get_option_name_arg(),
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
					'option_name' => $this->get_option_name_arg(),
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

		\register_rest_route(
			'aaa-option-optimizer/v1',
			'/delete-options',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'delete_options' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => [
					'option_names' => $this->get_option_names_arg(),
				],
			]
		);

		\register_rest_route(
			'aaa-option-optimizer/v1',
			'/set-autoload-options',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'set_autoload_options' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => [
					'option_names' => $this->get_option_names_arg(),
					'autoload'     => $this->get_autoload_arg(),
				],
			]
		);

		\register_rest_route(
			'aaa-option-optimizer/v1',
			'/migrate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'migrate_chunk' ],
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
		if ( ! Plugin::get_instance()->reset() ) {
			return new \WP_Error( 'tracking_reset_failed', \__( 'Tracking could not be reset. Please try again.', 'aaa-option-optimizer' ), [ 'status' => 503 ] );
		}
		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Migrate a chunk of data from old format to custom table.
	 *
	 * @return \WP_REST_Response
	 */
	public function migrate_chunk() {
		$result = Database::migrate_chunk();
		return new \WP_REST_Response( $result, $result['success'] ? 200 : 503 );
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
				'name'      => $option->option_name,
				'protected' => Protected_Options::is_protected( $option->option_name ),
				'plugin'    => $this->get_plugin_name( $option->option_name ),
				'value'     => $option->option_value,
				'size'      => $this->get_length( $option->option_value ),
				'raw_size'  => strlen( $option->option_value ),
				'autoload'  => $option->autoload,
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
		if ( Database::is_tracking_limited() ) {
			return new \WP_REST_Response(
				[
					'draw'             => intval( $_GET['draw'] ?? 0 ),
					'recordsTotal'     => 0,
					'recordsFiltered'  => 0,
					'data'             => [],
					'sources'          => [],
					'tracking_limited' => true,
				],
				200
			);
		}

		global $wpdb;

		// Load used options from custom table.
		$used_options = Database::get_tracked_option_keys();

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

		// Collect all unique sources (plugin names) before any filtering.
		$all_sources = [];
		foreach ( array_keys( $unused_keys ) as $option_name ) {
			$plugin_name                 = $this->get_plugin_name( $option_name );
			$all_sources[ $plugin_name ] = true;
		}
		$all_sources = array_keys( $all_sources );
		sort( $all_sources );

		// Apply source filter to unused keys if specified.
		$filter_by_source = isset( $_GET['columns'][2]['search']['value'] ) ? trim( \sanitize_text_field( \wp_unslash( $_GET['columns'][2]['search']['value'] ) ) ) : '';
		if ( '' !== $filter_by_source ) {
			$unused_keys = $this->filter_by_source( $unused_keys, $filter_by_source );
		}

		$total_unused = count( $unused_keys );

		// Sort order.
		[ $order_column, $order_dir ] = $this->get_sort_params();

		// Pagination.
		[ $offset, $limit ] = $this->get_pagination_params();
		$paged_option_names = array_keys( $unused_keys );

		// Optimize by slicing early for default sort, since we can sort $autoloaded_option_names in advance.
		if ( 'name' === $order_column && SORT_ASC === $order_dir ) {
			$paged_option_names = array_slice( $paged_option_names, $offset, $limit );
		}

		$response_data = [];

		if ( ! empty( $paged_option_names ) ) {
			$results = $this->get_option_rows( $paged_option_names );

			// Format output.
			foreach ( $results as $row ) {
				$response_data[] = [
					'name'      => $row->option_name,
					'protected' => Protected_Options::is_protected( $row->option_name ),
					'plugin'    => $this->get_plugin_name( $row->option_name ),
					'value'     => $row->option_value,
					'size'      => $this->get_length( $row->option_value ),
					'raw_size'  => strlen( $row->option_value ),
					'autoload'  => 'yes',
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
				'sources'         => $all_sources,
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
		global $wpdb;

		// Load used options from custom table (with counts).
		$used_options = Database::get_tracked_options();

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
				WHERE autoload IN ( '" . implode( "', '", esc_sql( wp_autoload_values_to_autoload() ) ) . "' )
				AND option_name NOT LIKE '%_transient_%'
				ORDER BY option_name ASC
			"
		);
		$autoload_option_keys    = array_fill_keys( $autoloaded_option_names, true );

		// Find used options that are not autoloaded.
		$non_autoloaded_used_keys = array_diff_key( $used_options, $autoload_option_keys );

		// Collect all unique sources (plugin names) before any filtering.
		$all_sources = [];
		foreach ( array_keys( $non_autoloaded_used_keys ) as $option_name ) {
			$plugin_name                 = $this->get_plugin_name( $option_name );
			$all_sources[ $plugin_name ] = true;
		}
		$all_sources = array_keys( $all_sources );
		sort( $all_sources );

		// Filter by source (plugin).
		$filter_by_source = isset( $_GET['columns'][2]['search']['value'] ) ? trim( \sanitize_text_field( \wp_unslash( $_GET['columns'][2]['search']['value'] ) ) ) : '';
		if ( '' !== $filter_by_source ) {
			$non_autoloaded_used_keys = $this->filter_by_source( $non_autoloaded_used_keys, $filter_by_source );
		}

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
					'sources'         => $all_sources,
				],
				200
			);
		}

		// Sort order.
		[ $order_column, $order_dir ] = $this->get_sort_params();

		// Pagination.
		[ $offset, $limit ] = $this->get_pagination_params();

		// We can't slice early here, because we can't sort $used_options in advance.
		$paged_option_names = array_keys( $non_autoloaded_used_keys );

		$response_data = [];

		$results = $this->get_option_rows( $paged_option_names );

		foreach ( $results as $row ) {
			$response_data[] = [
				'name'      => $row->option_name,
				'protected' => Protected_Options::is_protected( $row->option_name ),
				'plugin'    => $this->get_plugin_name( $row->option_name ),
				'value'     => $row->option_value,
				'size'      => $this->get_length( $row->option_value ),
				'raw_size'  => strlen( $row->option_value ),
				'autoload'  => 'no',
				'count'     => $used_options[ $row->option_name ] ?? 0,
			];
		}

		$total_filtered = count( $response_data );

		// Sort and slice after.
		$response_data = $this->sort_response_data_by_column( $response_data, $order_column, $order_dir );
		$response_data = array_slice( $response_data, $offset, $limit );

		// Return response.
		return new \WP_REST_Response(
			[
				'draw'            => intval( $_GET['draw'] ?? 0 ),
				'recordsTotal'    => $total_filtered,
				'recordsFiltered' => $total_filtered,
				'data'            => $response_data,
				'sources'         => $all_sources,
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
		global $wpdb;

		// Load used options from custom table (with counts).
		$used_options = Database::get_tracked_options();

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

		if ( empty( $non_autoloaded_keys ) ) {
			return new \WP_REST_Response(
				[
					'draw'            => intval( $_GET['draw'] ?? 0 ),
					'recordsTotal'    => 0,
					'recordsFiltered' => 0,
					'data'            => [],
					'sources'         => [],
				],
				200
			);
		}

		// Check which of them actually exist in the options table.
		$existing_option_names = array_column( $this->get_option_rows( array_keys( $non_autoloaded_keys ), false ), 'option_name' );
		$existing_keys         = array_fill_keys( $existing_option_names, true );

		// Build array of non-existing options (before any filtering).
		$non_existing_options = [];
		foreach ( $non_autoloaded_keys as $option => $count ) {
			if ( ! isset( $existing_keys[ $option ] ) ) {
				$non_existing_options[ $option ] = [
					'name'        => (string) $option,
					'protected'   => Protected_Options::is_protected( (string) $option ),
					'plugin'      => $this->get_plugin_name( $option ),
					'count'       => $count,
					'option_name' => (string) $option,
				];
			}
		}

		// Collect all unique sources (plugin names) before any filtering.
		$all_sources = [];
		foreach ( $non_existing_options as $row ) {
			$all_sources[ $row['plugin'] ] = true;
		}
		$all_sources = array_keys( $all_sources );
		sort( $all_sources );

		// Filter by source (plugin).
		$filter_by_source = isset( $_GET['columns'][1]['search']['value'] ) ? trim( \sanitize_text_field( \wp_unslash( $_GET['columns'][1]['search']['value'] ) ) ) : '';
		if ( '' !== $filter_by_source ) {
			$non_existing_options = array_filter(
				$non_existing_options,
				function ( $row ) use ( $filter_by_source ) {
					return false !== stripos( $row['plugin'], $filter_by_source );
				}
			);
		}

		// Search.
		$search = isset( $_GET['search']['value'] ) ? trim( \sanitize_text_field( \wp_unslash( $_GET['search']['value'] ) ) ) : '';
		if ( '' !== $search ) {
			$non_existing_options = array_filter(
				$non_existing_options,
				function ( $row ) use ( $search ) {
					return stripos( $row['name'], $search ) !== false;
				}
			);
		}

		$response_data  = array_values( $non_existing_options );
		$total_filtered = count( $response_data );

		// Pagination.
		[ $offset, $limit ] = $this->get_pagination_params();

		// Sort order.
		[ $order_column, $order_dir ] = $this->get_sort_params();

		// Sort and slice after.
		$response_data = $this->sort_response_data_by_column( $response_data, $order_column, $order_dir );
		$response_data = array_slice( $response_data, $offset, $limit );

		// Return response.
		return new \WP_REST_Response(
			[
				'draw'            => intval( $_GET['draw'] ?? 0 ),
				'recordsTotal'    => $total_filtered,
				'recordsFiltered' => $total_filtered,
				'data'            => $response_data,
				'sources'         => $all_sources,
			],
			200
		);
	}

	/**
	 * Fetch option rows in bounded queries.
	 *
	 * @param array<int|string> $names Option identifiers.
	 * @param bool              $include_values Whether values are needed.
	 * @return object[]
	 */
	private function get_option_rows( array $names, $include_values = true ) {
		global $wpdb;
		$rows    = [];
		$columns = $include_values ? 'option_name, option_value' : 'option_name';
		foreach ( array_chunk( $names, 500 ) as $chunk ) {
			$slots = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = array_merge( $rows, $wpdb->get_results( $wpdb->prepare( "SELECT $columns FROM {$wpdb->options} WHERE option_name IN ($slots) ORDER BY option_name", $chunk ) ) );
		}
		return $rows;
	}

	/**
	 * Update autoload status of an option.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The REST request object.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function update_option_autoload( $request ) {
		return $this->update_autoload( [ $request['option_name'] ], $request['autoload'] );
	}

	/**
	 * Delete an option.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The REST request object.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_option( $request ) {
		$option_name = $request['option_name'];
		if ( Protected_Options::is_mutation_protected( $option_name ) ) {
			return new \WP_Error( 'option_protected', 'Option is protected', [ 'status' => 403 ] );
		}

		if ( delete_option( $option_name ) ) {
			return new \WP_REST_Response( [ 'success' => true ], 200 );
		}
		return new \WP_Error( 'option_not_found_or_deleted', 'Option does not exist or could not be deleted', [ 'status' => 404 ] );
	}

	/**
	 * Delete multiple options.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The REST request object.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function delete_options( $request ) {
		$option_names = $request['option_names'];
		// Preflight the entire batch before deleting anything.
		foreach ( $option_names as $option_name ) {
			if ( Protected_Options::is_mutation_protected( $option_name ) ) {
				return new \WP_Error( 'option_protected', 'Option is protected', [ 'status' => 403 ] );
			}
		}
		$failed = [];
		foreach ( array_unique( $option_names ) as $option_name ) {
			if ( ! delete_option( $option_name ) ) {
				$failed[] = $option_name;
			}
		}
		if ( $failed ) {
			return new \WP_Error(
				'delete_failed',
				'Some options could not be deleted',
				[
					'status'       => 400,
					'option_names' => $failed,
				]
			);
		}
		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Set autoload status of multiple options.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request The REST request object.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function set_autoload_options( $request ) {
		return $this->update_autoload( $request['option_names'], $request['autoload'] );
	}

	/**
	 * Update autoload flags without reading or rewriting option values.
	 *
	 * @param string[] $option_names Exact option identifiers.
	 * @param string   $state Requested autoload state.
	 * @return \WP_Error|\WP_REST_Response
	 */
	private function update_autoload( array $option_names, string $state ) {
		foreach ( $option_names as $option_name ) {
			if ( Protected_Options::is_mutation_protected( $option_name ) ) {
				return new \WP_Error( 'option_protected', 'Option is protected', [ 'status' => 403 ] );
			}
		}

		$autoload = in_array( $state, \wp_autoload_values_to_autoload(), true );
		$results  = \wp_set_option_autoload_values( array_fill_keys( $option_names, $autoload ) );
		$failed   = [];
		foreach ( $option_names as $option_name ) {
			// Core returns false for unchanged rows too. Check the raw state, never the filtered value.
			if ( empty( $results[ $option_name ] ) && $this->get_raw_autoload( $option_name ) !== ( $autoload ? 'on' : 'off' ) ) {
				$failed[] = $option_name;
			}
		}
		if ( $failed ) {
			return new \WP_Error(
				'update_failed',
				'Some options could not be updated',
				[
					'status'       => 400,
					'option_names' => $failed,
				]
			);
		}
		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Read only the raw autoload state, without option filters.
	 *
	 * @param string $option_name Option name.
	 * @return string|null
	 */
	private function get_raw_autoload( $option_name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option_name ) );
	}

	/**
	 * Create an option with a false value.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request  The REST request object.
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function create_option_false( $request ) {
		$option_name = $request['option_name'];
		if ( Protected_Options::is_mutation_protected( $option_name ) ) {
			return new \WP_Error( 'option_protected', 'Option is protected', [ 'status' => 403 ] );
		}

		if ( add_option( $option_name, false, '', false ) ) {
			return new \WP_REST_Response( [ 'success' => true ], 200 );
		}
		return new \WP_Error( 'option_not_created', 'Option could not be created', [ 'status' => 400 ] );
	}

	/**
	 * Sort response data array by given column and direction.
	 *
	 * @param array<int, array<string, mixed>> $data        The data array to sort.
	 * @param string                           $column      The column key to sort by.
	 * @param int                              $direction   SORT_ASC or SORT_DESC.
	 *
	 * @return array<int, array<string, mixed>> The sorted array.
	 */
	protected function sort_response_data_by_column( array $data, string $column, int $direction ): array {

		usort(
			$data,
			function ( $a, $b ) use ( $column, $direction ) {
				$val_a = $a[ "raw_$column" ] ?? $a[ $column ] ?? '';
				$val_b = $b[ "raw_$column" ] ?? $b[ $column ] ?? '';

				if ( is_numeric( $val_a ) && is_numeric( $val_b ) ) {
					$val_a = floatval( $val_a );
					$val_b = floatval( $val_b );

					return $direction === SORT_DESC ? $val_b <=> $val_a : $val_a <=> $val_b;
				}

				return $direction === SORT_DESC
					? strnatcasecmp( $val_b, $val_a )
					: strnatcasecmp( $val_a, $val_b );
			}
		);

		return $data;
	}

	/**
	 * Get pagination parameters from $_GET.
	 *
	 * @return array{0: int, 1: int} {
	 *     @type int $offset Pagination offset.
	 *     @type int $limit  Number of items per page.
	 * }
	 */
	protected function get_pagination_params(): array {
		$offset = isset( $_GET['start'] ) ? intval( $_GET['start'] ) : 0;
		$limit  = isset( $_GET['length'] ) ? intval( $_GET['length'] ) : 25;

		return [ max( 0, $offset ), max( 1, min( 500, $limit ) ) ];
	}

	/**
	 * Get sort column and direction from DataTables-style request.
	 *
	 * @return array{0: string, 1: int} [ string $column, int $direction (SORT_ASC|SORT_DESC) ]
	 */
	public function get_sort_params(): array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Type and allowlist checked before use.
		$index = $_GET['order'][0]['column'] ?? null;
		if ( ! is_scalar( $index ) || ! ctype_digit( (string) $index ) ) {
			return [ 'name', SORT_ASC ];
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Type and allowlist checked before use.
		$column = $_GET['columns'][ (int) $index ]['data'] ?? 'name';
		if ( ! is_string( $column ) || ! in_array( $column, [ 'name', 'plugin', 'size', 'count', 'autoload' ], true ) ) {
			$column = 'name';
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Type and allowlist checked before use.
		$direction = $_GET['order'][0]['dir'] ?? 'asc';
		return [ $column, 'desc' === $direction ? SORT_DESC : SORT_ASC ];
	}

	/**
	 * Get the length of a value.
	 *
	 * @param mixed $value The input value.
	 *
	 * @return string
	 */
	private function get_length( $value ) {
		if ( empty( $value ) ) {
			return '0.00';
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- intended here.
			$length = strlen( serialize( $value ) );
		} elseif ( is_string( $value ) || is_numeric( $value ) ) {
			$length = strlen( strval( $value ) );
		}
		if ( ! isset( $length ) ) {
			return '0.00';
		}
		return number_format( ( $length / 1024 ), 2 );
	}

	/**
	 * Find plugin in known plugin prefixes list.
	 *
	 * @param string $option The option name.
	 *
	 * @return string
	 */
	private function get_plugin_name( $option ) {
		return $this->map_plugin_to_options->get_plugin_name( $option );
	}

	/**
	 * Filter options array by source (plugin) name.
	 *
	 * @param array<string, mixed> $options_array The options array to filter.
	 * @param string               $filter_term   The filter term to match against plugin names.
	 *
	 * @return array<string, mixed> The filtered options array.
	 */
	private function filter_by_source( array $options_array, string $filter_term ): array {

		if ( ! $filter_term ) {
			return $options_array;
		}

		return array_filter(
			$options_array,
			function ( $option_name ) use ( $filter_term ) {
				$plugin_name = $this->get_plugin_name( $option_name );
				return false !== stripos( $plugin_name, $filter_term );
			},
			ARRAY_FILTER_USE_KEY
		);
	}
}
