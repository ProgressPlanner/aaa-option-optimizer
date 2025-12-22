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
	 * Get the full table name with prefix.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
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
	 * @param array<string, int> $options    Array of option_name => count.
	 * @param int                $chunk_size Number of options per query. Default 500.
	 *
	 * @return void
	 */
	public static function batch_insert( $options, $chunk_size = 500 ) {
		global $wpdb;

		if ( empty( $options ) ) {
			return;
		}

		$table_name = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'BEGIN' );

		foreach ( array_chunk( $options, $chunk_size, true ) as $chunk ) {
			$values       = [];
			$placeholders = [];

			foreach ( $chunk as $option_name => $count ) {
				$placeholders[] = '(%s, %d, NOW())';
				$values[]       = $option_name;
				$values[]       = (int) $count;
			}

			$sql = "INSERT INTO {$table_name} (option_name, access_count, created_at)
					VALUES " . implode( ', ', $placeholders ) . '
					ON DUPLICATE KEY UPDATE access_count = access_count + VALUES(access_count)';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( $sql, ...$values ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'COMMIT' );
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
}
