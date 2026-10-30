<?php
/**
 * Plugin functionality for AAA Autoload Optimizer.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer;

/**
 * Core functionality of AAA Autoload Optimizer.
 */
class Plugin {
	/**
	 * The instance of the plugin.
	 *
	 * @var Plugin
	 */
	public static $instance;

	/**
	 * Holds the options accessed during the request with their access counts.
	 *
	 * @var array<string, int>
	 */
	protected $accessed_options = [];

	/**
	 * Whether the plugin is currently processing an option access.
	 * Used to prevent infinite recursion.
	 *
	 * @var bool
	 */
	protected $is_processing = false;

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
		if ( Admin_Page::get_option_tracking() === 'pre_option' ) {
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
		if ( $this->is_processing ) {
			return;
		}

		// The option page capability filter is not an option access, and its tag suffix comes from the request.
		if ( str_starts_with( $tag, 'option_page_capability_' ) ) {
			return;
		}

		// Check if the tag is related to an option access.
		if ( str_starts_with( $tag, 'option_' ) || str_starts_with( $tag, 'default_option_' ) ) {
			$this->is_processing = true;
			$option_name         = preg_replace( '#^(default_)?option_#', '', $tag );
			$this->add_option_usage( $option_name );
			$this->is_processing = false;
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
		if ( $this->is_processing ) {
			return $pre;
		}

		// If the $pre is false the get_option() will not be short-circuited.
		if ( ! defined( 'WP_SETUP_CONFIG' ) && false === $pre ) {
			$this->is_processing = true;
			$this->add_option_usage( $option_name );
			$this->is_processing = false;
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
			// Only validate the first access in a request, repeat accesses are already known to be valid.
			if ( ! $this->is_trackable_option_name( $option_name ) ) {
				return;
			}
			$this->accessed_options[ $option_name ] = 0;
		}

		++$this->accessed_options[ $option_name ];
	}

	/**
	 * Whether an accessed option name should be tracked.
	 *
	 * Only names that can't exist in the options table are rejected. Any other name can be a
	 * real option, and an untracked option would be reported as unused.
	 *
	 * @param string $option_name Name of the option being accessed.
	 *
	 * @return bool
	 */
	protected function is_trackable_option_name( $option_name ) {
		// The option_name column is VARCHAR(191) here and in wp_options, which counts characters, not bytes. Not filterable.
		if ( $option_name === '' || \mb_strlen( $option_name ) > 191 ) {
			return false;
		}

		/**
		 * Filters whether an accessed option name should be tracked.
		 *
		 * Option names are always escaped on output, regardless of this filter. Note that an
		 * autoloaded option excluded here will be listed as "Unused, but autoloaded".
		 *
		 * @param bool   $is_trackable Whether the name should be tracked. Default true.
		 * @param string $option_name  The option name.
		 */
		return (bool) \apply_filters( 'aaa_option_optimizer_is_trackable_option_name', true, $option_name );
	}

	/**
	 * Update the tracked options at the end of the page load.
	 *
	 * @return void
	 */
	public function update_tracked_options() {
		// phpcs:ignore WordPress.Security.NonceVerification -- not doing anything.
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'aaa-option-optimizer' ) {
			return;
		}

		// Handle reset.
		if ( $this->should_reset ) {
			Database::clear_tracked_options();
			return;
		}

		// Write accessed options directly to the custom table.
		if ( ! empty( $this->accessed_options ) ) {
			Database::batch_insert( $this->accessed_options );
		}
	}
}
