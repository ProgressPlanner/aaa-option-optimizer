<?php
/**
 * Plugin functionality for AAA Option Optimizer.
 *
 * @package Emilia\MetaOptimizer
 */

namespace Emilia\MetaOptimizer;

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
	 * Holds the names of the meta fields accessed during the request.
	 *
	 * @var string[]
	 */
	protected $accessed_meta_fields = [];

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
		$this->accessed_meta_fields = \get_option( 'meta_optimizer', [ 'used_meta_fields' => [] ] )['used_meta_fields'];

		// Hook into all actions and filters to monitor option accesses.
		// @phpstan-ignore-next-line -- The 'all' hook does not need a return.
		\add_filter( 'all', [ $this, 'monitor_meta_field_accesses' ] );

		// Use the shutdown action to update the option with tracked data.
		\add_action( 'shutdown', [ $this, 'update_tracked_meta_fields' ] );

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
	public function monitor_meta_field_accesses( $tag ) {

		// Check if the tag is related to a post meta field access.
		if ( 'get_post_metadata' === $tag ) {
			$args     = func_get_args(); // phpcs:ignore -- Get all arguments passed to the hook.
			$meta_key = $args[3];
			$this->add_meta_field_usage( $meta_key );
		}

		// Check if the tag is related to a default post meta field access, meaning that the meta field doesn't exist in the database yet.
		if ( 'default_post_metadata' === $tag ) {
			$args     = func_get_args(); // phpcs:ignore -- Get all arguments passed to the hook.
			$meta_key = $args[3];
			$this->remove_meta_field_usage( $meta_key );
		}
	}

	/**
	 * Add a meta field to the list of used meta fields if it's not already there.
	 *
	 * @param string $meta_key Name of the meta field being accessed.
	 *
	 * @return void
	 */
	protected function add_meta_field_usage( $meta_key ) {
		// Check if this option hasn't been tracked yet and add it to the array.
		if ( ! array_key_exists( $meta_key, $this->accessed_meta_fields ) ) {
			$this->accessed_meta_fields[ $meta_key ] = 1;
			return;
		}
		++$this->accessed_meta_fields[ $meta_key ];
	}

	/**
	 * Add a meta field to the list of used meta fields if it's not already there.
	 *
	 * @param string $meta_key Name of the meta field being accessed.
	 *
	 * @return void
	 */
	protected function remove_meta_field_usage( $meta_key ) {
		// Check if this option hasn't been tracked yet and add it to the array.
		if ( array_key_exists( $meta_key, $this->accessed_meta_fields ) ) {
			unset( $this->accessed_meta_fields[ $meta_key ] );
		}
	}

	/**
	 * Update the 'meta_optimizer' option with the list of used meta fields at the end of the page load.
	 *
	 * @return void
	 */
	public function update_tracked_meta_fields() {
		// phpcs:ignore WordPress.Security.NonceVerification -- not doing anything.
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'aaa-meta-optimizer' ) {
			return;
		}
		// Retrieve the existing meta_optimizer data.
		$meta_optimizer = get_option( 'meta_optimizer', [ 'used_meta_fields' => [] ] );

		$meta_optimizer['used_meta_fields'] = $this->accessed_meta_fields;

		if ( $this->should_reset ) {
			$meta_optimizer['used_meta_fields'] = [];
		}

		// Update the 'meta_optimizer' option with the new list.
		update_option( 'meta_optimizer', $meta_optimizer, true );
	}
}
