<?php
/**
 * Uninstall the plugin.
 *
 * Remove autoload from the plugin option.
 *
 * @package Progress_Planner
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove autoload from the plugin option.
$aaa_option_value = get_option( 'option_optimizer' );
update_option( 'option_optimizer', $aaa_option_value, false );