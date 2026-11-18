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

			// Theme/customizer. The active theme's mods are added in names().
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

			// Salts and keys, stored here when not defined in wp-config.php. Deleting them logs everyone out.
			'auth_key',
			'auth_salt',
			'secure_auth_key',
			'secure_auth_salt',
			'logged_in_key',
			'logged_in_salt',
			'nonce_key',
			'nonce_salt',
		];
	}

	/**
	 * Lowercased static names, built once per request.
	 *
	 * @var array<string, true>|null
	 */
	private static $static_names = null;

	/**
	 * Lowercased protected names, keyed for lookups.
	 *
	 * Names that depend on the current site (roles prefix, active theme) are added per call so
	 * the map stays correct after switch_to_blog() or a theme switch within the request.
	 *
	 * @return array<string, true>
	 */
	private static function names() {
		global $wpdb;

		if ( null === self::$static_names ) {
			$names   = self::core_options();
			$names[] = 'alloptions';
			$names[] = 'notoptions';
			$names[] = 'option_optimizer';
			$names[] = 'aaa_option_optimizer_tracking_limited';

			self::$static_names = array_fill_keys( array_map( 'strtolower', $names ), true );
		}

		$names = self::$static_names;
		$names[ strtolower( $wpdb->prefix . 'user_roles' ) ]      = true;
		$names[ strtolower( 'theme_mods_' . \get_stylesheet() ) ] = true;
		return $names;
	}


	/**
	 * Check core, blog-specific roles, the active theme's mods, and this plugin's own settings.
	 *
	 * @param string $option_name Option name.
	 * @return bool
	 */
	public static function is_protected( $option_name ) {
		return isset( self::names()[ strtolower( $option_name ) ] );
	}

	/**
	 * Resolve database collation aliases before checking a mutation.
	 *
	 * @param string $option_name Submitted option name.
	 * @return bool
	 */
	public static function is_mutation_protected( $option_name ) {
		return [] !== self::get_mutation_protected( [ $option_name ] );
	}

	/**
	 * Return the submitted names that are protected, directly or through a collation alias.
	 *
	 * Resolves every name with a single query, so bulk actions don't run one query per name.
	 *
	 * @param string[] $option_names Submitted option names.
	 * @return string[] Protected submitted names.
	 */
	public static function get_mutation_protected( array $option_names ) {
		global $wpdb;

		$protected = array_values( array_filter( $option_names, [ self::class, 'is_protected' ] ) );
		if ( $protected ) {
			return $protected;
		}
		if ( ! $option_names ) {
			return [];
		}

		$slots = implode( ',', array_fill( 0, count( $option_names ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are generated above.
		$stored = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ($slots)", array_values( $option_names ) ) );

		// The database matched these rows by collation, so the stored name stands in for the submitted one.
		return array_values( array_filter( $stored, [ self::class, 'is_protected' ] ) );
	}
}
