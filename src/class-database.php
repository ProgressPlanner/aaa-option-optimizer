<?php
/**
 * Database functionality for AAA Autoload Optimizer.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * Maximum retained option names, including missing options.
	 *
	 * @var int
	 */
	const MAX_TRACKED_OPTIONS = 10000;

	/**
	 * Record that observations have been dropped until the next reset.
	 *
	 * @return void
	 */
	public static function mark_tracking_limited() {
		\update_option( 'aaa_option_optimizer_tracking_limited', true, false );
	}

	/**
	 * Whether unused-option recommendations would be incomplete.
	 *
	 * Also detects an oversized table from an older plugin version before the
	 * next tracking write has had a chance to prune it.
	 *
	 * @return bool
	 */
	public static function is_tracking_limited() {
		global $wpdb;
		if ( \get_option( 'aaa_option_optimizer_tracking_limited', false ) ) {
			return true;
		}
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT option_name FROM %i LIMIT 1 OFFSET %d', $table, self::MAX_TRACKED_OPTIONS - 1 ) );
	}

	/**
	 * Lock name scoped to this database and blog.
	 *
	 * @return string
	 */
	private static function get_tracking_lock() {
		return 'aaaoo_' . md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . self::get_table_name() );
	}

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
	 * @return array{success: bool, remaining: int, total: int, message?: string}
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

		// Remove only observations that were committed; failed or capacity-limited
		// writes must leave the remaining source data available for a retry.
		$written = self::batch_insert( $chunk );
		if ( false === $written ) {
			return [
				'success'   => false,
				'remaining' => count( $option_data['used_options'] ),
				'total'     => (int) $total,
				'message'   => \__( 'Tracking data could not be saved. Please retry the migration.', 'aaa-option-optimizer' ),
			];
		}
		if ( $written ) {
			$option_data['used_options'] = array_diff_key( $option_data['used_options'], $written );
			\update_option( 'option_optimizer', $option_data, false );
		}

		$remaining = \count( $option_data['used_options'] );

		// Clean up total transient when done.
		if ( 0 === $remaining ) {
			\delete_transient( 'aaa_option_optimizer_migration_total' );
		}

		if ( count( $written ) < count( $chunk ) ) {
			return [
				'success'   => false,
				'remaining' => $remaining,
				'total'     => (int) $total,
				'message'   => \__( 'Tracking storage is full. Unmigrated observations have been retained.', 'aaa-option-optimizer' ),
			];
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
	 * @return array<string, int>|false Committed observations, or false on a write failure.
	 */
	public static function batch_insert( $options, $chunk_size = 500 ) {
		global $wpdb;
		if ( empty( $options ) ) {
			return [];
		}

		$table = self::get_table_name();
		$lock  = self::get_tracking_lock();
		// Serialize capacity checks and writes across concurrent requests.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 1)', $lock ) ) ) {
			self::mark_tracking_limited();
			return false;
		}

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			if ( null === $count ) {
				self::mark_tracking_limited();
				return false;
			}
			$count   = (int) $count;
			$limited = false;
			if ( $count >= self::MAX_TRACKED_OPTIONS ) {
				self::mark_tracking_limited();
			}
			if ( $count > self::MAX_TRACKED_OPTIONS ) {
				// Bound tables created by older versions too. Never infer unused options after pruning.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( false === $wpdb->query( $wpdb->prepare( 'DELETE FROM %i ORDER BY created_at ASC, option_name ASC LIMIT %d', $table, $count - self::MAX_TRACKED_OPTIONS ) ) ) {
					return false;
				}
				$count = self::MAX_TRACKED_OPTIONS;
			}

			\ksort( $options, SORT_STRING );
			$remaining = max( 0, self::MAX_TRACKED_OPTIONS - $count );
			$accepted  = [];
			foreach ( array_chunk( $options, 500, true ) as $chunk ) {
				$names = array_keys( $chunk );
				$slots = implode( ',', array_fill( 0, count( $names ), '%s' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$existing = array_fill_keys( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM %i WHERE option_name IN ($slots)", array_merge( [ $table ], $names ) ) ), true );
				if ( $wpdb->last_error ) {
					self::mark_tracking_limited();
					return false;
				}
				foreach ( $chunk as $name => $accesses ) {
					if ( isset( $existing[ $name ] ) ) {
						$accepted[ $name ] = $accesses;
					} elseif ( $remaining > 0 ) {
						$accepted[ $name ] = $accesses;
						--$remaining;
					} else {
						$limited = true;
					}
				}
			}
			if ( $limited ) {
				self::mark_tracking_limited();
			}

			if ( $accepted && ! self::write_chunks( $accepted, max( 1, min( 500, $chunk_size ) ) ) ) {
				if ( ! self::last_error_is_deadlock() || ! self::write_chunks( $accepted, max( 1, min( 500, $chunk_size ) ) ) ) {
					self::mark_tracking_limited();
					return false;
				}
			}
			return $accepted;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Write option counts in chunks inside a single transaction.
	 *
	 * @param array<string, int> $options    Array of option_name => count, already sorted.
	 * @param int                $chunk_size Number of options per query.
	 *
	 * @phpstan-impure
	 * @return bool Whether the transaction was committed.
	 */
	private static function write_chunks( $options, $chunk_size ) {
		global $wpdb;

		$table_name = self::get_table_name();

		// Use `START TRANSACTION` (not `BEGIN`) so W3 Total Cache's DbCache layer
		// recognizes it as a transaction and skips caching — `BEGIN` slips past its
		// regex and triggers a fatal in wpdb::load_col_info() on the boolean result.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}

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
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
		return true;
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
		$results = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, access_count FROM %i ORDER BY option_name LIMIT %d', $table_name, self::MAX_TRACKED_OPTIONS ), ARRAY_A );

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
		$option_names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i ORDER BY option_name LIMIT %d', $table_name, self::MAX_TRACKED_OPTIONS ) );

		if ( empty( $option_names ) ) {
			return [];
		}

		return array_fill_keys( $option_names, true );
	}

	/**
	 * Clear all tracked options from the table.
	 *
	 * @return bool Whether tracking data and the incomplete flag were cleared.
	 */
	public static function clear_tracked_options() {
		global $wpdb;
		$lock = self::get_tracking_lock();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 1)', $lock ) ) ) {
			return false;
		}
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false !== $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::get_table_name() ) ) ) {
				\delete_option( 'aaa_option_optimizer_tracking_limited' );
				return ! \get_option( 'aaa_option_optimizer_tracking_limited', false );
			}
			return false;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
