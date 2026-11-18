<?php
/**
 * Tests for mapping options to the plugin that owns them.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer\Tests;

use Progress_Planner\OptionOptimizer\Map_Plugin_To_Options;

/**
 * Tests the Map_Plugin_To_Options class and the known plugins list it reads.
 *
 * @covers \Progress_Planner\OptionOptimizer\Map_Plugin_To_Options
 */
class Map_Plugin_To_Options_Test extends Test_Case {

	/**
	 * Options are matched on their prefix.
	 *
	 * @return void
	 */
	public function test_known_prefixes_are_mapped() {
		$map = new Map_Plugin_To_Options();

		$this->assertSame( 'AAA Autoload Optimizer', $map->get_plugin_name( 'option_optimizer' ) );
		$this->assertSame( 'Akismet', $map->get_plugin_name( 'akismet_strictness' ) );
		$this->assertSame( 'Akismet', $map->get_plugin_name( 'wordpress_api_key' ) );
	}

	/**
	 * A prefix only matches at the start of the option name.
	 *
	 * @return void
	 */
	public function test_unknown_options_are_reported_as_unknown() {
		$map = new Map_Plugin_To_Options();

		$this->assertSame( 'Unknown', $map->get_plugin_name( self::PREFIX . 'nobody_owns_this' ) );
		$this->assertSame( 'Unknown', $map->get_plugin_name( self::PREFIX . 'akismet_strictness' ) );
	}

	/**
	 * Every entry in the shipped list has the shape the mapper relies on.
	 *
	 * @return void
	 */
	public function test_known_plugins_file_is_well_formed() {
		$plugins = \json_decode( \file_get_contents( AAA_OPTION_OPTIMIZER_DIR . '/known-plugins/known-plugins.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

		$this->assertIsArray( $plugins );
		$this->assertNotEmpty( $plugins );

		foreach ( $plugins as $slug => $plugin ) {
			$this->assertIsString( $plugin['name'] ?? null, "{$slug} has no name." );
			$this->assertNotSame( '', $plugin['name'], "{$slug} has an empty name." );
			$this->assertIsArray( $plugin['option_prefixes'] ?? null, "{$slug} has no option_prefixes." );
			$this->assertNotEmpty( $plugin['option_prefixes'], "{$slug} has no option_prefixes." );

			foreach ( $plugin['option_prefixes'] as $prefix ) {
				$this->assertIsString( $prefix, "{$slug} has a non-string prefix." );
				$this->assertNotSame( '', $prefix, "{$slug} has an empty prefix, which would match every option." );
			}
		}
	}
}
