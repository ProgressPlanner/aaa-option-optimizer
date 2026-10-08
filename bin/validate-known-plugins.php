<?php
/**
 * Validate known-plugins/known-plugins.json before publishing.
 *
 * Exits non-zero on any problem so CI can fail.
 *
 * @package Progress_Planner\OptionOptimizer
 */

$file = dirname( __DIR__ ) . '/known-plugins/known-plugins.json';

if ( ! file_exists( $file ) ) {
	fwrite( STDERR, "Missing file: {$file}\n" );
	exit( 1 );
}

$raw = file_get_contents( $file );
if ( false === $raw || '' === trim( $raw ) ) {
	fwrite( STDERR, "Empty file: {$file}\n" );
	exit( 1 );
}

$data = json_decode( $raw, true );
if ( JSON_ERROR_NONE !== json_last_error() ) {
	fwrite( STDERR, 'Invalid JSON: ' . json_last_error_msg() . "\n" );
	exit( 1 );
}

if ( ! is_array( $data ) || empty( $data ) ) {
	fwrite( STDERR, "Top-level JSON must be a non-empty object.\n" );
	exit( 1 );
}

$errors        = [];
$warnings      = [];
$seen_prefixes = [];

foreach ( $data as $slug => $entry ) {
	if ( ! is_string( $slug ) || '' === $slug ) {
		$errors[] = 'Slug must be a non-empty string.';
		continue;
	}

	if ( ! is_array( $entry ) ) {
		$errors[] = "Entry for '{$slug}' must be an object.";
		continue;
	}

	if ( empty( $entry['name'] ) || ! is_string( $entry['name'] ) ) {
		$errors[] = "Entry '{$slug}' missing 'name' string.";
	}

	if ( empty( $entry['option_prefixes'] ) || ! is_array( $entry['option_prefixes'] ) ) {
		$errors[] = "Entry '{$slug}' missing non-empty 'option_prefixes' array.";
		continue;
	}

	foreach ( $entry['option_prefixes'] as $prefix ) {
		if ( ! is_string( $prefix ) || '' === $prefix ) {
			$errors[] = "Entry '{$slug}' has empty/non-string prefix.";
			continue;
		}

		// Reject dangerously generic prefixes that would match thousands of options.
		if ( strlen( $prefix ) < 3 ) {
			$errors[] = "Entry '{$slug}' prefix '{$prefix}' is too short (<3 chars).";
		}

		if ( in_array( $prefix, [ 'wp_', 'option_', '_transient_' ], true ) ) {
			$errors[] = "Entry '{$slug}' prefix '{$prefix}' is reserved/dangerous.";
		}

		if ( isset( $seen_prefixes[ $prefix ] ) && $seen_prefixes[ $prefix ] !== $slug ) {
			$warnings[] = "Prefix '{$prefix}' claimed by both '{$seen_prefixes[ $prefix ]}' and '{$slug}'.";
		}
		$seen_prefixes[ $prefix ] = $slug;
	}
}

foreach ( $warnings as $warn ) {
	fwrite( STDERR, "WARNING: {$warn}\n" );
}

if ( ! empty( $errors ) ) {
	fwrite( STDERR, "Validation failed:\n" );
	foreach ( $errors as $err ) {
		fwrite( STDERR, "  - {$err}\n" );
	}
	exit( 1 );
}

$count = count( $data );
echo "OK: {$count} entries validated.\n";
exit( 0 );
