<?php
/**
 * Integration regressions. Run with wp eval-file against a disposable site.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer;

// phpcs:disable WordPress.DB, WordPress.Security.NonceVerification, WordPress.WP.GlobalVariablesOverride, WordPress.NamingConventions.PrefixAllGlobals
// Disposable fixtures intentionally exercise raw database rows and request globals.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'AAAOO_DISPOSABLE_TEST_SITE' ) || ! AAAOO_DISPOSABLE_TEST_SITE ) {
	exit( 'This test requires an explicitly disposable WP-CLI test site.' );
}

require_once dirname( __DIR__ ) . '/aaa-option-optimizer.php';
Database::create_table();
// Start from empty tracking data, so a previous failed run (e.g. a full table) can't skew results.
Database::clear_tracked_options();
wp_set_current_user( 1 );
rest_get_server();

/**
 * Fail on a regression.
 *
 * @param bool   $condition Expected truth.
 * @param string $message Assertion description.
 * @return void
 * @throws \RuntimeException When an assertion fails.
 */
function check( $condition, $message ) {
	if ( ! $condition ) {
		throw new \RuntimeException( esc_html( $message ) );
	}
}

/**
 * Dispatch through real REST validation and permission callbacks.
 *
 * @param string $route Route suffix.
 * @param array  $params Request parameters.
 * @param string $method HTTP method.
 * @return \WP_REST_Response
 */
function request( $route, $params = [], $method = 'POST' ) {
	$request = new \WP_REST_Request( $method, '/aaa-option-optimizer/v1/' . $route );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $params ) );
	return rest_do_request( $request );
}

