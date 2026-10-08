<?php
/**
 * Uninstall the plugin.
 *
 * Delete the plugin option and custom table.
 *
 * @package Progress_Planner\OptionOptimizer
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop the custom table.
$aaa_option_optimizer_table = $wpdb->prefix . 'option_optimizer_tracked';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is safe (from constant prefix).
$wpdb->query( "DROP TABLE IF EXISTS {$aaa_option_optimizer_table}" );

// Delete the batch transient.
delete_transient( 'option_optimizer_batch' );

// Delete the plugin option.
delete_option( 'option_optimizer' );

// Delete the cached known-plugins mapping.
delete_option( 'aaa_option_optimizer_known_plugins' );

// Clear the daily refresh cron event (defense-in-depth; deactivation already does this).
wp_clear_scheduled_hook( 'aaa_option_optimizer_refresh_known_plugins' );
