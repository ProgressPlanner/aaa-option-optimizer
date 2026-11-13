<?php
/**
 * Protect essential WordPress options from optimizer actions.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry shared by the REST handlers and admin tables.
 */
class Protected_Options {
	/**
	 * Core option names protected against deletion and autoload changes.
	 *
	 * @return string[]
	 */
	private static function core_options() {
		return [
			// Site identity & URLs.
			'siteurl',
			'home',
			'blogname',
			'blogdescription',
			'blog_charset',
			'admin_email',
			'admin_email_lifespan',
			'new_admin_email',
			'WPLANG',
			'site_icon',

			// Active plugins/theme.
			'active_plugins',
			'template',
			'stylesheet',
			'current_theme',
			'template_root',
			'stylesheet_root',
			'recently_activated',
			'uninstall_plugins',

			// Permalinks & rewrite.
			'permalink_structure',
			'category_base',
			'tag_base',
			'rewrite_rules',
			'page_on_front',
			'page_for_posts',
			'show_on_front',

			// Users & roles.
			'users_can_register',
			'default_role',
			'wp_user_roles',

			// Date/time.
			'date_format',
			'time_format',
			'gmt_offset',
			'timezone_string',
			'start_of_week',
			'links_updated_date_format',

			// Reading.
			'posts_per_page',
			'posts_per_rss',
			'rss_use_excerpt',
			'blog_public',

			// Discussion.
			'default_pingback_flag',
			'default_ping_status',
			'default_comment_status',
			'comments_notify',
			'moderation_notify',
			'comment_moderation',
			'require_name_email',
			'comment_registration',
			'close_comments_for_old_posts',
			'close_comments_days_old',
			'thread_comments',
			'thread_comments_depth',
			'page_comments',
			'comments_per_page',
			'default_comments_page',
			'comment_order',
			'comment_max_links',
			'moderation_keys',
			'disallowed_keys',
			'avatar_default',
			'avatar_rating',
			'show_avatars',
			'comment_whitelist',
			'comment_previously_approved',

			// Media.
			'thumbnail_size_w',
			'thumbnail_size_h',
			'thumbnail_crop',
			'medium_size_w',
			'medium_size_h',
			'medium_large_size_w',
			'medium_large_size_h',
			'large_size_w',
			'large_size_h',
			'image_default_link_type',
			'image_default_size',
			'image_default_align',
			'uploads_use_yearmonth_folders',
			'upload_path',
			'upload_url_path',

			// Mail.
			'mailserver_url',
			'mailserver_port',
			'mailserver_login',
			'mailserver_pass',
			'default_email_category',

			// DB & infra.
			'db_version',
			'db_upgraded',
			'initial_db_version',
			'auto_core_update_notified',
			'auto_update_core_dev',
			'auto_update_core_minor',
			'auto_update_core_major',
			'cron',
			'fresh_site',

			// Theme/customizer.
			'theme_mods',
			'theme_switched',
			'widget_block',

			// Privacy.
			'wp_page_for_privacy_policy',

			// Misc core.
			'use_smilies',
			'use_balanceTags',
			'hack_file',
			'html_type',
			'category_children',
			'finished_splitting_shared_terms',
			'finished_updating_comment_type',
			'default_category',
			'default_post_format',
			'sidebars_widgets',
		];
	}


	/**
	 * Check core, blog-specific roles, and this plugin's own settings.
	 *
	 * @param string $option_name Option name.
	 * @return bool
	 */
	public static function is_protected( $option_name ) {
		global $wpdb;

		$names   = self::core_options();
		$names[] = $wpdb->prefix . 'user_roles';
		$names[] = 'alloptions';
		$names[] = 'notoptions';
		$names[] = 'option_optimizer';
		$names[] = 'aaa_option_optimizer_tracking_limited';

		return in_array( strtolower( $option_name ), array_map( 'strtolower', $names ), true );
	}

	/**
	 * Resolve database collation aliases before checking a mutation.
	 *
	 * @param string $option_name Submitted option name.
	 * @return bool
	 */
	public static function is_mutation_protected( $option_name ) {
		global $wpdb;

		if ( self::is_protected( $option_name ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$stored_name = $wpdb->get_var( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s", $option_name ) );
		return null !== $stored_name && self::is_protected( $stored_name );
	}
}
