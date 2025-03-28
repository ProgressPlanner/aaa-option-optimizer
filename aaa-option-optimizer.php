<?php
/**
 * Plugin that tracks post meta usage and allows the user to optimize them.
 *
 * @package Emilia\MetaOptimizer
 *
 * Plugin Name: AAA Meta Optimizer
 * Plugin URI: https://joost.blog/plugins/aaa-meta-optimizer/
 * Description: Tracks post meta usage and allows the user to optimize them.
 * Version: 1.0
 * License: GPL-3.0+
 * Author: Joost de Valk
 * Author URI: https://joost.blog/
 * Text Domain: aaa-meta-optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'AAA_META_OPTIMIZER_FILE', __FILE__ );
define( 'AAA_META_OPTIMIZER_DIR', __DIR__ );

require_once __DIR__ . '/src/autoload.php';

register_activation_hook( __FILE__, 'aaa_meta_optimizer_activation' );
register_deactivation_hook( __FILE__, 'aaa_meta_optimizer_deactivation' );

/**
 * Activation hooked function to store start stats.
 *
 * @return void
 */
function aaa_meta_optimizer_activation() {

	update_option(
		'meta_optimizer',
		[
			'starting_point_date' => current_time( 'mysql' ),
			'used_meta_fields'    => [],
		],
		true
	);
}

/**
 * Deactivation hooked function to remove autoload from the plugin option.
 *
 * @return void
 */
function aaa_meta_optimizer_deactivation() {
	$aaa_option_value = get_option( 'meta_optimizer' );
	update_option( 'meta_optimizer', $aaa_option_value, false );
}

/**
 * Initializes the plugin.
 *
 * @return void
 */
function aaa_meta_optimizer_init() {
	$optimizer = new Emilia\MetaOptimizer\Plugin();
	$optimizer->register_hooks();
}

aaa_meta_optimizer_init();
