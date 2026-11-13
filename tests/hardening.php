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

foreach ( [ 'siteurl', 'SITEURL', 'sitéurl', 'active_plugins', $wpdb->prefix . 'user_roles', 'alloptions', 'notoptions' ] as $protected ) {
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

// Hold the lock on a separate connection to exercise real lock timeouts.
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
$other_connection = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$lock             = 'aaaoo_' . md5( DB_NAME . $table );
check( '1' === (string) $other_connection->get_var( $other_connection->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ), 'Acquire the contention fixture lock.' );
try {
	$response = request( 'migrate' );
	check( 503 === $response->get_status() && false === $response->get_data()['success'], 'Migration lock timeout must report failure.' );
	check( 2 === $response->get_data()['remaining'], 'Failed migration must report retained observations.' );
	check( $source === get_option( 'option_optimizer' ), 'Lock timeout must preserve migration source.' );
	check( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), 'Failed migration must not insert rows.' );
	check( 503 === request( 'reset' )->get_status(), 'Reset lock timeout must report failure.' );
	check( 2 === (int) $wpdb->get_var( "SELECT access_count FROM $table WHERE option_name = 'before_reset'" ), 'Failed reset must retain tracking rows.' );
	check( Database::is_tracking_limited(), 'Failed reset must retain incomplete state.' );
} finally {
	$other_connection->get_var( $other_connection->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	$other_connection->close();
}

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

Database::clear_tracked_options();
$observations = [];
for ( $index = 0; $index < Database::MAX_TRACKED_OPTIONS + 1; ++$index ) {
	$observations[ 'dynamic_' . $index ] = 1;
}
Database::batch_insert( $observations );
$table = Database::get_table_name();
check( Database::MAX_TRACKED_OPTIONS === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), 'Tracking storage bound.' );
check( Database::is_tracking_limited(), 'Overflow must mark observations incomplete.' );
check( [] === request( 'unused-options', [], 'GET' )->get_data()['data'], 'Do not recommend deletions from incomplete observations.' );
Database::batch_insert(
	[
		'dynamic_0'       => 3,
		'another_missing' => 1,
	]
);
check( 4 === (int) $wpdb->get_var( "SELECT access_count FROM $table WHERE option_name = 'dynamic_0'" ), 'Existing counts still update at capacity.' );
check( Database::MAX_TRACKED_OPTIONS === count( Database::get_tracked_options() ), 'Bounded reads.' );
// A partial migration removes only committed keys and stops automatic retries.
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
check( 503 === $response->get_status() && 1 === $response->get_data()['remaining'], 'Full storage must report a partial migration.' );
check( [ 'zz_unmigrated' => 3 ] === get_option( 'option_optimizer' )['used_options'], 'Retain only unsaved observations after a partial migration.' );
check( 11 === (int) $wpdb->get_var( "SELECT access_count FROM $table WHERE option_name = 'dynamic_0'" ), 'Commit the existing observation once.' );
check( 503 === request( 'migrate' )->get_status(), 'Retry while full must report failure.' );
check( 11 === (int) $wpdb->get_var( "SELECT access_count FROM $table WHERE option_name = 'dynamic_0'" ), 'Retry must not replay already migrated observations.' );

Database::clear_tracked_options();
check( ! Database::is_tracking_limited(), 'Reset clears incomplete state.' );

// An old oversized table is pruned on the next write.
$wpdb->query( "INSERT INTO $table (option_name, access_count) VALUES ('legacy_extra', 1)" );
Database::batch_insert( $observations );
$wpdb->query( "INSERT INTO $table (option_name, access_count) VALUES ('legacy_extra_two', 1)" );
Database::batch_insert( [ 'legacy_extra' => 1 ] );
check( Database::MAX_TRACKED_OPTIONS === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), 'Prune legacy oversized storage.' );
Database::clear_tracked_options();

$plugin = new Plugin();
for ( $index = 0; $index < 1001; ++$index ) {
	$plugin->monitor_option_accesses_pre_option( false, 'request_' . $index );
}
$_GET['page'] = 'aaa-option-optimizer';
$plugin->update_tracked_options();
check( Database::is_tracking_limited(), 'Request cap must be visible.' );
check( 1000 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), 'Frontend page parameter must not suppress tracking.' );
$_GET = [];

\WP_CLI::success( 'Hardening integration regressions passed.' );
