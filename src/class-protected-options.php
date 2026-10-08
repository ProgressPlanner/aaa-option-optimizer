<?php
/**
 * Protected options registry for AAA Option Optimizer.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer;

/**
 * Centralizes the list of options that must never be deleted or autoload-changed
 * through this plugin. Used by REST handlers and import flow.
 */
class Protected_Options {

	/**
	 * Cached protected option lookup map.
	 *
	 * @var array<string, true>|null
	 */
	private static $cache = null;

	/**
	 * Cached protected prefixes.
	 *
	 * @var string[]|null
	 */
	private static $prefix_cache = null;

	/**
	 * Get the canonical hardcoded list of WordPress core options that must be protected.
	 *
	 * Curated from wp-includes/option.php, wp-includes/default-constants.php, and the
	 * orpharion plugin's core options list. Order is not significant.
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
	 * Get the protected option prefixes.
	 *
	 * Options whose names start with any of these prefixes are protected:
	 *
	 * - 'option_optimizer'       — this plugin's own settings
	 * - 'aaa_option_optimizer'   — this plugin's transients/scheduled events
	 * - '_aaaoo_q__'             — quarantine prefix (defense in depth, even though
	 *                              the current design stores those in a custom table)
	 * - '_transient_'            — transient cache options
	 * - '_site_transient_'       — site-wide transient cache options
	 *
	 * @return string[]
	 */
	private static function core_prefixes() {
		if ( null === self::$prefix_cache ) {
			self::$prefix_cache = [
				'option_optimizer',
				'aaa_option_optimizer',
				'_aaaoo_q__',
				'_transient_',
				'_site_transient_',
			];
		}
		return self::$prefix_cache;
	}

	/**
	 * Get the full protected option lookup map.
	 *
	 * @return array<string, true>
	 */
	public static function get_protected_map() {
		if ( null === self::$cache ) {
			$options = self::core_options();

			/**
			 * Filter the list of protected option names.
			 *
			 * Use this filter to add must-not-delete options from MU plugins or
			 * other code that owns critical option keys. Prefix-based protection
			 * is handled separately via {@see is_protected()}.
			 *
			 * @param string[] $options Protected option names.
			 */
			$options = \apply_filters( 'aaa_option_optimizer_protected_options', $options );

			self::$cache = \array_fill_keys( \array_map( 'strval', $options ), true );
		}

		return self::$cache;
	}

	/**
	 * Check whether the given option name is protected.
	 *
	 * @param string $option_name Option name.
	 *
	 * @return bool
	 */
	public static function is_protected( $option_name ) {
		if ( '' === $option_name ) {
			return false;
		}

		if ( isset( self::get_protected_map()[ $option_name ] ) ) {
			return true;
		}

		foreach ( self::core_prefixes() as $prefix ) {
			if ( 0 === \strpos( $option_name, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reset internal caches.
	 *
	 * Useful when the filter callbacks change at runtime (e.g. tests).
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$cache        = null;
		self::$prefix_cache = null;
	}
}