global $wpdb;
$names = [ '123', 'true', '["foo"]', 'a  b', 'a<b>c', 'literal%20name', 'quote\'"\\name', 'é😀' ];
foreach ( $names as $name ) {
	add_option( $name, [ 'raw' => 'unchanged' ], '', false );
	$before = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", $name ) );
	add_filter(
		'option_' . $name,
		static function () {
			return 'filtered';
		}
	);
	check(
		200 === request(
			'update-autoload',
			[
				'option_name' => $name,
				'autoload'    => 'yes',
			]
		)->get_status(),
		'Exact-name autoload: ' . $name
	);
	check( $before === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", $name ) ), 'Autoload changed raw value: ' . $name );
	check(
		200 === request(
			'update-autoload',
			[
				'option_name' => $name,
				'autoload'    => 'yes',
			]
		)->get_status(),
		'Unchanged state should succeed.'
	);
}
check(
	200 === request(
		'set-autoload-options',
		[
			'option_names' => $names,
			'autoload'     => 'no',
		]
	)->get_status(),
	'Bulk autoload.'
);
// List responses must preserve the serialized bytes already stored in wp_options.
Database::batch_insert( array_fill_keys( $names, 1 ) );
// List routes paginate from $_GET (25 rows by default); fetch one full page so fixtures are present on busy sites.
$_GET          = [ 'length' => 500 ];
$listed_values = array_column( request( 'used-not-autoloaded-options', [], 'GET' )->get_data()['data'], 'value', 'name' );
$_GET          = [];
foreach ( $names as $name ) {
	$stored_value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", $name ) );
	check( $stored_value === ( $listed_values[ $name ] ?? null ), 'List response must not serialize the raw value again: ' . $name );
}
add_option( 'aaaoo_false', false, '', false );
check(
	200 === request(
		'update-autoload',
		[
			'option_name' => 'aaaoo_false',
			'autoload'    => 'yes',
		]
	)->get_status(),
	'False-valued option must be supported.'
);
check(
	400 === request(
		'update-autoload',
		[
			'option_name' => 'aaaoo_missing',
			'autoload'    => 'yes',
		]
	)->get_status(),
	'Missing option must fail.'
);

// Failed SQL must leave the original row and bytes intact.
$fail_update = static function ( $query ) use ( $wpdb ) {
	return str_starts_with( $query, "UPDATE $wpdb->options SET autoload" ) ? 'UPDATE aaaoo_nonexistent_table SET autoload = 1' : $query;
};
$before      = $wpdb->get_row( "SELECT * FROM $wpdb->options WHERE option_name = 'aaaoo_false'", ARRAY_A );
$wpdb->suppress_errors( true );
add_filter( 'query', $fail_update );
check(
	400 === request(
		'update-autoload',
		[
			'option_name' => 'aaaoo_false',
			'autoload'    => 'no',
		]
	)->get_status(),
	'Failed update must report failure.'
);
remove_filter( 'query', $fail_update );
$wpdb->suppress_errors( false );
check( $before === $wpdb->get_row( "SELECT * FROM $wpdb->options WHERE option_name = 'aaaoo_false'", ARRAY_A ), 'Failed update must preserve row.' );

foreach ( [ 123, true, [], [ 'foo' ], '', ' siteurl', 'siteurl ', str_repeat( 'a', 192 ) ] as $bad ) {
	foreach ( [ 'delete-option', 'create-option-false', 'update-autoload' ] as $route ) {
		check(
			400 === request(
				$route,
				[
					'option_name' => $bad,
					'autoload'    => 'yes',
				]
			)->get_status(),
			'Reject malformed single identifier.'
		);
	}
}
foreach ( [ 'one,two', [], [ 123 ], [ true ], [ [ 'foo' ] ], [ 'key' => 'foo' ], [ 'foo', '' ], array_fill( 0, 501, 'foo' ) ] as $bad ) {
	foreach ( [ 'delete-options', 'set-autoload-options' ] as $route ) {
		check(
			400 === request(
				$route,
				[
					'option_names' => $bad,
					'autoload'     => 'yes',
				]
			)->get_status(),
			'Reject malformed bulk identifiers.'
		);
	}
}
foreach ( [ [], true, 'invalid' ] as $bad ) {
	check(
		400 === request(
			'set-autoload-options',
			[
				'option_names' => [ 'aaaoo_false' ],
				'autoload'     => $bad,
			]
		)->get_status(),
		'Reject malformed autoload state.'
	);
}

foreach ( [ 'siteurl', 'SITEURL', 'sitéurl', 'active_plugins', $wpdb->prefix . 'user_roles', 'alloptions', 'notoptions', 'theme_mods_' . get_stylesheet(), strtoupper( 'theme_mods_' . get_stylesheet() ), 'auth_salt', 'NONCE_KEY', 'logged_in_salt' ] as $protected ) {
	// Alias folding depends on the database: MySQL matches 'sitéurl' to 'siteurl', SQLite doesn't.
	// Where the engine doesn't fold it, the name is a different, unprotected option and is skipped.
	$resolved = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name = %s", $protected ) );
	if ( ! Protected_Options::is_protected( $protected ) && ( null === $resolved || ! Protected_Options::is_protected( $resolved ) ) ) {
		continue;
	}
	foreach ( [ 'delete-option', 'create-option-false', 'update-autoload' ] as $route ) {
		check(
			403 === request(
				$route,
				[
					'option_name' => $protected,
					'autoload'    => 'no',
				]
			)->get_status(),
			'Protect critical identifier: ' . $protected
		);
	}
	foreach ( [ 'delete-options', 'set-autoload-options' ] as $route ) {
		check(
			403 === request(
				$route,
				[
					'option_names' => [ 'aaaoo_false', $protected ],
					'autoload'     => 'no',
				]
			)->get_status(),
			'Preflight protected batch.'
		);
		check( null !== $wpdb->get_var( "SELECT option_name FROM $wpdb->options WHERE option_name = 'aaaoo_false'" ), 'Protected batch deleted an earlier option.' );
	}
}
// Leftover mods of an inactive theme stay removable; only the active theme's are protected.
add_option( 'theme_mods_aaaoo_inactive_theme', [ 'header_textcolor' => 'fff' ], '', false );
check( ! Protected_Options::is_protected( 'theme_mods' ), 'The bare theme_mods name is not a core option.' );
check( 200 === request( 'delete-option', [ 'option_name' => 'theme_mods_aaaoo_inactive_theme' ] )->get_status(), 'Inactive theme mods are removable.' );
check(
	200 === request(
		'set-autoload-options',
		[
			'option_names' => [ 'aaaoo_false' ],
			'autoload'     => 'no',
		]
	)->get_status(),
	'Unprotected batch still passes the batch preflight.'
);

wp_set_current_user( 0 );
check( 401 === request( 'delete-option', [ 'option_name' => 'aaaoo_false' ] )->get_status(), 'Anonymous mutation denied.' );
wp_set_current_user( 1 );
check( 200 === request( 'delete-options', [ 'option_names' => $names ] )->get_status(), 'Authenticated bulk route needs no extra nonce.' );
foreach ( $names as $name ) {
	check( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name = %s", $name ) ), 'Exact-name delete: ' . $name );
}

$rest = new REST();
$_GET = [
	'order'   => [
		[
			'column' => [ 'bad' ],
			'dir'    => [ 'bad' ],
		],
	],
	'columns' => [],
];
check( [ 'name', SORT_ASC ] === $rest->get_sort_params(), 'Array sort index must not throw.' );
foreach ( [ 'DESC', 'Desc', 'desc' ] as $dir ) {
	$_GET = [
		'order'   => [
			[
				'column' => '0',
				'dir'    => $dir,
			],
		],
		'columns' => [ [ 'data' => 'size' ] ],
	];
	check( [ 'size', SORT_DESC ] === $rest->get_sort_params(), 'Sort direction is case-insensitive: ' . $dir );
}
$_GET = [
	'order'   => [
		[
			'column' => '0',
			'dir'    => 'desc',
		],
	],
	'columns' => [ [ 'data' => 'size' ] ],
];
check( [ 'size', SORT_DESC ] === $rest->get_sort_params(), 'Valid sort without redundant nonce.' );
$_GET = [];

Database::clear_tracked_options();
Database::batch_insert( [ 'before_reset' => 2 ] );
Database::mark_tracking_limited();
$table  = Database::get_table_name();
$source = [
	'used_options' => [
		'migration_one' => 7,
		'migration_two' => 3,
	],
];
update_option( 'option_optimizer', $source, false );

// A concurrent connection holding the old GET_LOCK name must not block or fail tracking writes.
$other_connection = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$other_connection->get_var( $other_connection->prepare( 'SELECT GET_LOCK(%s, 0)', 'aaaoo_' . md5( DB_NAME . $table ) ) );
try {
	$started = microtime( true );
	check( [ 'lock_free' => 1 ] === Database::batch_insert( [ 'lock_free' => 1 ] ), 'Tracking writes take no global lock.' );
	check( microtime( true ) - $started < 0.5, 'Tracking writes never wait on a lock.' );
} finally {
	$other_connection->close();
}
$wpdb->query( "DELETE FROM $table WHERE option_name = 'lock_free'" );

$fail_tracking_insert = static function ( $query ) use ( $table ) {
	return str_starts_with( $query, "INSERT INTO $table " ) ? 'INSERT INTO aaaoo_missing_table VALUES (1)' : $query;
};
$wpdb->suppress_errors( true );
add_filter( 'query', $fail_tracking_insert );
try {
	check( 503 === request( 'migrate' )->get_status(), 'Failed tracking INSERT must report migration failure.' );
	check( $source === get_option( 'option_optimizer' ), 'Failed INSERT must preserve migration source.' );
} finally {
	remove_filter( 'query', $fail_tracking_insert );
	$wpdb->suppress_errors( false );
}
$response = request( 'migrate' );
check( 200 === $response->get_status() && 0 === $response->get_data()['remaining'], 'Migration retry must complete.' );
check( [] === get_option( 'option_optimizer' )['used_options'], 'Successful migration removes committed observations.' );
check( 7 === (int) $wpdb->get_var( "SELECT access_count FROM $table WHERE option_name = 'migration_one'" ), 'Retry must not duplicate failed migration counts.' );

$fail_tracking_reset = static function ( $query ) {
	return str_starts_with( $query, 'TRUNCATE TABLE ' ) ? 'TRUNCATE TABLE aaaoo_missing_table' : $query;
};
$wpdb->suppress_errors( true );
add_filter( 'query', $fail_tracking_reset );
try {
	check( 503 === request( 'reset' )->get_status(), 'Failed TRUNCATE must report reset failure.' );
	check( 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), 'Failed TRUNCATE must preserve tracking rows.' );
	check( Database::is_tracking_limited(), 'Failed TRUNCATE must preserve incomplete state.' );
} finally {
	remove_filter( 'query', $fail_tracking_reset );
	$wpdb->suppress_errors( false );
}
$reset_plugin = Plugin::get_instance();
$reset_plugin->monitor_option_accesses_pre_option( false, 'before_reset_request' );
check( 200 === request( 'reset' )->get_status(), 'Reset retry must succeed.' );
check( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), 'Successful reset must clear data before returning.' );
check( ! Database::is_tracking_limited(), 'Successful reset clears incomplete state.' );
$reset_plugin->monitor_option_accesses_pre_option( false, 'after_reset_request' );
$reset_plugin->update_tracked_options();
check( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), 'Shutdown must not repopulate a successful reset.' );

