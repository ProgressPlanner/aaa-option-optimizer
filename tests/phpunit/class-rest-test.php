<?php
/**
 * Tests for the REST endpoints that change options.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer\Tests;

use Progress_Planner\OptionOptimizer\Database;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Tests the REST class.
 *
 * @covers \Progress_Planner\OptionOptimizer\REST
 */
class REST_Test extends Test_Case {

	/**
	 * Set up a REST server with the plugin's routes.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \Spy_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals -- Test fixture.
		\do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- Core hook.
	}

	/**
	 * Reset the REST server.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals -- Test fixture.

		parent::tear_down();
	}

	/**
	 * Log in as a user with the given role, with a valid REST nonce.
	 *
	 * @param string $role The role.
	 *
	 * @return void
	 */
	private function log_in_as( $role ) {
		$user_id = self::factory()->user->create( [ 'role' => $role ] );
		if ( 'administrator' === $role && \is_multisite() ) {
			\grant_super_admin( $user_id );
		}
		\wp_set_current_user( $user_id );

		$_SERVER['HTTP_X_WP_NONCE'] = \wp_create_nonce( 'wp_rest' );
	}

	/**
	 * Dispatch a request to one of the plugin's routes.
	 *
	 * @param string               $route  Route suffix.
	 * @param array<string, mixed> $params Request parameters.
	 * @param string               $method HTTP method.
	 *
	 * @return WP_REST_Response
	 */
	private function request( $route, $params = [], $method = 'POST' ) {
		$request = new WP_REST_Request( $method, '/aaa-option-optimizer/v1/' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return \rest_do_request( $request );
	}

	/**
	 * Routes that change options, with parameters that would succeed for an administrator.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public function data_write_routes() {
		$name = self::PREFIX . 'guarded';

		return [
			'update-autoload'      => [
				'update-autoload',
				[
					'option_name' => $name,
					'autoload'    => 'yes',
				],
			],
			'set-autoload-options' => [
				'set-autoload-options',
				[
					'option_names' => [ $name ],
					'autoload'     => 'yes',
				],
			],
			'delete-option'        => [ 'delete-option', [ 'option_name' => $name ] ],
			'delete-options'       => [ 'delete-options', [ 'option_names' => [ $name ] ] ],
			'create-option-false'  => [ 'create-option-false', [ 'option_name' => $name . '_new' ] ],
			'reset'                => [ 'reset', [] ],
		];
	}

	/**
	 * Visitors and users without manage_options can't change anything.
	 *
	 * @dataProvider data_write_routes
	 *
	 * @param string               $route  Route suffix.
	 * @param array<string, mixed> $params Request parameters.
	 *
	 * @return void
	 */
	public function test_write_routes_require_manage_options( $route, $params ) {
		$name = self::PREFIX . 'guarded';
		\add_option( $name, 'value', '', false );

		$this->assertSame( 401, $this->request( $route, $params )->get_status(), 'Logged out.' );

		$this->log_in_as( 'editor' );
		$this->assertSame( 403, $this->request( $route, $params )->get_status(), 'Editor.' );

		$this->assertSame( 'value', \get_option( $name ) );
		$this->assertNotAutoloaded( $name );
		$this->assertNull( $this->get_autoload_flag( $name . '_new' ) );
	}

	/**
	 * Autoload values and whether they should result in an autoloaded option.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function data_autoload_values() {
		return [
			'yes'      => [ 'yes', true ],
			'on'       => [ 'on', true ],
			'auto'     => [ 'auto', true ],
			'auto-on'  => [ 'auto-on', true ],
			'no'       => [ 'no', false ],
			'off'      => [ 'off', false ],
			'auto-off' => [ 'auto-off', false ],
		];
	}

	/**
	 * The autoload flag is changed and the value is left alone.
	 *
	 * @dataProvider data_autoload_values
	 *
	 * @param string $autoload        The requested autoload value.
	 * @param bool   $expect_autoload Whether the option should be autoloaded afterwards.
	 *
	 * @return void
	 */
	public function test_update_autoload_changes_the_flag_and_keeps_the_value( $autoload, $expect_autoload ) {
		$this->log_in_as( 'administrator' );

		$name  = self::PREFIX . 'flip';
		$value = [
			'nested' => [ 'a' => 1 ],
			'text'   => "it's \"quoted\" \\ and slashed",
		];
		// Start from the opposite state.
		\add_option( $name, $value, '', ! $expect_autoload );

		$response = $this->request(
			'update-autoload',
			[
				'option_name' => $name,
				'autoload'    => $autoload,
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'success' => true ], $response->get_data() );

		if ( $expect_autoload ) {
			$this->assertAutoloaded( $name );
		} else {
			$this->assertNotAutoloaded( $name );
		}

		\wp_cache_flush();
		$this->assertSame( $value, \get_option( $name ) );
	}

	/**
	 * An unknown autoload value is rejected and changes nothing.
	 *
	 * @return void
	 */
	public function test_update_autoload_rejects_an_invalid_value() {
		$this->log_in_as( 'administrator' );

		$name = self::PREFIX . 'invalid';
		\add_option( $name, 'value', '', true );

		$response = $this->request(
			'update-autoload',
			[
				'option_name' => $name,
				'autoload'    => 'maybe',
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_autoload_value', $response->get_data()['code'] );
		$this->assertAutoloaded( $name );
		$this->assertSame( 'value', \get_option( $name ) );
	}

	/**
	 * A missing option is reported, not created.
	 *
	 * @return void
	 */
	public function test_update_autoload_reports_a_missing_option() {
		$this->log_in_as( 'administrator' );

		$name = self::PREFIX . 'missing';

		$response = $this->request(
			'update-autoload',
			[
				'option_name' => $name,
				'autoload'    => 'no',
			]
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertNull( $this->get_autoload_flag( $name ) );
	}

	/**
	 * The required parameters are enforced.
	 *
	 * @return void
	 */
	public function test_update_autoload_requires_its_parameters() {
		$this->log_in_as( 'administrator' );

		$this->assertSame( 400, $this->request( 'update-autoload', [ 'autoload' => 'no' ] )->get_status() );
		$this->assertSame( 400, $this->request( 'update-autoload', [ 'option_name' => self::PREFIX . 'a' ] )->get_status() );
	}

	/**
	 * The bulk route changes every existing option and skips missing ones.
	 *
	 * @return void
	 */
	public function test_set_autoload_options_changes_existing_options_only() {
		$this->log_in_as( 'administrator' );

		$first   = self::PREFIX . 'bulk_1';
		$second  = self::PREFIX . 'bulk_2';
		$missing = self::PREFIX . 'bulk_missing';
		$other   = self::PREFIX . 'bulk_untouched';

		\add_option( $first, 'one', '', true );
		\add_option( $second, [ 'two' ], '', true );
		\add_option( $other, 'other', '', true );

		$response = $this->request(
			'set-autoload-options',
			[
				'option_names' => [ $first, $missing, $second ],
				'autoload'     => 'no',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotAutoloaded( $first );
		$this->assertNotAutoloaded( $second );
		$this->assertNull( $this->get_autoload_flag( $missing ) );
		$this->assertAutoloaded( $other );

		\wp_cache_flush();
		$this->assertSame( 'one', \get_option( $first ) );
		$this->assertSame( [ 'two' ], \get_option( $second ) );
	}

	/**
	 * The bulk routes need a REST nonce on top of the capability.
	 *
	 * @return void
	 */
	public function test_bulk_routes_require_a_nonce() {
		$this->log_in_as( 'administrator' );
		unset( $_SERVER['HTTP_X_WP_NONCE'] );

		$name = self::PREFIX . 'nonce';
		\add_option( $name, 'value', '', true );

		$set = $this->request(
			'set-autoload-options',
			[
				'option_names' => [ $name ],
				'autoload'     => 'no',
			]
		);
		$this->assertSame( 403, $set->get_status() );

		$delete = $this->request( 'delete-options', [ 'option_names' => [ $name ] ] );
		$this->assertSame( 403, $delete->get_status() );

		$this->assertAutoloaded( $name );
		$this->assertSame( 'value', \get_option( $name ) );
	}

	/**
	 * The bulk route rejects an unknown autoload value.
	 *
	 * @return void
	 */
	public function test_set_autoload_options_rejects_an_invalid_value() {
		$this->log_in_as( 'administrator' );

		$name = self::PREFIX . 'bulk_invalid';
		\add_option( $name, 'value', '', true );

		$response = $this->request(
			'set-autoload-options',
			[
				'option_names' => [ $name ],
				'autoload'     => 'maybe',
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertAutoloaded( $name );
	}

	/**
	 * Deleting removes exactly the requested option.
	 *
	 * @return void
	 */
	public function test_delete_option() {
		$this->log_in_as( 'administrator' );

		$name  = self::PREFIX . 'delete';
		$other = self::PREFIX . 'delete_other';
		\add_option( $name, 'value' );
		\add_option( $other, 'value' );

		$this->assertSame( 200, $this->request( 'delete-option', [ 'option_name' => $name ] )->get_status() );
		$this->assertNull( $this->get_autoload_flag( $name ) );
		$this->assertNotNull( $this->get_autoload_flag( $other ) );

		$this->assertSame( 404, $this->request( 'delete-option', [ 'option_name' => $name ] )->get_status() );
	}

	/**
	 * Bulk deleting removes exactly the requested options.
	 *
	 * @return void
	 */
	public function test_delete_options() {
		$this->log_in_as( 'administrator' );

		$first  = self::PREFIX . 'delete_1';
		$second = self::PREFIX . 'delete_2';
		$other  = self::PREFIX . 'delete_other';
		\add_option( $first, 'value' );
		\add_option( $second, 'value' );
		\add_option( $other, 'value' );

		$response = $this->request( 'delete-options', [ 'option_names' => [ $first, $second ] ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $this->get_autoload_flag( $first ) );
		$this->assertNull( $this->get_autoload_flag( $second ) );
		$this->assertNotNull( $this->get_autoload_flag( $other ) );
	}

	/**
	 * A requested-but-missing option can be created as a non-autoloaded `false`.
	 *
	 * @return void
	 */
	public function test_create_option_false() {
		$this->log_in_as( 'administrator' );

		$name = self::PREFIX . 'created';

		$this->assertSame( 200, $this->request( 'create-option-false', [ 'option_name' => $name ] )->get_status() );
		$this->assertNotAutoloaded( $name );
		$this->assertEmpty( \get_option( $name, 'default' ) );

		// It can't overwrite an option that already exists.
		$this->assertSame( 400, $this->request( 'create-option-false', [ 'option_name' => $name ] )->get_status() );
	}

	/**
	 * Autoloaded options that were never read are listed as unused; tracked ones are not.
	 *
	 * @return void
	 */
	public function test_unused_options_lists_untracked_autoloaded_options() {
		$used           = self::PREFIX . 'used';
		$unused         = self::PREFIX . 'unused';
		$not_autoloaded = self::PREFIX . 'not_autoloaded';

		// Write the tracking data first: it commits the test transaction, so the user and
		// options created below must come after it to be rolled back.
		Database::batch_insert( [ $used => 3 ] );

		$this->log_in_as( 'administrator' );

		\add_option( $used, 'value', '', true );
		\add_option( $unused, 'value', '', true );
		\add_option( $not_autoloaded, 'value', '', false );

		$_GET['search'] = [ 'value' => self::PREFIX ];
		$response       = $this->request( 'unused-options', [], 'GET' );
		unset( $_GET['search'] );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( 1, $data['recordsTotal'] );
		$this->assertSame( [ $unused ], \wp_list_pluck( $data['data'], 'name' ) );
	}

	/**
	 * Tracked options that are not autoloaded are listed as candidates for autoloading.
	 *
	 * @return void
	 */
	public function test_used_not_autoloaded_options_lists_tracked_options() {
		$autoloaded     = self::PREFIX . 'autoloaded';
		$not_autoloaded = self::PREFIX . 'not_autoloaded';

		// Commits the test transaction, so it has to run before the user and options are created.
		Database::batch_insert(
			[
				$autoloaded     => 2,
				$not_autoloaded => 5,
			]
		);

		$this->log_in_as( 'administrator' );

		\add_option( $autoloaded, 'value', '', true );
		\add_option( $not_autoloaded, 'value', '', false );

		$_GET['search'] = [ 'value' => self::PREFIX ];
		$response       = $this->request( 'used-not-autoloaded-options', [], 'GET' );
		unset( $_GET['search'] );

		$this->assertSame( 200, $response->get_status() );

		$rows = $response->get_data()['data'];

		$this->assertSame( [ $not_autoloaded ], \wp_list_pluck( $rows, 'name' ) );
		$this->assertSame( 5, (int) $rows[0]['count'] );
	}
}
