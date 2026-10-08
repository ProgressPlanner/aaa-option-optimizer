<?php
/**
 * Database functionality for AAA Option Optimizer.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer;

/**
 * Handles custom database table for tracking options.
 */
class Database {

	/**
	 * The database table name (without prefix).
	 *
	 * @var string
	 */
	const TABLE_NAME = 'option_optimizer_tracked';

	/**
	 * The quarantine table name (without prefix).
	 *
	 * @var string
	 */
	const QUARANTINE_TABLE_NAME = 'option_optimizer_quarantine';

	/**
	 * Get the full table name with prefix.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Get the full quarantine table name with prefix.
	 *
	 * @return string
	 */
	public static function get_quarantine_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::QUARANTINE_TABLE_NAME;
	}

	/**
	 * Create the custom table.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			option_name VARCHAR(191) NOT NULL,
			access_count BIGINT UNSIGNED DEFAULT 1,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (option_name)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		\dbDelta( $sql );
	}

	/**
	 * Drop the custom table.
	 *
	 * @return void
	 */
	public static function drop_table() {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (from constant).
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
	}

	/**
	 * Check if the table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
	}

	/**
	 * Number of options to migrate per request.
	 *
	 * @var int
	 */
	const MIGRATION_CHUNK_SIZE = 1000;

	/**
	 * Get migration status.
	 *
	 * @return array{needs_migration: bool, total: int, remaining: int}
	 */
	public static function get_migration_status() {
		$option_data = \get_option( 'option_optimizer' );

		if ( ! \is_array( $option_data ) || empty( $option_data['used_options'] ) ) {
			return [
				'needs_migration' => false,
				'total'           => 0,
				'remaining'       => 0,
			];
		}

		$remaining = \count( $option_data['used_options'] );

		// Get total from transient or set it on first check.
		$total = \get_transient( 'aaa_option_optimizer_migration_total' );
		if ( false === $total ) {
			$total = $remaining;
			\set_transient( 'aaa_option_optimizer_migration_total', $total, HOUR_IN_SECONDS );
		}

		return [
			'needs_migration' => true,
			'total'           => (int) $total,
			'remaining'       => $remaining,
		];
	}

	/**
	 * Migrate a chunk of data from the old option format to the custom table.
	 *
	 * Processes in chunks to avoid timeouts on slow hosts with large datasets.
	 *
	 * @return array{success: bool, remaining: int, total: int}
	 */
	public static function migrate_chunk() {
		$option_data = \get_option( 'option_optimizer' );

		// No data or already migrated.
		if ( ! \is_array( $option_data ) || empty( $option_data['used_options'] ) ) {
			\delete_transient( 'aaa_option_optimizer_migration_total' );
			return [
				'success'   => true,
				'remaining' => 0,
				'total'     => 0,
			];
		}

		// Ensure table exists.
		if ( ! self::table_exists() ) {
			self::create_table();
		}

		// Get total for progress tracking.
		$total = \get_transient( 'aaa_option_optimizer_migration_total' );
		if ( false === $total ) {
			$total = \count( $option_data['used_options'] );
			\set_transient( 'aaa_option_optimizer_migration_total', $total, HOUR_IN_SECONDS );
		}

		// Take a chunk of options to migrate.
		$chunk = \array_slice( $option_data['used_options'], 0, self::MIGRATION_CHUNK_SIZE, true );

		// Batch insert chunk to custom table.
		self::batch_insert( $chunk );

		// Remove migrated options from the array.
		$option_data['used_options'] = \array_slice( $option_data['used_options'], self::MIGRATION_CHUNK_SIZE, null, true );

		\update_option( 'option_optimizer', $option_data, false );

		$remaining = \count( $option_data['used_options'] );

		// Clean up total transient when done.
		if ( 0 === $remaining ) {
			\delete_transient( 'aaa_option_optimizer_migration_total' );
		}

		return [
			'success'   => true,
			'remaining' => $remaining,
			'total'     => (int) $total,
		];
	}

	/**
	 * Batch insert or update option counts.
	 *
	 * Splits large datasets into chunks and wraps them in a transaction
	 * for optimal performance on slow hosts with large datasets.
	 *
	 * Rows are written in a fixed (sorted) order. Every request writes the
	 * options it read at shutdown, and InnoDB locks rows in the order of the
	 * VALUES list; with per-request access order, concurrent requests lock
	 * the same rows in different orders and deadlock each other.
	 *
	 * @param array<string, int> $options    Array of option_name => count.
	 * @param int                $chunk_size Number of options per query. Default 500.
	 *
	 * @return void
	 */
	public static function batch_insert( $options, $chunk_size = 500 ) {
		if ( empty( $options ) ) {
			return;
		}

		// SORT_STRING: numeric option names become int keys, keep one total order.
		\ksort( $options, SORT_STRING );

		// A deadlock rolls back the whole transaction, so one retry can't double count.
		if ( ! self::write_chunks( $options, $chunk_size ) && self::last_error_is_deadlock() ) {
			self::write_chunks( $options, $chunk_size );
		}
	}

	/**
	 * Write option counts in chunks inside a single transaction.
	 *
	 * @param array<string, int> $options    Array of option_name => count, already sorted.
	 * @param int                $chunk_size Number of options per query.
	 *
	 * @return bool Whether the transaction was committed.
	 */
	private static function write_chunks( $options, $chunk_size ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// Use `START TRANSACTION` (not `BEGIN`) so W3 Total Cache's DbCache layer
		// recognizes it as a transaction and skips caching — `BEGIN` slips past its
		// regex and triggers a fatal in wpdb::load_col_info() on the boolean result.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );

		foreach ( array_chunk( $options, $chunk_size, true ) as $chunk ) {
			$values       = [];
			$placeholders = [];

			foreach ( $chunk as $option_name => $count ) {
				$placeholders[] = '(%s, %d, NOW())';
				$values[]       = (string) $option_name;
				$values[]       = (int) $count;
			}

			$sql = "INSERT INTO {$table_name} (option_name, access_count, created_at)
					VALUES " . implode( ', ', $placeholders ) . '
					ON DUPLICATE KEY UPDATE access_count = access_count + VALUES(access_count)';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $wpdb->query( $wpdb->prepare( $sql, ...$values ) ) ) {
				// Keep the error: ROLLBACK succeeding would clear `$wpdb->last_error`.
				$error = $wpdb->last_error;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( 'ROLLBACK' );
				$wpdb->last_error = $error;
				return false;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->query( 'COMMIT' );
	}

	/**
	 * Whether the last database error was an InnoDB deadlock (MySQL error 1213).
	 *
	 * @return bool
	 */
	private static function last_error_is_deadlock() {
		global $wpdb;

		return false !== \stripos( (string) $wpdb->last_error, 'Deadlock found' );
	}

	/**
	 * Get all tracked options as an associative array.
	 *
	 * @return array<string, int> Array of option_name => access_count.
	 */
	public static function get_tracked_options() {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (from constant).
		$results = $wpdb->get_results( "SELECT option_name, access_count FROM {$table_name}", ARRAY_A );

		if ( empty( $results ) ) {
			return [];
		}

		$options = [];
		foreach ( $results as $row ) {
			$options[ $row['option_name'] ] = (int) $row['access_count'];
		}

		return $options;
	}

	/**
	 * Get tracked option names as a keyed array for efficient lookups.
	 *
	 * @return array<string, bool> Array of option_name => true.
	 */
	public static function get_tracked_option_keys() {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (from constant).
		$option_names = $wpdb->get_col( "SELECT option_name FROM {$table_name}" );

		if ( empty( $option_names ) ) {
			return [];
		}

		return array_fill_keys( $option_names, true );
	}

	/**
	 * Clear all tracked options from the table.
	 *
	 * @return void
	 */
	public static function clear_tracked_options() {
		global $wpdb;

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (from constant).
		$wpdb->query( "TRUNCATE TABLE {$table_name}" );
	}

	/**
	 * Create the quarantine table.
	 *
	 * @return void
	 */
	public static function create_quarantine_table() {
		global $wpdb;

		$table_name      = self::get_quarantine_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			option_name VARCHAR(191) NOT NULL,
			option_value LONGTEXT NOT NULL,
			autoload VARCHAR(20) NOT NULL DEFAULT 'no',
			quarantined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			expires_at DATETIME NOT NULL,
			expiry_action VARCHAR(10) NOT NULL DEFAULT 'keep',
			PRIMARY KEY (id),
			UNIQUE KEY option_name (option_name)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		\dbDelta( $sql );
	}

	/**
	 * Drop the quarantine table.
	 *
	 * @return void
	 */
	public static function drop_quarantine_table() {
		global $wpdb;

		$table_name = self::get_quarantine_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name is safe (from constant).
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
	}

	/**
	 * Check if the quarantine table exists.
	 *
	 * @return bool
	 */
	public static function quarantine_table_exists() {
		global $wpdb;

		$table_name = self::get_quarantine_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
	}

	/**
	 * Insert a quarantine row.
	 *
	 * @param string $option_name   Option name.
	 * @param string $option_value  Serialized option value as stored in wp_options.
	 * @param string $autoload      Autoload value as stored in wp_options.
	 * @param string $expires_at    MySQL DATETIME for expiry.
	 * @param string $expiry_action 'keep' or 'delete'.
	 *
	 * @return bool True on success.
	 */
	public static function insert_quarantine_row( $option_name, $option_value, $autoload, $expires_at, $expiry_action = 'keep' ) {
		global $wpdb;

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::get_quarantine_table_name(),
			[
				'option_name'    => $option_name,
				'option_value'   => $option_value,
				'autoload'       => $autoload,
				'quarantined_at' => \current_time( 'mysql' ),
				'expires_at'     => $expires_at,
				'expiry_action'  => $expiry_action,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return false !== $result;
	}

	/**
	 * Get a quarantine row by option name.
	 *
	 * @param string $option_name Option name.
	 *
	 * @return array<string, mixed>|null Row data, or null if not found.
	 */
	public static function get_quarantine_row( $option_name ) {
		global $wpdb;

		$table_name = self::get_quarantine_table_name();

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE option_name = %s", $option_name ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (from constant).
			ARRAY_A
		);

		return null === $row ? null : $row;
	}

	/**
	 * Delete a quarantine row by option name.
	 *
	 * @param string $option_name Option name.
	 *
	 * @return bool True on success.
	 */
	public static function delete_quarantine_row( $option_name ) {
		global $wpdb;

		$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::get_quarantine_table_name(),
			[ 'option_name' => $option_name ],
			[ '%s' ]
		);

		return false !== $result && $result > 0;
	}

	/**
	 * Get all quarantine rows.
	 *
	 * @return array<int, array<string, mixed>> Rows.
	 */
	public static function get_all_quarantine_rows() {
		global $wpdb;

		$table_name = self::get_quarantine_table_name();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT * FROM {$table_name} ORDER BY quarantined_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (from constant).
			ARRAY_A
		);

		return empty( $rows ) ? [] : $rows;
	}

	/**
	 * Count quarantine rows.
	 *
	 * @return int
	 */
	public static function count_quarantine_rows() {
		global $wpdb;

		$table_name = self::get_quarantine_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (from constant).
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
	}

	/**
	 * Get expired quarantine rows whose expiry_action is 'delete'.
	 *
	 * @return array<int, array<string, mixed>> Rows.
	 */
	public static function get_expired_quarantine_rows() {
		global $wpdb;

		$table_name = self::get_quarantine_table_name();

		$sql = "SELECT * FROM {$table_name} WHERE expires_at <= %s AND expiry_action = %s"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (from constant).

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $sql, \current_time( 'mysql' ), 'delete' ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return empty( $rows ) ? [] : $rows;
	}
}