// A failed tracking write loses at most that request's counts; it must not pause recommendations.
Database::clear_tracked_options();
$wpdb->suppress_errors( true );
add_filter( 'query', $fail_tracking_insert );
try {
	check( false === Database::batch_insert( [ 'write_fails' => 1 ] ), 'Failed tracking INSERT reports failure.' );
	check( ! Database::is_tracking_limited(), 'A failed write must not mark tracking incomplete.' );
} finally {
	remove_filter( 'query', $fail_tracking_insert );
	$wpdb->suppress_errors( false );
}

// Capacity: new names beyond the limit are dropped and flagged; existing names keep counting.
$observations = [];
for ( $index = 0; $index < Database::MAX_TRACKED_OPTIONS; ++$index ) {
	$observations[ 'dynamic_' . $index ] = 1;
}
Database::batch_insert( $observations );
check( Database::MAX_TRACKED_OPTIONS === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), 'Fill storage to the limit.' );
check( ! Database::is_tracking_limited(), 'A full table alone, with nothing dropped, keeps recommendations.' );

// Names that match a stored row only by case or accents are existing rows, not new ones.
Database::batch_insert( [ 'DYNAMIC_0' => 2 ] );
check( 3 === (int) $wpdb->get_var( "SELECT access_count FROM $table WHERE option_name = 'dynamic_0'" ), 'Collation alias updates the existing row at capacity.' );
check( ! Database::is_tracking_limited(), 'Collation alias at capacity is not a dropped observation.' );
check( Database::MAX_TRACKED_OPTIONS === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), 'Collation alias adds no row.' );
// Non-ASCII names at capacity go through wpdb's charset check, which must still find the table.
$wpdb->query( "UPDATE $table SET option_name = 'é😀_stored' WHERE option_name = 'dynamic_9999'" );
check( [ 'é😀_STORED' => 1 ] === Database::batch_insert( [ 'é😀_STORED' => 1 ] ), 'Non-ASCII alias (ASCII case differs) at capacity is written, not failed.' );
check( 2 === (int) $wpdb->get_var( "SELECT access_count FROM $table WHERE option_name = 'é😀_stored'" ), 'Non-ASCII alias updates the existing row.' );
check( [] === Database::batch_insert( [ 'ñew_😀' => 1 ] ), 'New non-ASCII name at capacity is dropped, not failed.' );
check( Database::is_tracking_limited(), 'Dropped non-ASCII name marks observations incomplete.' );
delete_option( 'aaa_option_optimizer_tracking_limited' );

