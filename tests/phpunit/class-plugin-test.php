<?php
/**
 * Tests for option access tracking.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer\Tests;

use Progress_Planner\OptionOptimizer\Database;
use Progress_Planner\OptionOptimizer\Plugin;

/**
 * Tests the Plugin class.
 *
 * @covers \Progress_Planner\OptionOptimizer\Plugin
 */
class Plugin_Test extends Test_Case {

	/**
	 * The plugin instance WordPress loaded.
	 *
	 * @var Plugin
	 */
	private $original_instance;

	/**
	 * The instance under test. It is not hooked into WordPress.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Create a fresh, unhooked instance.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->original_instance = Plugin::$instance;
		$this->plugin            = new Plugin();
	}

	/**
	 * Restore the instance WordPress loaded.
	 *
	 * @return void
	 */
	public function tear_down() {
		Plugin::$instance = $this->original_instance;
		unset( $_GET['page'] );

		parent::tear_down();
	}

	/**
	 * The plugin hooks its tracking into WordPress when it loads.
	 *
	 * @return void
	 */
	public function test_plugin_registers_its_hooks_on_load() {
		$this->assertSame( PHP_INT_MAX, \has_filter( 'pre_option', [ $this->original_instance, 'monitor_option_accesses_pre_option' ] ) );
		$this->assertSame( 10, \has_action( 'shutdown', [ $this->original_instance, 'update_tracked_options' ] ) );
	}

	/**
	 * Option reads are counted and written to the table on shutdown.
	 *
	 * @return void
	 */
	public function test_option_accesses_are_counted_and_stored() {
		$this->plugin->monitor_option_accesses_pre_option( false, self::PREFIX . 'a' );
		$this->plugin->monitor_option_accesses_pre_option( false, self::PREFIX . 'a' );
		$this->plugin->monitor_option_accesses_pre_option( false, self::PREFIX . 'b' );

		$this->plugin->update_tracked_options();

		$tracked = Database::get_tracked_options();
		\ksort( $tracked );

		$this->assertSame(
			[
				self::PREFIX . 'a' => 2,
				self::PREFIX . 'b' => 1,
			],
			$tracked
		);
	}

	/**
	 * The pre_option filter never changes the value it is given.
	 *
	 * @return void
	 */
	public function test_pre_option_filter_passes_the_value_through() {
		$this->assertFalse( $this->plugin->monitor_option_accesses_pre_option( false, self::PREFIX . 'a' ) );
		$this->assertSame( 'short-circuit', $this->plugin->monitor_option_accesses_pre_option( 'short-circuit', self::PREFIX . 'b' ) );
	}

	/**
	 * A read another plugin short-circuits never reaches the database, so it is not tracked.
	 *
	 * @return void
	 */
	public function test_short_circuited_reads_are_not_tracked() {
		$this->plugin->monitor_option_accesses_pre_option( 'short-circuit', self::PREFIX . 'a' );
		$this->plugin->update_tracked_options();

		$this->assertSame( [], Database::get_tracked_options() );
	}

	/**
	 * Names that can't exist in wp_options are not tracked.
	 *
	 * @return void
	 */
	public function test_impossible_option_names_are_not_tracked() {
		$longest_valid = self::PREFIX . \str_repeat( 'a', 191 - \strlen( self::PREFIX ) );

		$this->plugin->monitor_option_accesses_pre_option( false, '' );
		$this->plugin->monitor_option_accesses_pre_option( false, $longest_valid . 'a' );
		$this->plugin->monitor_option_accesses_pre_option( false, $longest_valid );
		$this->plugin->update_tracked_options();

		$this->assertSame( [ $longest_valid => 1 ], Database::get_tracked_options() );
	}

	/**
	 * The trackable filter can exclude option names.
	 *
	 * @return void
	 */
	public function test_trackable_filter_excludes_option_names() {
		$excluded = self::PREFIX . 'excluded';

		\add_filter(
			'aaa_option_optimizer_is_trackable_option_name',
			static function ( $is_trackable, $option_name ) use ( $excluded ) {
				return $option_name === $excluded ? false : $is_trackable;
			},
			10,
			2
		);

		$this->plugin->monitor_option_accesses_pre_option( false, $excluded );
		$this->plugin->monitor_option_accesses_pre_option( false, self::PREFIX . 'kept' );
		$this->plugin->update_tracked_options();

		$this->assertSame( [ self::PREFIX . 'kept' => 1 ], Database::get_tracked_options() );
	}

	/**
	 * The legacy "all" hook tracking recognizes option hooks only.
	 *
	 * @return void
	 */
	public function test_legacy_tracking_recognizes_option_hooks() {
		$this->plugin->monitor_option_accesses_legacy( 'option_' . self::PREFIX . 'a' );
		$this->plugin->monitor_option_accesses_legacy( 'default_option_' . self::PREFIX . 'b' );
		$this->plugin->monitor_option_accesses_legacy( 'option_page_capability_' . self::PREFIX . 'c' );
		$this->plugin->monitor_option_accesses_legacy( 'init' );
		$this->plugin->update_tracked_options();

		$tracked = Database::get_tracked_options();
		\ksort( $tracked );

		$this->assertSame(
			[
				self::PREFIX . 'a' => 1,
				self::PREFIX . 'b' => 1,
			],
			$tracked
		);
	}

	/**
	 * Nothing is written while the plugin's own admin page is rendered.
	 *
	 * @return void
	 */
	public function test_nothing_is_stored_on_the_plugin_admin_page() {
		$_GET['page'] = 'aaa-option-optimizer';

		$this->plugin->monitor_option_accesses_pre_option( false, self::PREFIX . 'a' );
		$this->plugin->update_tracked_options();

		$this->assertSame( [], Database::get_tracked_options() );
	}

	/**
	 * A reset clears the table instead of writing this request's accesses.
	 *
	 * @return void
	 */
	public function test_reset_clears_the_tracked_options() {
		Database::batch_insert( [ self::PREFIX . 'old' => 3 ] );

		$this->plugin->monitor_option_accesses_pre_option( false, self::PREFIX . 'new' );
		$this->plugin->reset();
		$this->plugin->update_tracked_options();

		$this->assertSame( [], Database::get_tracked_options() );
	}

	/**
	 * A real get_option() call is tracked through the hooks registered on load.
	 *
	 * @return void
	 */
	public function test_get_option_is_tracked_end_to_end() {
		// Move the hooked callbacks to the instance under test, so only this test's reads are written.
		\remove_filter( 'pre_option', [ $this->original_instance, 'monitor_option_accesses_pre_option' ], PHP_INT_MAX );
		\add_filter( 'pre_option', [ $this->plugin, 'monitor_option_accesses_pre_option' ], PHP_INT_MAX, 2 );

		\get_option( self::PREFIX . 'missing' );
		\get_option( self::PREFIX . 'missing' );

		\remove_filter( 'pre_option', [ $this->plugin, 'monitor_option_accesses_pre_option' ], PHP_INT_MAX );
		$this->plugin->update_tracked_options();

		$tracked = Database::get_tracked_options();

		$this->assertSame( 2, $tracked[ self::PREFIX . 'missing' ] );
	}
}
