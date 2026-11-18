<?php
/**
 * Validate the community-maintained plugin map without dependencies.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer;

if ( PHP_SAPI !== 'cli' ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Standalone CLI.

/**
 * Validate a local plugin map and exit with a CI-friendly status.
 *
 * @param string $filename JSON file path.
 * @return void
 */
function validate_known_plugins( $filename ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local CLI input.
	$contents = file_get_contents( $filename );
	$plugins  = false === $contents ? null : json_decode( $contents );
	$errors   = [];

	if ( ! $plugins instanceof \stdClass || ! count( get_object_vars( $plugins ) ) ) {
		$errors[] = 'The plugin map must be a non-empty JSON object.';
	} else {
		foreach ( get_object_vars( $plugins ) as $slug => $plugin ) {
			if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]*$/D', (string) $slug ) ) {
				$errors[] = "Invalid plugin slug: $slug";
			}
			if ( ! $plugin instanceof \stdClass ) {
				$errors[] = "$slug must be an object.";
				continue;
			}
			$keys = array_keys( get_object_vars( $plugin ) );
			sort( $keys );
			if ( [ 'name', 'option_prefixes' ] !== $keys ) {
				$errors[] = "$slug must contain exactly name and option_prefixes.";
			}
			if ( ! isset( $plugin->name ) || ! is_string( $plugin->name ) || '' === trim( $plugin->name ) ) {
				$errors[] = "$slug.name must be a non-empty string.";
			}
			if ( ! isset( $plugin->option_prefixes ) || ! is_array( $plugin->option_prefixes ) || ! $plugin->option_prefixes ) {
				$errors[] = "$slug.option_prefixes must be a non-empty array.";
				continue;
			}
			$seen = [];
			foreach ( $plugin->option_prefixes as $prefix ) {
				if ( ! is_string( $prefix ) || '' === trim( $prefix ) || preg_match( '/[\x00-\x1f\x7f]/', $prefix ) || preg_match_all( '/./us', $prefix ) > 191 ) {
					$errors[] = "$slug has an invalid option prefix.";
					continue;
				}
				if ( in_array( $prefix, $seen, true ) ) {
					$errors[] = "$slug has a duplicate option prefix.";
				}
				$seen[] = $prefix;
			}
		}
	}

	if ( $errors ) {
		fwrite( STDERR, implode( "\n", $errors ) . "\n" );
		exit( 1 );
	}
	fwrite( STDOUT, "Known plugins schema is valid.\n" );
}

validate_known_plugins( $argv[1] ?? dirname( __DIR__ ) . '/known-plugins/known-plugins.json' );
