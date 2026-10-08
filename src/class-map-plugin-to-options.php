<?php
/**
 * Functionality to map options to plugins.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer;

/**
 * Class Map_Plugin_To_Options
 *
 * @package Progress_Planner\OptionOptimizer
 */
class Map_Plugin_To_Options {
	/**
	 * List of plugins we can recognize.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $plugins_list = [];

	/**
	 * Find plugin in known plugin prefixes list.
	 *
	 * @param string $option The option name.
	 *
	 * @return string
	 */
	public function get_plugin_name( string $option ): string {
		$match = $this->find_match( $option );
		return null !== $match ? $match : __( 'Unknown', 'aaa-option-optimizer' );
	}

	/**
	 * Whether the option's source plugin is known.
	 *
	 * @param string $option The option name.
	 *
	 * @return bool
	 */
	public function is_known( string $option ): bool {
		return null !== $this->find_match( $option );
	}

	/**
	 * Look up the plugin name for an option, or null if no match.
	 *
	 * @param string $option The option name.
	 *
	 * @return string|null
	 */
	private function find_match( string $option ): ?string {
		if ( empty( $this->plugins_list ) ) {
			$known              = new Known_Plugins();
			$this->plugins_list = $known->get();
		}

		$match = null;
		foreach ( $this->plugins_list as $plugin ) {
			foreach ( $plugin['option_prefixes'] as $prefix ) {
				if ( strpos( $option, $prefix ) === 0 && isset( $plugin['name'] ) ) {
					$match = $plugin['name'];
					break 2;
				}
			}
		}

		/**
		 * Filters the plugin name an option is mapped to.
		 *
		 * Lets integrations recognize options the bundled known-plugins list
		 * does not, or override an existing match. Return a plugin name to mark
		 * the option as known, or null to leave it unrecognized. Both
		 * get_plugin_name() and is_known() honor this filter, so a filtered
		 * match is treated as known everywhere.
		 *
		 * @param string|null $match  The matched plugin name, or null if unknown.
		 * @param string      $option The option name being looked up.
		 */
		return \apply_filters( 'aaa_option_optimizer_plugin_name', $match, $option );
	}
}
