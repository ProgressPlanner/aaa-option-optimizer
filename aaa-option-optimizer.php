<?php
/**
 * Plugin that tracks autoloaded options usage and allows the user to optimize them.
 *
 * @package Emilia\OptionOptimizer
 *
 * Plugin Name: AAA Option Optimizer
 * Plugin URI: https://joost.blog/plugins/aaa-option-optimizer/
 * Description: Tracks autoloaded options usage and allows the user to optimize them.
 * Version: 1.2.1
 * License: GPL-3.0+
 * Author: Joost de Valk
 * Author URI: https://joost.blog/
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
 * Activation hooked function to store start stats.
 *
 * @return void
 */
function aaa_option_optimizer_activation() {
	global $wpdb;
	//phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one time query, no caching needed.
	$result = $wpdb->get_row( "SELECT count(*) AS count, SUM(LENGTH(option_value)) as autoload_size FROM {$wpdb->options} WHERE autoload='yes'" );
	update_option(
		'option_optimizer',
		[
			'starting_point_kb'   => ( $result->autoload_size / 1024 ),
			'starting_point_num'  => $result->count,
			'starting_point_date' => current_time( 'mysql' ),
			'used_options'        => [],
		],
		true
	);
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
 * Initializes the plugin.
 *
 * @return void
 */
function aaa_option_optimizer_init() {
	$optimizer = new Emilia\OptionOptimizer\Plugin();
	$optimizer->register_hooks();
}

aaa_option_optimizer_init();
