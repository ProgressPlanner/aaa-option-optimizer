<?php
/**
 * Remote mapping regressions.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer\Tests;

use Progress_Planner\OptionOptimizer\Admin_Page;
use Progress_Planner\OptionOptimizer\Known_Plugins;
use Progress_Planner\OptionOptimizer\Map_Plugin_To_Options;

/**
 * Exercises remote mapping validation and consent.
 *
 * @covers \Progress_Planner\OptionOptimizer\Known_Plugins
 */
class Known_Plugins_Test extends Test_Case {

	/**
	 * Clean up the persisted remote cache and schedule.
	 *
	 * @return void
	 */
	public function tear_down() {
		\delete_option( Known_Plugins::CACHE_KEY );
		\wp_clear_scheduled_hook( Known_Plugins::CRON_HOOK );
		parent::tear_down();
	}

	/**
	 * Enable remote requests for this fixture.
	 *
	 * @return void
	 */
	private function consent() {
		\update_option( Admin_Page::OPTION_NAME, [ 'settings' => [ 'remote_data_consent' => true ] ] );
	}

	/**
	 * A recognizable remote-only prefix.
	 *
	 * @return array
	 */
	private function mapping() {
		return [
			'test-plugin' => [
				'name'            => 'Test Plugin',
				'option_prefixes' => [ self::PREFIX ],
			],
		];
	}

	/**
	 * No request or remote mapping is used without consent, including after revocation.
	 *
	 * @return void
	 */
	public function test_consent_gates_requests_and_cached_mapping() {
		\add_filter(
			'pre_http_request',
			function () {
				$this->fail( 'Remote request without consent.' );
			}
		);
		\update_option( Known_Plugins::CACHE_KEY, $this->mapping(), false );
		$loader = new Known_Plugins();
		$this->assertFalse( $loader->refresh() );
		$this->assertArrayHasKey( 'akismet', $loader->get() );
		$this->consent();
		$this->assertSame( $this->mapping(), $loader->get() );
		\delete_option( Admin_Page::OPTION_NAME );
		$this->assertArrayHasKey( 'akismet', $loader->get() );
	}

	/**
	 * A valid refresh uses a site-free User-Agent and a non-autoloaded cache.
	 *
	 * @return void
	 */
	public function test_valid_refresh_preserves_privacy_and_maps_options() {
		$this->consent();
		\add_filter(
			'pre_http_request',
			function ( $response, $args, $url ) {
				$this->assertSame( Known_Plugins::REMOTE_URL, $url );
				$this->assertSame( 'POST', $args['method'] );
				$this->assertSame( 'AAA-Option-Optimizer', $args['user-agent'] );
				$this->assertTrue( $args['reject_unsafe_urls'] );
				$this->assertSame( 2 * MB_IN_BYTES, $args['limit_response_size'] );
				$this->assertSame( [ 'plugin_version', 'wp_version' ], array_keys( json_decode( $args['body'], true ) ) );
				return [
					'response' => [ 'code' => 200 ],
					'body'     => \wp_json_encode( $this->mapping() ),
				];
			},
			10,
			3
		);
		$this->assertTrue( ( new Known_Plugins() )->refresh() );
		$this->assertSame( $this->mapping(), \get_option( Known_Plugins::CACHE_KEY ) );
		$this->assertNotAutoloaded( Known_Plugins::CACHE_KEY );
		$mapper = new Map_Plugin_To_Options();
		$this->assertSame( 'Test Plugin', $mapper->get_plugin_name( self::PREFIX . 'setting' ) );
		$this->assertTrue( $mapper->is_known( self::PREFIX . 'setting' ) );
	}

	/**
	 * Invalid payloads cannot replace a good cache or reach strpos().
	 *
	 * @return void
	 */
	public function test_invalid_refresh_preserves_last_good_mapping() {
		$this->consent();
		\update_option( Known_Plugins::CACHE_KEY, $this->mapping(), false );
		$bodies = [
			'{invalid',
			'[]',
			'{"error":"unavailable"}',
			'{"broken":{"name":"Broken","option_prefixes":[[]]}}',
			'{"broken":{"name":[],"option_prefixes":["valid_"]}}',
			'{"broken":{"name":"Broken","option_prefixes":[""]}}',
			'{"broken":{"name":"Broken","option_prefixes":{"x":"valid_"}}}',
		];
		foreach ( $bodies as $body ) {
			$callback = static function () use ( $body ) {
				return [
					'response' => [ 'code' => 200 ],
					'body'     => $body,
				];
			};
			\add_filter( 'pre_http_request', $callback );
			$this->assertFalse( ( new Known_Plugins() )->refresh(), $body );
			$this->assertSame( $this->mapping(), \get_option( Known_Plugins::CACHE_KEY ) );
			\remove_filter( 'pre_http_request', $callback );
		}
	}

	/**
	 * Already stored malformed mappings fall back to the bundled data.
	 *
	 * @return void
	 */
	public function test_malformed_cache_falls_back_without_breaking_lookup() {
		$this->consent();
		\update_option(
			Known_Plugins::CACHE_KEY,
			[
				'broken' => [
					'name'            => 'Broken',
					'option_prefixes' => [ [] ],
				],
			],
			false
		);
		$this->assertSame( 'Akismet', ( new Map_Plugin_To_Options() )->get_plugin_name( 'akismet_setting' ) );
	}

	/**
	 * Transport failures and non-200 responses leave the bundled fallback usable.
	 *
	 * @return void
	 */
	public function test_failed_refresh_uses_bundled_mapping() {
		$this->consent();
		$responses = [
			new \WP_Error( 'offline' ),
			[
				'response' => [ 'code' => 404 ],
				'body'     => '{}',
			],
		];
		foreach ( $responses as $response ) {
			$callback = static function () use ( $response ) {
				return $response;
			};
			\add_filter( 'pre_http_request', $callback );
			$loader = new Known_Plugins();
			$this->assertFalse( $loader->refresh() );
			$this->assertArrayHasKey( 'akismet', $loader->get() );
			\remove_filter( 'pre_http_request', $callback );
		}
	}

	/**
	 * A URL filter can disable refresh without attempting an HTTP request.
	 *
	 * @return void
	 */
	public function test_url_filter_disables_requests() {
		$this->consent();
		\add_filter( 'aaa_option_optimizer_known_plugins_url', '__return_false' );
		\add_filter(
			'pre_http_request',
			function () {
				$this->fail( 'Disabled URL attempted an HTTP request.' );
			}
		);
		$this->assertFalse( ( new Known_Plugins() )->refresh() );
	}
	/**
	 * Consent changes require an administrator and update the daily schedule.
	 *
	 * @return void
	 */
	public function test_rest_consent_controls_refresh_schedule() {
		$request = new \WP_REST_Request( 'POST', '/aaa-option-optimizer/v1/set-consent' );
		$request->set_param( 'consent', true );
		\wp_set_current_user( 0 );
		$this->assertSame( 401, \rest_do_request( $request )->get_status() );
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 403, \rest_do_request( $request )->get_status() );
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertSame( 200, \rest_do_request( $request )->get_status() );
		$this->assertTrue( Known_Plugins::has_consent() );
		$this->assertSame( 'daily', \wp_get_schedule( Known_Plugins::CRON_HOOK ) );
		$request->set_param( 'consent', false );
		$this->assertSame( 200, \rest_do_request( $request )->get_status() );
		$this->assertFalse( Known_Plugins::has_consent() );
		$this->assertFalse( \wp_next_scheduled( Known_Plugins::CRON_HOOK ) );
	}
}