$written = Database::batch_insert(
	[
		'dynamic_1'       => 3,
		'another_missing' => 1,
	]
);
check( [ 'dynamic_1' => 3 ] === $written, 'Only the existing name is written at capacity.' );
check( 4 === (int) $wpdb->get_var( "SELECT access_count FROM $table WHERE option_name = 'dynamic_1'" ), 'Existing counts still update at capacity.' );
check( Database::is_tracking_limited(), 'A dropped new name marks observations incomplete.' );
check( [] === request( 'unused-options', [], 'GET' )->get_data()['data'], 'Do not recommend deletions from incomplete observations.' );

// Legacy data is migrated in full, even past the limit, so migration always finishes.
update_option(
	'option_optimizer',
	[
		'used_options' => [
			'dynamic_0'     => 7,
			'zz_unmigrated' => 3,
		],
	],
	false
);
$response = request( 'migrate' );
check( 200 === $response->get_status() && 0 === $response->get_data()['remaining'], 'Migration completes at capacity.' );
check( [] === get_option( 'option_optimizer' )['used_options'], 'Migration leaves no observations behind.' );
check( 10 === (int) $wpdb->get_var( "SELECT access_count FROM $table WHERE option_name = 'dynamic_0'" ), 'Commit the existing observation once.' );
check( 3 === (int) $wpdb->get_var( "SELECT access_count FROM $table WHERE option_name = 'zz_unmigrated'" ), 'Migrate the new observation past the limit.' );
check( 200 === request( 'migrate' )->get_status(), 'Retry after completion is a no-op.' );
check( 10 === (int) $wpdb->get_var( "SELECT access_count FROM $table WHERE option_name = 'dynamic_0'" ), 'Retry must not replay already migrated observations.' );

