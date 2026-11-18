<?php
/**
 * Base test case.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer\Tests;

use Progress_Planner\OptionOptimizer\Database;
use Yoast\WPTestUtils\WPIntegration\TestCase;

/**
 * Base test case for the plugin's integration tests.
 *
 * The plugin writes its tracking data inside its own transaction (START TRANSACTION / COMMIT),
 * which also commits the transaction the WordPress test suite wraps around every test. So the
 * rollback in tear_down() can't be relied on: this class cleans up explicitly instead. Tests
 * must only create options whose name starts with self::PREFIX.
 */
abstract class Test_Case extends TestCase {

	/**
	 * Prefix for every option a test creates.
	 *
	 * @var string
	 */
	const PREFIX = 'aaaoo_test_';

	/**
	 * Make sure the tracking table exists as a real (not temporary) table.
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();

		if ( ! Database::table_exists() ) {
			Database::create_table();
		}
		self::clean_up_database();
	}

	/**
	 * Remove everything a test may have committed.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_SERVER['HTTP_X_WP_NONCE'] );

		parent::tear_down();

		self::clean_up_database();
	}

	/**
	 * Empty the tracking table and delete the options the tests created.
	 *
	 * @return void
	 */
	protected static function clean_up_database() {
		global $wpdb;

		$table_name = Database::get_table_name();

		// phpcs:disable WordPress.DB -- Direct clean-up of test fixtures.
		$wpdb->query( "DELETE FROM {$table_name}" );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name = 'option_optimizer' OR option_name LIKE %s",
				$wpdb->esc_like( self::PREFIX ) . '%',
				'%' . $wpdb->esc_like( 'aaa_option_optimizer_migration_total' )
			)
		);
		$wpdb->query( 'COMMIT' );
		// phpcs:enable WordPress.DB

		\wp_cache_flush();
	}

	/**
	 * Read the raw autoload flag of an option, bypassing all caches and filters.
	 *
	 * @param string $option_name The option name.
	 *
	 * @return string|null The flag, or null if the option does not exist.
	 */
	protected function get_autoload_flag( $option_name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option_name ) );
	}

	/**
	 * Assert that an option is stored as autoloaded.
	 *
	 * @param string $option_name The option name.
	 *
	 * @return void
	 */
	protected function assertAutoloaded( $option_name ) {
		$this->assertContains( $this->get_autoload_flag( $option_name ), \wp_autoload_values_to_autoload(), "Option {$option_name} should be autoloaded." );
	}

	/**
	 * Assert that an option exists and is stored as not autoloaded.
	 *
	 * @param string $option_name The option name.
	 *
	 * @return void
	 */
	protected function assertNotAutoloaded( $option_name ) {
		$flag = $this->get_autoload_flag( $option_name );

		$this->assertNotNull( $flag, "Option {$option_name} should exist." );
		$this->assertNotContains( $flag, \wp_autoload_values_to_autoload(), "Option {$option_name} should not be autoloaded." );
	}
}
