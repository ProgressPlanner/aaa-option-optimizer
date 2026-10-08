<?php
/**
 * Export options to JSON.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer;

/**
 * Builds the JSON payload for option exports. The REST handler is responsible
 * for streaming this payload to the browser as a download.
 */
class Exporter {

	/**
	 * Export format version. Bump when the payload shape changes incompatibly.
	 */
	const VERSION = '1.0.0';

	/**
	 * Build the export payload for a list of option names.
	 *
	 * Unknown option names are silently skipped. Order in the output follows the
	 * order in the input.
	 *
	 * @param string[] $option_names Option names to export.
	 *
	 * @return array<string, mixed>
	 */
	public function export( array $option_names ) {
		global $wpdb;

		$options = [];

		if ( ! empty( $option_names ) ) {
			$option_names = \array_values( \array_unique( \array_filter( \array_map( 'strval', $option_names ) ) ) );

			if ( ! empty( $option_names ) ) {
				$placeholders = \implode( ',', \array_fill( 0, \count( $option_names ), '%s' ) );

				$sql = "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name IN ( {$placeholders} )"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built above; $wpdb->options is safe.

				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare( $sql, ...$option_names ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					ARRAY_A
				);

				if ( $rows ) {
					// Index by name so we can return in requested order.
					$by_name = [];
					foreach ( $rows as $row ) {
						$by_name[ (string) $row['option_name'] ] = $row;
					}

					foreach ( $option_names as $name ) {
						if ( isset( $by_name[ $name ] ) ) {
							$row       = $by_name[ $name ];
							$options[] = [
								'option_name'  => (string) $row['option_name'],
								'option_value' => (string) $row['option_value'],
								'autoload'     => (string) $row['autoload'],
							];
						}
					}
				}
			}
		}

		return [
			'version'     => self::VERSION,
			'exported_at' => \gmdate( 'c' ),
			'site_url'    => \home_url(),
			'wp_version'  => \get_bloginfo( 'version' ),
			'plugin'      => 'aaa-option-optimizer',
			'options'     => $options,
		];
	}

	/**
	 * Build a suggested filename for the export.
	 *
	 * @return string
	 */
	public function suggested_filename() {
		$host = \wp_parse_url( \home_url(), PHP_URL_HOST );
		$host = \is_string( $host ) ? \preg_replace( '/[^a-zA-Z0-9.\-]/', '', $host ) : 'site';
		return 'aaa-option-optimizer-' . $host . '-' . \gmdate( 'Ymd-His' ) . '.json';
	}
}
