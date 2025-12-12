<?php
/**
 * Plugin functionality for AAA Option Optimizer.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer;

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
		// Hook into all actions and filters to monitor option accesses.
		// @phpstan-ignore-next-line -- The 'all' hook does not need a return.
		\add_filter( 'all', [ $this, 'monitor_option_accesses' ] );

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
	public function monitor_option_accesses( $tag ) {
		// Check if the tag is related to an option access.
		if ( str_starts_with( $tag, 'option_' ) || str_starts_with( $tag, 'default_option_' ) ) {
			$option_name = preg_replace( '#^(default_)?option_#', '', $tag );
			$this->add_option_usage( $option_name );
		}
	}

	/**
	 * Add an option to the list of used options if it's not already there.
	 *
	 * @param string $option_name Name of the option being accessed.
	 *
	 * @return void
	 */
	protected function add_option_usage( $option_name ) {
		// Check if this option hasn't been tracked yet and add it to the array.
		if ( ! array_key_exists( $option_name, $this->accessed_options ) ) {
			$this->accessed_options[ $option_name ] = 1;
			return;
		}
		++$this->accessed_options[ $option_name ];
	}

	/**
	 * Update the tracked options at the end of the page load.
	 *
	 * Uses transient batching to reduce database writes - only flushes to the custom table
	 * every 5 minutes instead of on every request.
	 *
	 * @return void
	 */
	public function update_tracked_options() {
		// phpcs:ignore WordPress.Security.NonceVerification -- not doing anything.
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'aaa-option-optimizer' ) {
			return;
		}

		// Handle reset: clear batch and custom table.
		if ( $this->should_reset ) {
			\delete_transient( 'option_optimizer_batch' );
			Database::clear_tracked_options();
			return;
		}

		// Get the batch data.
		$batch_data = $this->get_batch_data();

		// Add current request's options to the batch.
		foreach ( $this->accessed_options as $option_name => $count ) {
			if ( ! isset( $batch_data['options'][ $option_name ] ) ) {
				$batch_data['options'][ $option_name ] = 0;
			}
			$batch_data['options'][ $option_name ] += $count;
		}

		// Check if it's time to flush the batch.
		$should_flush = ( \time() - $batch_data['last_flush'] ) >= $this->get_flush_interval();

		// Flush batch to custom table every 5 minutes.
		if ( ! empty( $batch_data['options'] ) && $should_flush ) {
			Database::batch_insert( $batch_data['options'] );

			// Reset the batch data.
			$batch_data = [
				'options'    => [],
				'last_flush' => \time(),
			];
		}

		// No expiry - batch is explicitly deleted on flush, expiry would only cause data loss.
		\set_transient( 'option_optimizer_batch', $batch_data, 0 );
	}

	/**
	 * Get the batch data.
	 *
	 * @return array<string, int>
	 */
	protected function get_batch_data() {
		// Get existing batch (stores both data and flush timestamp in one transient).
		$batch_data = \get_transient( 'option_optimizer_batch' );
		if ( ! \is_array( $batch_data ) || ! isset( $batch_data['options'], $batch_data['last_flush'] ) ) {
			$batch_data = [
				'options'    => [],
				'last_flush' => \time(),
			];
		}

		return $batch_data;
	}

	/**
	 * Get the flush interval.
	 *
	 * @return int
	 */
	protected function get_flush_interval() {
		return (int) \apply_filters( 'aaa_option_optimizer_flush_interval', 5 * MINUTE_IN_SECONDS );
	}
}
