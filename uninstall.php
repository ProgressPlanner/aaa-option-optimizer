<?php
/**
 * Uninstall the plugin.
 *
 * Delete the plugin option.
 *
 * @package Progress_Planner
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete the batch transient.
delete_transient( 'option_optimizer_batch' );

// Delete the plugin option.
delete_option( 'option_optimizer' );
