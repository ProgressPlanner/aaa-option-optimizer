<?php
/**
 * AAA Option Optimizer Settings Page
 *
 * @package Emilia\OptionOptimizer
 */

namespace Emilia\OptionOptimizer;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Settings
 */
class Settings_Page {

	/**
	 * Option name for settings
	 */
	const OPTION_NAME = 'option_optimizer';

	/**
	 * Initialize the settings
	 */
	public static function register_hooks() {
		add_action( 'admin_menu', [ __CLASS__, 'add_settings_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
	}

	/**
	 * Add settings page to Tools menu
	 */
	public static function add_settings_page(): void {
		add_submenu_page(
			'tools.php',
			__( 'Option Optimizer Settings', 'aaa-option-optimizer' ),
			__( 'Settings', 'aaa-option-optimizer' ),
			'manage_options',
			'aaa-option-optimizer-settings',
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	/**
	 * Register settings
	 */
	public static function register_settings(): void {
		register_setting(
			'aaa_option_optimizer_settings_group',
			self::OPTION_NAME,
			[
				'sanitize_callback' => [ __CLASS__, 'sanitize_settings' ],
			]
		);

		add_settings_section(
			'aaa_option_optimizer_tracking_section',
			__( 'Option tracking', 'aaa-option-optimizer' ),
			[ __CLASS__, 'render_tracking_section' ],
			'aaa-option-optimizer-settings'
		);

		add_settings_section(
			'aaa_option_optimizer_stats_section',
			__( 'Stats', 'aaa-option-optimizer' ),
			[ __CLASS__, 'render_stats_section' ],
			'aaa-option-optimizer-settings'
		);
	}

	/**
	 * Render settings page
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check if settings were saved.
		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Nonce check not needed here.
			add_settings_error(
				'aaa_option_optimizer_messages',
				'aaa_option_optimizer_message',
				esc_html__( 'Settings saved.', 'aaa-option-optimizer' ),
				'updated'
			);
		}

		settings_errors( 'aaa_option_optimizer_messages' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'aaa_option_optimizer_settings_group' );
				do_settings_sections( 'aaa-option-optimizer-settings' );
				submit_button( __( 'Save Settings', 'aaa-option-optimizer' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render tracking section
	 */
	public static function render_tracking_section(): void {
		$settings = self::get_settings();
		?>
		<p><?php \esc_html_e( 'Configure how options are tracked on your site.', 'aaa-option-optimizer' ); ?></p>
		<fieldset>
		<label for="aaa_option_optimizer_tracking_pre_option">
			<input type="radio" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[settings][option_tracking]" value="pre_option" id="aaa_option_optimizer_tracking_pre_option" <?php checked( $settings['option_tracking'], 'pre_option' ); ?>>
				<?php \esc_html_e( 'Pre option', 'aaa-option-optimizer' ); ?>
			</input>
		</label>
		<br>
		<label for="aaa_option_optimizer_tracking_legacy">
			<input type="radio" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[settings][option_tracking]" value="legacy" id="aaa_option_optimizer_tracking_legacy" <?php checked( $settings['option_tracking'], 'legacy' ); ?>>
				<?php \esc_html_e( 'Legacy', 'aaa-option-optimizer' ); ?>
			</input>
		</label>
		</fieldset>
		<?php
	}

	/**
	 * Render stats section description
	 */
	public static function render_stats_section(): void {
		$option_optimizer = \get_option( 'option_optimizer', [ 'used_options' => [] ] );

		global $wpdb;
		$autoload_values = \wp_autoload_values_to_autoload();
		$placeholders    = \implode( ',', \array_fill( 0, \count( $autoload_values ), '%s' ) );

		// phpcs:disable WordPress.DB
		$result = $wpdb->get_row(
			$wpdb->prepare( "SELECT count(*) AS count, SUM( LENGTH( option_value ) ) as autoload_size FROM {$wpdb->options} WHERE autoload IN ( $placeholders )", $autoload_values )
		);
		// phpcs:enable WordPress.DB
		?>
		<p>
			<?php
			printf(
				// translators: %1$s is the date, %2$s is the number of options at stat, %3$s is the size at start in KB, %4$s is the number of options now, %5$s is the size in KB now.
				\esc_html__( 'When you started on %1$s you had %2$s autoloaded options, for %3$sKB of memory. Now you have %4$s options, for %5$sKB of memory.', 'aaa-option-optimizer' ),
				\esc_html( \gmdate( 'Y-m-d', \strtotime( $option_optimizer['starting_point_date'] ) ) ),
				isset( $option_optimizer['starting_point_num'] ) ? \esc_html( $option_optimizer['starting_point_num'] ) : '-',
				\number_format( ( $option_optimizer['starting_point_kb'] ), 1 ),
				\esc_html( $result->count ),
				\number_format( ( $result->autoload_size / 1024 ), 1 )
			);
			?>
		</p>
		<?php

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is used for REST API.
		if ( isset( $_GET['tracking_reset'] ) && $_GET['tracking_reset'] === 'true' ) :
			?>
				<div class="notice notice-success is-dismissible">
					<p><?php \esc_html_e( 'Tracking data has been reset.', 'aaa-option-optimizer' ); ?></p>
				</div>
				<?php // Take the parameter out of the URL without reloading the page. ?>
				<script>window.history.pushState({}, document.title, window.location.href.replace( '&tracking_reset=true', '' ) );</script>
			<?php endif; ?>

			<div class="aaa-option-optimizer-reset" style="float: none;">
				<button id="aaa-option-reset-data" class="button button-delete reset-data" type="button">
					<?php \esc_html_e( 'Reset data', 'aaa-option-optimizer' ); ?>
				</button>
			</div>
		<?php
	}

	/**
	 * Sanitize settings
	 *
	 * This merges the settings into the existing option_optimizer option structure.
	 *
	 * @param array<string, mixed> $input Settings input.
	 * @return array<string, mixed> Sanitized settings merged with existing option data.
	 */
	public static function sanitize_settings( $input ): array {
		// Get the existing option_optimizer data to preserve other keys.
		$existing = get_option( self::OPTION_NAME, [] );

		// Initialize settings array if it doesn't exist.
		if ( ! isset( $existing['settings'] ) ) {
			$existing['settings'] = [];
		}

		// Sanitize the option_tracking setting.
		$option_tracking = 'legacy';
		if ( isset( $input['settings']['option_tracking'] ) ) {
			$input_option_tracking = \sanitize_text_field( $input['settings']['option_tracking'] );
			if ( \in_array( $input_option_tracking, [ 'pre_option', 'legacy' ], true ) ) {
				$option_tracking = $input_option_tracking;
			}
		}
		$existing['settings']['option_tracking'] = $option_tracking;

		// Return the full option structure with merged settings.
		return $existing;
	}

	/**
	 * Get settings
	 *
	 * @return array<string, mixed> Settings from the settings subarray.
	 */
	public static function get_settings(): array {
		$defaults = [
			'option_tracking' => 'legacy',
		];

		$option_optimizer = get_option( self::OPTION_NAME, [] );
		$settings         = isset( $option_optimizer['settings'] ) ? $option_optimizer['settings'] : [];

		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Get option tracking
	 *
	 * @return string Option tracking.
	 */
	public static function get_option_tracking(): string {
		$settings = self::get_settings();
		return $settings['option_tracking'] ?? 'legacy';
	}
}
