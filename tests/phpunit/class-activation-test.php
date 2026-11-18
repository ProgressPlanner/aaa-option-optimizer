<?php
/**
 * Tests for the activation and deactivation routines.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer\Tests;

use Progress_Planner\OptionOptimizer\Database;

/**
 * Tests the functions in the main plugin file.
 *
 * @covers ::aaa_option_optimizer_activation
 * @covers ::aaa_option_optimizer_deactivation
 */
class Activation_Test extends Test_Case {

	/**
	 * Activation records the starting point in a non-autoloaded option.
	 *
	 * @return void
	 */
	public function test_activation_stores_the_starting_point() {
		global $wpdb;

		// phpcs:ignore WordPress.DB
		$autoloaded = (int) $wpdb->get_var( "SELECT count(*) FROM {$wpdb->options} WHERE autoload IN ( '" . \implode( "', '", \esc_sql( \wp_autoload_values_to_autoload() ) ) . "' )" );

		\aaa_option_optimizer_activation();

		$option = \get_option( 'option_optimizer' );

		$this->assertTrue( Database::table_exists() );
		$this->assertSame( $autoloaded, (int) $option['starting_point_num'] );
		$this->assertGreaterThan( 0, $option['starting_point_kb'] );
		$this->assertNotEmpty( $option['starting_point_date'] );
		$this->assertSame( [ 'option_tracking' => 'pre_option' ], $option['settings'] );
		$this->assertNotAutoloaded( 'option_optimizer' );
	}

	/**
	 * Activating again keeps the existing starting point.
	 *
	 * @return void
	 */
	public function test_activation_preserves_an_existing_starting_point() {
		$existing = [
			'starting_point_kb'   => 1.5,
			'starting_point_num'  => 7,
			'starting_point_date' => '2024-01-01 00:00:00',
			'settings'            => [ 'option_tracking' => 'legacy' ],
		];
		\update_option( 'option_optimizer', $existing, false );

		\aaa_option_optimizer_activation();

		$this->assertSame( $existing, \get_option( 'option_optimizer' ) );
	}

	/**
	 * Deactivation keeps the plugin option, but stops autoloading it.
	 *
	 * @return void
	 */
	public function test_deactivation_stops_autoloading_the_plugin_option() {
		$value = [ 'starting_point_num' => 7 ];
		\update_option( 'option_optimizer', $value, true );
		$this->assertAutoloaded( 'option_optimizer' );

		\aaa_option_optimizer_deactivation();

		$this->assertNotAutoloaded( 'option_optimizer' );

		\wp_cache_flush();
		$this->assertSame( $value, \get_option( 'option_optimizer' ) );
	}

	/**
	 * Deactivation does not create the plugin option when it is missing.
	 *
	 * @return void
	 */
	public function test_deactivation_does_not_create_the_plugin_option() {
		\aaa_option_optimizer_deactivation();

		$this->assertNull( $this->get_autoload_flag( 'option_optimizer' ) );
	}
}