// An oversized table is never pruned: reads return every row, and core rows survive.
$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
check( Database::MAX_TRACKED_OPTIONS + 1 === $rows, 'Table may exceed the soft limit.' );
Database::batch_insert( [ 'dynamic_2' => 1 ] );
check( $rows === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), 'Writes never prune rows.' );
check( $rows === count( Database::get_tracked_options() ), 'Reads are not truncated at the limit.' );
check( $rows === count( Database::get_tracked_option_keys() ), 'Key reads are not truncated at the limit.' );

Database::clear_tracked_options();
check( ! Database::is_tracking_limited(), 'Reset clears incomplete state.' );

// A heavy request (well over 1,000 names) is normal and must not pause recommendations.
$plugin = new Plugin();
for ( $index = 0; $index < 3000; ++$index ) {
	$plugin->monitor_option_accesses_pre_option( false, 'request_' . $index );
}
$_GET['page'] = 'aaa-option-optimizer';
$plugin->update_tracked_options();
check( ! Database::is_tracking_limited(), 'A heavy request is not an incomplete observation.' );
check( 3000 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), 'Frontend page parameter must not suppress tracking.' );
$_GET = [];

// Only a request reading more names than storage holds is capped and flagged.
Database::clear_tracked_options();
$plugin = new Plugin();
for ( $index = 0; $index <= Database::MAX_TRACKED_OPTIONS; ++$index ) {
	$plugin->monitor_option_accesses_pre_option( false, 'huge_' . $index );
}
$plugin->update_tracked_options();
check( Database::is_tracking_limited(), 'Request cap must be visible.' );
check( Database::MAX_TRACKED_OPTIONS === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), 'Request cap bounds one request.' );
Database::clear_tracked_options();

\WP_CLI::success( 'Hardening integration regressions passed.' );
