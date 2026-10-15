<?php
/**
 * Import options from a JSON payload.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer;

/**
 * Restores options from an Exporter payload.
 */
class Importer {

	/**
	 * Import a payload.
	 *
	 * @param array<string, mixed> $payload   Parsed JSON payload from Exporter.
	 * @param bool                 $overwrite If true, existing options are overwritten.
	 *
	 * @return array{imported:int, skipped:int, errors:array<int, array{option_name:string, reason:string}>}|\WP_Error
	 */
	public function import( array $payload, $overwrite = false ) {
		if ( empty( $payload['version'] ) || empty( $payload['options'] ) || ! \is_array( $payload['options'] ) ) {
			return new \WP_Error(
				'invalid_payload',
				\__( 'Invalid import payload: missing version or options.', 'aaa-option-optimizer' ),
				[ 'status' => 400 ]
			);
		}

		// Forward-compat: accept any 1.x version. Bump self::handle on breaking changes.
		if ( 0 !== \strpos( (string) $payload['version'], '1.' ) ) {
			return new \WP_Error(
				'unsupported_version',
				\sprintf(
					/* translators: %s: version string */
					\__( 'Unsupported export version: %s.', 'aaa-option-optimizer' ),
					(string) $payload['version']
				),
				[ 'status' => 400 ]
			);
		}

		$imported = 0;
		$skipped  = 0;
		$errors   = [];

		foreach ( $payload['options'] as $entry ) {
			if ( ! \is_array( $entry ) || empty( $entry['option_name'] ) ) {
				++$skipped;
				continue;
			}

			$name = (string) $entry['option_name'];

			if ( Protected_Options::is_protected( $name ) ) {
				++$skipped;
				$errors[] = [
					'option_name' => $name,
					'reason'      => 'protected',
				];
				continue;
			}

			$raw_value = isset( $entry['option_value'] ) ? (string) $entry['option_value'] : '';
			$value     = \maybe_unserialize( $raw_value );
			$autoload  = isset( $entry['autoload'] ) ? (string) $entry['autoload'] : 'no';
			$bool_auto = \in_array( $autoload, \wp_autoload_values_to_autoload(), true );

			$exists = false !== \get_option( $name, false );

			if ( $exists ) {
				if ( ! $overwrite ) {
					++$skipped;
					$errors[] = [
						'option_name' => $name,
						'reason'      => 'exists',
					];
					continue;
				}

				\delete_option( $name );
			}

			if ( \add_option( $name, $value, '', $bool_auto ) ) {
				++$imported;
			} else {
				++$skipped;
				$errors[] = [
					'option_name' => $name,
					'reason'      => 'add_option_failed',
				];
			}
		}

		return [
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		];
	}
}
