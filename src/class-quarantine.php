<?php
/**
 * Quarantine functionality for AAA Option Optimizer.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer;

/**
 * Moves options into a soft-delete table where they can be restored or permanently removed.
 */
class Quarantine {

	/**
	 * Hook name for the daily wp-cron cleanup event.
	 */
	const CRON_HOOK = 'aaa_option_optimizer_quarantine_cleanup';

	/**
	 * Default retention in days.
	 */
	const DEFAULT_RETENTION_DAYS = 7;

	/**
	 * Default expiry action.
	 */
	const DEFAULT_EXPIRY_ACTION = 'keep';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		\add_action(
			self::CRON_HOOK,
			function () {
				$this->cleanup_expired();
			}
		);
	}

	/**
	 * Quarantine an option: copy its row into the quarantine table and delete from wp_options.
	 *
	 * Protected options are refused. Cap-exceeded calls return an error.
	 *
	 * @param string $option_name Option name.
	 *
	 * @return true|\WP_Error
	 */
	public function quarantine( $option_name ) {
		if ( Protected_Options::is_protected( $option_name ) ) {
			return new \WP_Error(
				'option_protected',
				\sprintf(
					/* translators: %s: option name */
					\__( 'Option "%s" is protected and cannot be quarantined.', 'aaa-option-optimizer' ),
					$option_name
				),
				[ 'status' => 403 ]
			);
		}

		global $wpdb;

		// Read the live row directly so we capture the raw stored value and autoload flag.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s",
				$option_name
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new \WP_Error(
				'option_not_found',
				\__( 'Option does not exist.', 'aaa-option-optimizer' ),
				[ 'status' => 404 ]
			);
		}

		// If already quarantined (somehow), refuse — the unique key would error anyway.
		if ( null !== Database::get_quarantine_row( $option_name ) ) {
			return new \WP_Error(
				'already_quarantined',
				\__( 'Option is already in quarantine.', 'aaa-option-optimizer' ),
				[ 'status' => 409 ]
			);
		}

		$settings      = self::get_settings();
		$retention     = (int) $settings['retention_days'];
		$expiry_action = (string) $settings['expiry_action'];
		$expires_at    = \gmdate( 'Y-m-d H:i:s', \strtotime( "+{$retention} days", \time() ) );

		$inserted = Database::insert_quarantine_row(
			$option_name,
			(string) $row['option_value'],
			(string) $row['autoload'],
			$expires_at,
			$expiry_action
		);

		if ( ! $inserted ) {
			return new \WP_Error(
				'quarantine_failed',
				\__( 'Failed to write to quarantine table.', 'aaa-option-optimizer' ),
				[ 'status' => 500 ]
			);
		}

		if ( ! \delete_option( $option_name ) ) {
			// Roll back the quarantine row so state stays consistent.
			Database::delete_quarantine_row( $option_name );
			return new \WP_Error(
				'delete_failed',
				\__( 'Failed to remove option from wp_options.', 'aaa-option-optimizer' ),
				[ 'status' => 500 ]
			);
		}

		return true;
	}

	/**
	 * Restore a quarantined option back into wp_options.
	 *
	 * @param string $option_name Option name.
	 *
	 * @return true|\WP_Error
	 */
	public function restore( $option_name ) {
		$row = Database::get_quarantine_row( $option_name );
		if ( null === $row ) {
			return new \WP_Error(
				'not_quarantined',
				\__( 'Option is not in quarantine.', 'aaa-option-optimizer' ),
				[ 'status' => 404 ]
			);
		}

		$value    = \maybe_unserialize( (string) $row['option_value'] );
		$autoload = self::normalize_autoload( (string) $row['autoload'] );

		// If WordPress has recreated the option in the meantime, overwrite it.
		if ( false === \get_option( $option_name, false ) ) {
			$ok = \add_option( $option_name, $value, '', $autoload );
		} else {
			\delete_option( $option_name );
			$ok = \add_option( $option_name, $value, '', $autoload );
		}

		if ( ! $ok ) {
			return new \WP_Error(
				'restore_failed',
				\__( 'Failed to restore option.', 'aaa-option-optimizer' ),
				[ 'status' => 500 ]
			);
		}

		Database::delete_quarantine_row( $option_name );

		return true;
	}

	/**
	 * Permanently delete a quarantined option.
	 *
	 * @param string $option_name Option name.
	 *
	 * @return true|\WP_Error
	 */
	public function permanently_delete( $option_name ) {
		$row = Database::get_quarantine_row( $option_name );
		if ( null === $row ) {
			return new \WP_Error(
				'not_quarantined',
				\__( 'Option is not in quarantine.', 'aaa-option-optimizer' ),
				[ 'status' => 404 ]
			);
		}

		Database::delete_quarantine_row( $option_name );
		return true;
	}

	/**
	 * List all quarantined options with display-ready fields.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_all() {
		$rows   = Database::get_all_quarantine_rows();
		$output = [];

		foreach ( $rows as $row ) {
			$raw_value = (string) $row['option_value'];
			$output[]  = [
				'name'           => (string) $row['option_name'],
				'value'          => \htmlentities( $raw_value, ENT_QUOTES | ENT_SUBSTITUTE ),
				'size'           => \round( \strlen( $raw_value ) / 1024, 2 ),
				'autoload'       => (string) $row['autoload'],
				'quarantined_at' => (string) $row['quarantined_at'],
				'expires_at'     => (string) $row['expires_at'],
				'expiry_action'  => (string) $row['expiry_action'],
			];
		}

		return $output;
	}

	/**
	 * Cleanup expired quarantine rows.
	 *
	 * Only rows whose `expiry_action` is `delete` are removed; rows set to `keep`
	 * stay in quarantine until the user acts on them.
	 *
	 * @return int Number of rows removed.
	 */
	public function cleanup_expired() {
		$expired = Database::get_expired_quarantine_rows();
		$count   = 0;

		foreach ( $expired as $row ) {
			if ( Database::delete_quarantine_row( (string) $row['option_name'] ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Get plugin settings relevant to quarantine, with defaults.
	 *
	 * @return array{retention_days:int, expiry_action:string}
	 */
	public static function get_settings() {
		$option   = \get_option( Admin_Page::OPTION_NAME, [] );
		$settings = isset( $option['settings'] ) && \is_array( $option['settings'] ) ? $option['settings'] : [];

		$retention = isset( $settings['quarantine_retention_days'] ) ? (int) $settings['quarantine_retention_days'] : self::DEFAULT_RETENTION_DAYS;
		$retention = \max( 1, \min( 30, $retention ) );

		$action = isset( $settings['quarantine_expiry_action'] ) ? (string) $settings['quarantine_expiry_action'] : self::DEFAULT_EXPIRY_ACTION;
		if ( ! \in_array( $action, [ 'keep', 'delete' ], true ) ) {
			$action = self::DEFAULT_EXPIRY_ACTION;
		}

		return [
			'retention_days' => $retention,
			'expiry_action'  => $action,
		];
	}

	/**
	 * Normalize an autoload value from wp_options into the bool that add_option() expects.
	 *
	 * @param string $autoload Raw autoload column value.
	 *
	 * @return bool
	 */
	private static function normalize_autoload( $autoload ) {
		return \in_array( $autoload, \wp_autoload_values_to_autoload(), true );
	}
}
