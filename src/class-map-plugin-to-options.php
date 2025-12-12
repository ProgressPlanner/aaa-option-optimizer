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
	 * @var object[]
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
		$plugins_list = [];
		if ( empty( $this->plugins_list ) ) {
			$this->plugins_list = json_decode( file_get_contents( plugin_dir_path( AAA_OPTION_OPTIMIZER_FILE ) . 'known-plugins/known-plugins.json' ), true );
		}

		// for each plugin in the list, check if the option starts with the prefix.
		foreach ( $this->plugins_list as $plugin ) {
			foreach ( $plugin['option_prefixes'] as $prefix ) {
				if ( strpos( $option, $prefix ) === 0 ) {
					if ( isset( $plugin['name'] ) ) {
						return $plugin['name'];
					}
				}
			}
		}

		return __( 'Unknown', 'aaa-option-optimizer' );
	}
}
