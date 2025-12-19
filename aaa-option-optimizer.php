<?php
/**
 * Plugin that tracks autoloaded options usage and allows the user to optimize them.
 *
 * @package Progress_Planner\OptionOptimizer
 *
 * Plugin Name: AAA Option Optimizer
 * Plugin URI: https://progressplanner.com/plugins/aaa-option-optimizer/
 * Description: Tracks autoloaded options usage and allows the user to optimize them.
 * Version: 1.5.1
 * License: GPL-3.0+
 * Author: Team Prospress Planner
 * Author URI: https://prospressplanner.com/
 * Text Domain: aaa-option-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'AAA_OPTION_OPTIMIZER_FILE', __FILE__ );
define( 'AAA_OPTION_OPTIMIZER_DIR', __DIR__ );

require_once __DIR__ . '/src/autoload.php';

register_activation_hook( __FILE__, 'aaa_option_optimizer_activation' );
register_deactivation_hook( __FILE__, 'aaa_option_optimizer_deactivation' );

/**
 * Activation hooked function to store start stats and create table.
 *
 * @return void
 */
function aaa_option_optimizer_activation() {
	global $wpdb;

	// Create the custom table.
	Progress_Planner\OptionOptimizer\Database::create_table();

	// Migrate existing data if present.
	Progress_Planner\OptionOptimizer\Database::maybe_migrate();

	$autoload_values = \wp_autoload_values_to_autoload();
	$placeholders    = implode( ',', array_fill( 0, count( $autoload_values ), '%s' ) );

	// phpcs:disable WordPress.DB
	$result = $wpdb->get_row(
		$wpdb->prepare( "SELECT count(*) AS count, SUM( LENGTH( option_value ) ) as autoload_size FROM {$wpdb->options} WHERE autoload IN ( $placeholders )", $autoload_values )
	);
	// phpcs:enable WordPress.DB

	// Only set starting point if not already set (preserve existing data).
	$existing = get_option( 'option_optimizer' );
	if ( empty( $existing['starting_point_date'] ) ) {
		update_option(
			'option_optimizer',
			[
				'starting_point_kb'   => ( $result->autoload_size / 1024 ),
				'starting_point_num'  => $result->count,
				'starting_point_date' => current_time( 'mysql' ),
				'settings'            => [
					'option_tracking' => 'pre_option',
				],
			],
			false
		);
	}
}

/**
 * Deactivation hooked function to remove autoload from the plugin option.
 *
 * @return void
 */
function aaa_option_optimizer_deactivation() {
	$aaa_option_value = get_option( 'option_optimizer' );
	update_option( 'option_optimizer', $aaa_option_value, false );
}

/**
 * Ensure database table exists and migrate data if needed.
 * Runs on plugins_loaded to handle existing installs that don't trigger activation.
 *
 * @return void
 */
function aaa_option_optimizer_maybe_upgrade() {
	// Check if table exists, create if not.
	if ( ! Progress_Planner\OptionOptimizer\Database::table_exists() ) {
		Progress_Planner\OptionOptimizer\Database::create_table();
	}

	// Migrate existing data if present.
	Progress_Planner\OptionOptimizer\Database::maybe_migrate();
}
add_action( 'plugins_loaded', 'aaa_option_optimizer_maybe_upgrade' );

/**
 * Initializes the plugin.
 *
 * @return void
 */
function aaa_option_optimizer_init() {
	$optimizer = new Progress_Planner\OptionOptimizer\Plugin();
	$optimizer->register_hooks();
}

aaa_option_optimizer_init();
