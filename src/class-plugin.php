<?php
/**
 * Plugin functionality for AAA Option Optimizer.
 *
 * @package Emilia\OptionOptimizer
 */

namespace Emilia\OptionOptimizer;

/**
 * Core functionality of AAA Option Optimizer.
 */
class Plugin {
	/**
	 * The instance of the plugin.
	 *
	 * @var Plugin
	 */
	public static $instance;

	/**
	 * Holds the names of the options accessed during the request.
	 *
	 * @var string[]
	 */
	protected $accessed_options = [];

	/**
	 * Whether the plugin should reset the option_optimizer data.
	 *
	 * @var boolean
	 */
	protected $should_reset = false;

	/**
	 * Initializes the plugin.
	 *
	 * @return void
	 */
	public function __construct() {
		self::$instance = $this;
	}

	/**
	 * Gets the instance of the plugin.
	 *
	 * @return Plugin
	 */
	public static function get_instance() {
		// @phpstan-ignore-next-line -- The 'instance' property is set in the constructor.
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		$this->accessed_options = \get_option( 'option_optimizer', [ 'used_options' => [] ] )['used_options'];

		if ( Settings_Page::get_option_tracking() === 'pre_option' ) {
			\add_filter( 'pre_option', [ $this, 'monitor_option_accesses_pre_option' ], PHP_INT_MAX, 2 );
		} else {
			// Hook into all actions and filters to monitor option accesses.
			// @phpstan-ignore-next-line -- The 'all' hook does not need a return.
			\add_filter( 'all', [ $this, 'monitor_option_accesses_legacy' ] );
		}

		// Use the shutdown action to update the option with tracked data.
		\add_action( 'shutdown', [ $this, 'update_tracked_options' ] );

		// Register the REST routes.
		$rest = new REST();
		$rest->register_hooks();

		if ( \is_admin() ) {
			// Register the admin page.
			$admin_page = new Admin_Page();
			$admin_page->register_hooks();

			// Register the settings page.
			Settings_Page::register_hooks();
		}
	}

	/**
	 * Sets the 'should_reset' property.
	 *
	 * @param boolean $should_reset Whether the plugin should reset the option_optimizer data.
	 *
	 * @return void
	 */
	public function reset( $should_reset = true ) {
		$this->should_reset = $should_reset;
	}

	/**
	 * Monitor all actions and filters for option accesses.
	 *
	 * @param string $tag The current action or filter tag being executed.
	 *
	 * @return void
	 */
	public function monitor_option_accesses_legacy( $tag ) {
		// Check if the tag is related to an option access.
		if ( str_starts_with( $tag, 'option_' ) || str_starts_with( $tag, 'default_option_' ) ) {
			$option_name = preg_replace( '#^(default_)?option_#', '', $tag );
			$this->add_option_usage( $option_name );
		}
	}

	/**
	 * Add an option to the list of used options if it's not already there.
	 *
	 * @param mixed  $pre The value to return instead of the option value.
	 * @param string $option_name Name of the option being accessed.
	 *
	 * @return mixed
	 */
	public function monitor_option_accesses_pre_option( $pre, $option_name ) {

		// If the $pre is false the get_option() will not be short-circuited.
		if ( ! defined( 'WP_SETUP_CONFIG' ) && false === $pre ) {
			$this->add_option_usage( $option_name );
		}

		return $pre;
	}

	/**
	 * Add an option to the list of used options if it's not already there.
	 *
	 * @param string $option_name Name of the option being accessed.
	 *
	 * @return void
	 */
	protected function add_option_usage( $option_name ) {
		if ( ! array_key_exists( $option_name, $this->accessed_options ) ) {
			$this->accessed_options[ $option_name ] = 0;
		}

		++$this->accessed_options[ $option_name ];
	}

	/**
	 * Update the 'option_optimizer' option with the list of used options at the end of the page load.
	 *
	 * @return void
	 */
	public function update_tracked_options() {
		// phpcs:ignore WordPress.Security.NonceVerification -- not doing anything.
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'aaa-option-optimizer' ) {
			return;
		}
		// Retrieve the existing option_optimizer data.
		$option_optimizer = get_option( 'option_optimizer', [ 'used_options' => [] ] );

		$option_optimizer['used_options'] = $this->accessed_options;

		if ( $this->should_reset ) {
			$option_optimizer['used_options'] = [];
		}

		// Update the 'option_optimizer' option with the new list.
		update_option( 'option_optimizer', $option_optimizer, false );
	}
}
