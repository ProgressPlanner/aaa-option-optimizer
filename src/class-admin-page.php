<?php
/**
 * Admin page functionality for AAA Option Optimizer.
 *
 * @package Progress_Planner\OptionOptimizer
 */

namespace Progress_Planner\OptionOptimizer;

/**
 * Admin page functionality for AAA Option Optimizer.
 */
class Admin_Page {

	/**
	 * Option name for settings.
	 */
	const OPTION_NAME = 'option_optimizer';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		// Register a link to the settings page on the plugins overview page.
		\add_filter( 'plugin_action_links', [ $this, 'filter_plugin_actions' ], 10, 2 );

		\add_action( 'admin_menu', [ $this, 'add_admin_page' ] );
		\add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		\add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		\register_setting(
			'aaa_option_optimizer_settings_group',
			self::OPTION_NAME,
			[
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
			]
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * This merges the settings into the existing option_optimizer option structure.
	 *
	 * @param array<string, mixed> $input Settings input.
	 * @return array<string, mixed> Sanitized settings merged with existing option data.
	 */
	public function sanitize_settings( $input ): array {
		// Get the existing option_optimizer data to preserve other keys.
		$existing = \get_option( self::OPTION_NAME, [] );

		// Initialize settings array if it doesn't exist.
		if ( ! isset( $existing['settings'] ) ) {
			$existing['settings'] = [];
		}

		// Sanitize the option_tracking setting.
		$option_tracking = 'pre_option';
		if ( isset( $input['settings']['option_tracking'] ) ) {
			$input_option_tracking = \sanitize_text_field( $input['settings']['option_tracking'] );
			if ( \in_array( $input_option_tracking, [ 'pre_option', 'legacy' ], true ) ) {
				$option_tracking = $input_option_tracking;
			}
		}
		$existing['settings']['option_tracking'] = $option_tracking;

		// Consent to contacting our servers (checkbox: present means granted).
		$existing['settings']['remote_data_consent'] = isset( $input['settings']['remote_data_consent'] )
			? (bool) $input['settings']['remote_data_consent']
			: false;

		// Return the full option structure with merged settings.
		return $existing;
	}

	/**
	 * Get settings.
	 *
	 * @return array<string, mixed> Settings from the settings subarray.
	 */
	public static function get_settings(): array {
		$defaults = [
			'option_tracking'     => 'pre_option',
			'remote_data_consent' => false,
		];

		$option_optimizer = \get_option( self::OPTION_NAME, [] );
		$settings         = isset( $option_optimizer['settings'] ) ? $option_optimizer['settings'] : [];

		return \wp_parse_args( $settings, $defaults );
	}

	/**
	 * Get the options this site has already reported.
	 *
	 * Reports are POSTed to an external endpoint which never reports back, so
	 * this record is only ever "we sent this", not "this was accepted". It is
	 * kept beside `settings` rather than inside it so the settings page, which
	 * saves that subarray wholesale, cannot drop it.
	 *
	 * @return array<string, array<string, string>> Reported options, keyed by option name.
	 */
	public static function get_reported_options(): array {
		$option   = \get_option( self::OPTION_NAME, [] );
		$reported = isset( $option['reported_options'] ) ? $option['reported_options'] : [];

		return \is_array( $reported ) ? $reported : [];
	}

	/**
	 * Record that an option has been reported from this site.
	 *
	 * Re-reporting is allowed -- a user who picked the wrong plugin needs a way
	 * to correct it -- so an existing entry is overwritten rather than kept.
	 *
	 * @param string $option_name The option that was reported.
	 * @param string $slug        The wp.org slug it was reported as.
	 * @param string $plugin_name The plugin name shown at verification time.
	 * @param string $prefix      The option prefix the report covered, if given.
	 *
	 * @return void
	 */
	public static function record_reported_option( string $option_name, string $slug, string $plugin_name, string $prefix = '' ): void {
		$option = \get_option( self::OPTION_NAME, [] );

		$reported = isset( $option['reported_options'] ) && \is_array( $option['reported_options'] )
			? $option['reported_options']
			: [];

		$reported[ $option_name ] = [
			'slug'     => $slug,
			'name'     => $plugin_name,
			'prefix'   => $prefix,
			'reported' => \gmdate( 'c' ),
		];

		$option['reported_options'] = $reported;
		\update_option( self::OPTION_NAME, $option );
	}

	/**
	 * The option prefixes this site has reported, and what they were reported as.
	 *
	 * A report that names a prefix is the user telling us which plugin owns a
	 * whole family of options. That is first-hand evidence about this site, so
	 * the sibling options can be prefilled from it while the submission waits
	 * for a maintainer to publish the prefix to the shared mapping.
	 *
	 * Longest prefix first, so a more specific report wins over a broader one.
	 *
	 * @return array<string, array<string, string>> Reported plugin data, keyed by prefix.
	 */
	public static function get_reported_prefixes(): array {
		$prefixes = [];

		foreach ( self::get_reported_options() as $record ) {
			if ( empty( $record['prefix'] ) || empty( $record['slug'] ) ) {
				continue;
			}

			$prefixes[ $record['prefix'] ] = [
				'slug' => $record['slug'],
				'name' => isset( $record['name'] ) ? $record['name'] : '',
			];
		}

		\uksort(
			$prefixes,
			static function ( $a, $b ) {
				return \strlen( (string) $b ) <=> \strlen( (string) $a );
			}
		);

		return $prefixes;
	}

	/**
	 * Get option tracking.
	 *
	 * @return string Option tracking.
	 */
	public static function get_option_tracking(): string {
		$settings = self::get_settings();
		return $settings['option_tracking'] ?? 'pre_option';
	}

	/**
	 * Register the settings link for the plugins page.
	 *
	 * @param array<string, string> $links The plugin action links.
	 * @param string                $file  The plugin file.
	 *
	 * @return array<string, string>
	 */
	public function filter_plugin_actions( $links, $file ): array {

		/* Static so we don't call plugin_basename on every plugin row. */
		static $this_plugin;
		if ( ! $this_plugin ) {
			$this_plugin = \plugin_basename( AAA_OPTION_OPTIMIZER_FILE );
		}

		if ( $file === $this_plugin ) {
			$settings_link = '<a href="' . \admin_url( 'tools.php?page=aaa-option-optimizer' ) . '">' . \__( 'Optimize Options', 'aaa-option-optimizer' ) . '</a>';
			// Put our link before other links.
			\array_unshift( $links, $settings_link );
		}

		return $links;
	}

	/**
	 * Adds the admin page under the Tools menu.
	 *
	 * @return void
	 */
	public function add_admin_page() {
		\add_management_page(
			__( 'AAA Option Optimizer', 'aaa-option-optimizer' ),
			__( 'Option Optimizer', 'aaa-option-optimizer' ),
			'manage_options',
			'aaa-option-optimizer',
			[ $this, 'render_admin_page_ajax' ]
		);
	}

	/**
	 * Enqueue our scripts.
	 *
	 * @param string $hook The current page hook.
	 *
	 * @return void
	 */
	public function enqueue_scripts( $hook ) {
		if ( $hook !== 'tools_page_aaa-option-optimizer' ) {
			return;
		}

		\wp_enqueue_style(
			'aaa-option-optimizer',
			plugin_dir_url( AAA_OPTION_OPTIMIZER_FILE ) . 'css/style.css',
			[],
			'2.0.1'
		);

		\wp_enqueue_script(
			'aaaoo-datatables',
			plugin_dir_url( AAA_OPTION_OPTIMIZER_FILE ) . 'js/vendor/datatables.min.js',
			[], // Dependencies.
			'2.3.2',
			true // In footer.
		);

		\wp_enqueue_style(
			'aaaoo-datatables',
			\plugin_dir_url( AAA_OPTION_OPTIMIZER_FILE ) . 'js/vendor/datatables.min.css',
			[],
			'2.3.2'
		);

		\wp_enqueue_script(
			'aaa-option-optimizer-admin-js',
			\plugin_dir_url( AAA_OPTION_OPTIMIZER_FILE ) . 'js/admin-script.js',
			[ 'jquery', 'aaaoo-datatables' ], // Dependencies.
			\filemtime( \plugin_dir_path( AAA_OPTION_OPTIMIZER_FILE ) . 'js/admin-script.js' ), // Version.
			true // In footer.
		);

		/**
		 * Filter the URL the plugin POSTs unknown-option reports to.
		 *
		 * @param string $url The submission endpoint URL.
		 */
		$report_url = \apply_filters( 'aaa_option_optimizer_report_url', 'https://option-optimizer-api.progressplanner.com/submit' );

		\wp_localize_script(
			'aaa-option-optimizer-admin-js',
			'aaaOptionOptimizer',
			[
				'root'             => \esc_url_raw( \rest_url() ),
				'nonce'            => \wp_create_nonce( 'wp_rest' ),
				'migration'        => Database::get_migration_status(),
				'reportUrl'        => \esc_url_raw( $report_url ),
				'hasRemoteConsent' => Known_Plugins::has_consent(),
				'reportedOptions'  => self::get_reported_options(),
				'reportedPrefixes' => self::get_reported_prefixes(),
				'installedPlugins' => $this->get_installed_plugins(),
				'i18n'             => [
					'filterBySource'         => \esc_html__( 'Filter by source', 'aaa-option-optimizer' ),
					'showValue'              => \esc_html__( 'Show', 'aaa-option-optimizer' ),
					'addAutoload'            => \esc_html__( 'Add autoload', 'aaa-option-optimizer' ),
					'removeAutoload'         => \esc_html__( 'Remove autoload', 'aaa-option-optimizer' ),
					'deleteOption'           => \esc_html__( 'Delete', 'aaa-option-optimizer' ),
					'createOptionFalse'      => \esc_html__( 'Create option with value false', 'aaa-option-optimizer' ),
					'unknownLabel'           => \esc_html__( 'Unknown', 'aaa-option-optimizer' ),
					'reportOrigin'           => \esc_html__( 'Report', 'aaa-option-optimizer' ),
					'reportReported'         => \esc_html__( 'Reported', 'aaa-option-optimizer' ),
					/* translators: %s: plugin name the option was reported as. */
					'reportReportedAs'       => \esc_html__( 'Reported as %s. Awaiting review -- submit again to correct it.', 'aaa-option-optimizer' ),
					'reportReportedPending'  => \esc_html__( 'Reported, awaiting review', 'aaa-option-optimizer' ),
					'reportOriginOf'         => \esc_html__( 'Report origin of', 'aaa-option-optimizer' ),
					'reportSlugOrUrlLabel'   => \esc_html__( 'wp.org slug or URL', 'aaa-option-optimizer' ),
					'reportSlugPlaceholder'  => \esc_html__( 'Pick an installed plugin, or type a slug or URL', 'aaa-option-optimizer' ),
					'reportSlugOrUrlHelp'    => \esc_html__( 'Choose from the installed plugins, or type the slug yourself if the plugin has been removed.', 'aaa-option-optimizer' ),
					'reportListLabel'        => \esc_html__( 'Installed plugins', 'aaa-option-optimizer' ),
					'reportNoMatches'        => \esc_html__( 'No installed plugin matches. Press Enter to use what you typed.', 'aaa-option-optimizer' ),
					/* translators: %d: number of matching plugins. */
					'reportMatchCount'       => \esc_html__( '%d plugins match', 'aaa-option-optimizer' ),
					'reportMatchCountOne'    => \esc_html__( '1 plugin matches', 'aaa-option-optimizer' ),
					'reportPrefixLabel'      => \esc_html__( 'Option prefix this plugin uses (optional)', 'aaa-option-optimizer' ),
					'reportPrefixHelp'       => \esc_html__( 'Optional. If this plugin names its options with a shared prefix, adding it lets one report cover all of them. Leave empty to report only this option.', 'aaa-option-optimizer' ),
					'reportVerifying'        => \esc_html__( 'Checking wp.org…', 'aaa-option-optimizer' ),
					'reportNotFound'         => \esc_html__( 'Plugin not found on wordpress.org.', 'aaa-option-optimizer' ),
					'reportVerifyError'      => \esc_html__( 'Could not verify with wordpress.org.', 'aaa-option-optimizer' ),
					'reportVerified'         => \esc_html__( 'Verified:', 'aaa-option-optimizer' ),
					'reportSubmit'           => \esc_html__( 'Submit', 'aaa-option-optimizer' ),
					'reportCancel'           => \esc_html__( 'Cancel', 'aaa-option-optimizer' ),
					'reportSubmitting'       => \esc_html__( 'Submitting…', 'aaa-option-optimizer' ),
					'reportThanks'           => \esc_html__( 'Thanks! Your report has been submitted.', 'aaa-option-optimizer' ),
					'reportFailed'           => \esc_html__( 'Submission failed. Please try again.', 'aaa-option-optimizer' ),
					'reportPrivacyNote'      => \esc_html__( 'We send the option name, the prefix and the slug you provide, and your site address. Never the option value.', 'aaa-option-optimizer' ),
					'reportConsentLabel'     => \esc_html__( 'I agree to send this report to option-optimizer-api.progressplanner.com, and to let the plugin keep the known-plugins list up to date daily.', 'aaa-option-optimizer' ),
					'noAutoloadedButNotUsed' => \esc_html__( 'All autoloaded options are in use.', 'aaa-option-optimizer' ),
					'noUsedButNotAutoloaded' => \esc_html__( 'All options that are used are autoloaded.', 'aaa-option-optimizer' ),
					'noOptionsSelected'      => \esc_html__( 'No options selected.', 'aaa-option-optimizer' ),
					'bulkActions'            => \esc_html__( 'Bulk actions', 'aaa-option-optimizer' ),
					'noBulkActionSelected'   => \esc_html__( 'No action selected.', 'aaa-option-optimizer' ),
					'delete'                 => \esc_html__( 'Delete', 'aaa-option-optimizer' ),
					'apply'                  => \esc_html__( 'Apply', 'aaa-option-optimizer' ),

					'search'                 => \esc_html__( 'Search:', 'aaa-option-optimizer' ),
					'migrating'              => \esc_html__( 'Migrating...', 'aaa-option-optimizer' ),
					'migrationComplete'      => \esc_html__( 'Migration complete! Reloading page...', 'aaa-option-optimizer' ),
					'migrationError'         => \esc_html__( 'Migration error. Please try again.', 'aaa-option-optimizer' ),
					/* translators: %1$d: number of migrated options, %2$d: total number of options */
					'migratedOf'             => \esc_html__( 'Migrated %1$d of %2$d options', 'aaa-option-optimizer' ),
					'entries'                => [
						'_' => \esc_html__( 'entries', 'aaa-option-optimizer' ),
						'1' => \esc_html__( 'entry', 'aaa-option-optimizer' ),
					],
					'sInfo'                  => sprintf(
						// translators: %1$s is the start, %2$s is the end, %3$s is the total, %4$s is the entries.
						\esc_html__( 'Showing %1$s to %2$s of %3$s %4$s', 'aaa-option-optimizer' ),
						'_START_',
						'_END_',
						'_TOTAL_',
						'_ENTRIES-TOTAL_'
					),
					'sInfoEmpty'             => \esc_html__( 'Showing 0 to 0 of 0 entries', 'aaa-option-optimizer' ),
					'sInfoFiltered'          => sprintf(
						// translators: %1$s is the max, %2$s is the entries-max.
						\esc_html__( '(filtered from %1$s total %2$s)', 'aaa-option-optimizer' ),
						'_MAX_',
						'_ENTRIES-MAX_'
					),
					'sZeroRecords'           => \esc_html__( 'No matching records found', 'aaa-option-optimizer' ),
					'oAria'                  => [
						'orderable'        => \esc_html__( ': Activate to sort', 'aaa-option-optimizer' ),
						'orderableReverse' => \esc_html__( ': Activate to invert sorting', 'aaa-option-optimizer' ),
						'orderableRemove'  => \esc_html__( ': Activate to remove sorting', 'aaa-option-optimizer' ),
						'paginate'         => [
							'first'    => \esc_html__( 'First', 'aaa-option-optimizer' ),
							'last'     => \esc_html__( 'Last', 'aaa-option-optimizer' ),
							'next'     => \esc_html__( 'Next', 'aaa-option-optimizer' ),
							'previous' => \esc_html__( 'Previous', 'aaa-option-optimizer' ),
						],
					],
				],
			]
		);
	}

	/**
	 * Build the list of installed plugins offered as Report popover suggestions.
	 *
	 * The array key is the plugin's directory name, which for plugins hosted on
	 * wordpress.org matches their wp.org slug — the value the report endpoint
	 * expects. Plugins living in a single file at the plugins root have no
	 * directory to derive a slug from and are skipped.
	 *
	 * These are suggestions only. A directory name can differ from the wp.org
	 * slug (renamed folders, plugins hosted elsewhere), so a picked slug still
	 * goes through the same wordpress.org verification as a typed one.
	 *
	 * @return array<string, string> Slug => plugin name.
	 */
	private function get_installed_plugins(): array {
		if ( ! \function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = [];
		foreach ( \get_plugins() as $file => $data ) {
			// "slug/slug.php" gives a directory to use; "single-file.php" does not.
			if ( \strpos( $file, '/' ) === false ) {
				continue;
			}

			$slug = \dirname( $file );
			if ( ! isset( $plugins[ $slug ] ) ) {
				$plugins[ $slug ] = isset( $data['Name'] ) ? (string) $data['Name'] : $slug;
			}
		}

		\asort( $plugins, SORT_NATURAL | SORT_FLAG_CASE );

		return $plugins;
	}

	/**
	 * Renders the admin page.
	 *
	 * @return void
	 */
	public function render_admin_page_ajax() {
		$option_optimizer = \get_option( 'option_optimizer', [ 'used_options' => [] ] );

		global $wpdb;
		$autoload_values = \wp_autoload_values_to_autoload();
		$placeholders    = \implode( ',', \array_fill( 0, \count( $autoload_values ), '%s' ) );

		// phpcs:disable WordPress.DB
		$result = $wpdb->get_row(
			$wpdb->prepare( "SELECT count(*) AS count, SUM( LENGTH( option_value ) ) as autoload_size FROM {$wpdb->options} WHERE autoload IN ( $placeholders )", $autoload_values )
		);
		// phpcs:enable WordPress.DB

		// Check if migration is needed.
		$migration_status = Database::get_migration_status();
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'AAA Option Optimizer', 'aaa-option-optimizer' ); ?></h1>

			<?php if ( $migration_status['needs_migration'] ) : ?>
			<div id="aaa-migration-notice" class="notice notice-warning">
				<p>
					<strong><?php \esc_html_e( 'Data Migration Required', 'aaa-option-optimizer' ); ?></strong><br>
					<?php
					printf(
						/* translators: %d: number of options to migrate */
						\esc_html__( 'We need to migrate %d tracked options to the new database format.', 'aaa-option-optimizer' ),
						(int) $migration_status['remaining']
					);
					?>
				</p>
				<div id="aaa-migration-progress" style="display: none; margin: 10px 0;">
					<div style="background: #e0e0e0; border-radius: 4px; height: 20px; width: 100%; max-width: 400px;">
						<div id="aaa-migration-progress-bar" style="background: #0073aa; height: 100%; border-radius: 4px; width: 0%; transition: width 0.3s;"></div>
					</div>
					<p id="aaa-migration-status" style="margin: 5px 0;"></p>
				</div>
				<p>
					<button id="aaa-start-migration" class="button button-primary" type="button">
						<?php \esc_html_e( 'Start Migration', 'aaa-option-optimizer' ); ?>
					</button>
				</p>
			</div>
			<?php endif; ?>

			<p><?php \esc_html_e( 'We\'ve found the following things you can maybe optimize:', 'aaa-option-optimizer' ); ?></p>

			<div class="aaa-option-optimizer-tabs">
				<input class="input" name="tabs" type="radio" id="tab-1" checked="checked" />
				<label class="label" for="tab-1"><?php \esc_html_e( 'Unused, but autoloaded', 'aaa-option-optimizer' ); ?></label>
				<div class="panel">
					<h2 id="unused-autoloaded"><?php \esc_html_e( 'Unused, but autoloaded', 'aaa-option-optimizer' ); ?></h2>
					<p><?php \esc_html_e( 'The following options are autoloaded on each pageload, but AAA Option Optimizer has not been able to detect them being used.', 'aaa-option-optimizer' ); ?></p>

					<table style="width:100%" id="unused_options_table" class="aaa_option_table">
						<thead>
							<tr>
								<th class="select-all"><input type="checkbox" class="select-all-checkbox" /></th>
								<th><?php \esc_html_e( 'Option', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Source', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Size (KB)', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Autoload', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Actions', 'aaa-option-optimizer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td class="select-all"></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td class="actions"></td>
							</tr>
						</tbody>
						<tfoot>
							<tr>
								<th class="select-all"><input type="checkbox" class="select-all-checkbox" /></th>
								<th><?php \esc_html_e( 'Option', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Source', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Size (KB)', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Autoload', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Actions', 'aaa-option-optimizer' ); ?></th>
							</tr>
						</tfoot>
					</table>
				</div>

				<input class="input" name="tabs" type="radio" id="tab-2"/>
				<label class="label" for="tab-2"><?php \esc_html_e( 'Used, but not autoloaded', 'aaa-option-optimizer' ); ?></label>
				<div class="panel">
					<?php // Render differences. ?>
					<h2 id="used-not-autoloaded"><?php \esc_html_e( 'Used, but not autoloaded options', 'aaa-option-optimizer' ); ?></h2>
					<p><?php \esc_html_e( 'The following options are *not* autoloaded on each pageload, but AAA Option Optimizer has detected that they are being used. If one of the options below has been called a lot and is not very big, you might consider adding autoload to that option.', 'aaa-option-optimizer' ); ?></p>
					<table style="width:100%;" id="used_not_autoloaded_table" class="aaa_option_table">
						<thead>
							<tr>
								<th class="select-all"><input type="checkbox" class="select-all-checkbox" /></th>
								<th><?php \esc_html_e( 'Option', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Source', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Size (KB)', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Autoload', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( '# Calls', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Actions', 'aaa-option-optimizer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td class="select-all"></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td class="actions"></td>
							</tr>
						</tbody>
						<tfoot>
							<tr>
								<th class="select-all"><input type="checkbox" class="select-all-checkbox" /></th>
								<th><?php \esc_html_e( 'Option', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Source', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Size (KB)', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Autoload', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( '# Calls', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Actions', 'aaa-option-optimizer' ); ?></th>
							</tr>
						</tfoot>
					</table>
				</div>

				<input class="input" name="tabs" type="radio" id="tab-3"/>
				<label class="label" for="tab-3"><?php \esc_html_e( 'Requested options that do not exist', 'aaa-option-optimizer' ); ?></label>
				<div class="panel">
					<h2 id="requested-do-not-exist"><?php \esc_html_e( 'Requested options that do not exist', 'aaa-option-optimizer' ); ?></h2>
					<p><?php \esc_html_e( 'The following options are requested sometimes, but AAA Option Optimizer has detected that they do not exist. If one of the options below has been called a lot, it might help to create it with a value of false.', 'aaa-option-optimizer' ); ?></p>
					<table width="100%" id="requested_do_not_exist_table" class="aaa_option_table">
						<thead>
							<tr>
								<th><?php \esc_html_e( 'Option', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Source', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( '# Calls', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Actions', 'aaa-option-optimizer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td></td>
								<td></td>
								<td></td>
								<td class="actions"></td>
							</tr>
						</tbody>
						<tfoot>
							<tr>
								<th><?php \esc_html_e( 'Option', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Source', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( '# Calls', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Actions', 'aaa-option-optimizer' ); ?></th>
							</tr>
						</tfoot>
					</table>
				</div>

				<input class="input" name="tabs" type="radio" id="tab-4"/>
				<label class="label" for="tab-4"><?php \esc_html_e( 'All options', 'aaa-option-optimizer' ); ?></label>
				<div class="panel">
					<p><?php \esc_html_e( 'If you want to browse all the options in the database, you can do so here:', 'aaa-option-optimizer' ); ?></p>
					<button id="aaa_get_all_options" class="button button-primary"><?php \esc_html_e( 'Get all options', 'aaa-option-optimizer' ); ?></button>
					<table class="aaa_option_table" id="all_options_table" style="display:none;">
						<thead>
							<tr>
								<th class="select-all"><input type="checkbox" class="select-all-checkbox" /></th>
								<th><?php \esc_html_e( 'Option', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Source', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Size (KB)', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Autoload', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Actions', 'aaa-option-optimizer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td class="select-all"></td>
								<td></td>
								<td></td>
								<td></td>
								<td></td>
								<td class="actions"></td>
							</tr>
						</tbody>
						<tfoot>
							<tr>
								<th class="select-all"><input type="checkbox" class="select-all-checkbox" /></th>
								<th><?php \esc_html_e( 'Option', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Source', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Size (KB)', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Autoload', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Actions', 'aaa-option-optimizer' ); ?></th>
							</tr>
						</tfoot>
					</table>
				</div>

				<input class="input" name="tabs" type="radio" id="tab-5"/>
				<label class="label" for="tab-5"><?php \esc_html_e( 'Settings', 'aaa-option-optimizer' ); ?></label>
				<div class="panel">
					<?php $this->render_settings_tab( $option_optimizer, $result ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the settings tab content.
	 *
	 * @param array<string, mixed> $option_optimizer The option optimizer data.
	 * @param object               $result           The database query result with current stats.
	 *
	 * @return void
	 */
	private function render_settings_tab( $option_optimizer, $result ): void {
		$settings = self::get_settings();

		// Check if settings were saved.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce check not needed here.
		if ( isset( $_GET['settings-updated'] ) ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php \esc_html_e( 'Settings saved.', 'aaa-option-optimizer' ); ?></p>
			</div>
			<?php
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is used for REST API.
		if ( isset( $_GET['tracking_reset'] ) && $_GET['tracking_reset'] === 'true' ) :
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php \esc_html_e( 'Tracking data has been reset.', 'aaa-option-optimizer' ); ?></p>
			</div>
			<?php // Take the parameter out of the URL without reloading the page. ?>
			<script>window.history.pushState({}, document.title, window.location.href.replace( '&tracking_reset=true', '' ) );</script>
		<?php endif; ?>

		<h2><?php \esc_html_e( 'Stats', 'aaa-option-optimizer' ); ?></h2>
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

		<div class="aaa-option-optimizer-reset" style="margin-bottom: 30px;">
			<button id="aaa-option-reset-data" class="button button-delete reset-data" type="button">
				<?php \esc_html_e( 'Reset data', 'aaa-option-optimizer' ); ?>
			</button>
		</div>

		<h2><?php \esc_html_e( 'Option Tracking', 'aaa-option-optimizer' ); ?></h2>
		<form action="options.php" method="post">
			<?php \settings_fields( 'aaa_option_optimizer_settings_group' ); ?>
			<p><?php \esc_html_e( 'Configure how options are tracked on your site.', 'aaa-option-optimizer' ); ?></p>
			<fieldset class="aaa-option-optimizer-tracking-fieldset">
				<label for="aaa_option_optimizer_tracking_pre_option">
					<input type="radio" name="<?php echo \esc_attr( self::OPTION_NAME ); ?>[settings][option_tracking]" value="pre_option" id="aaa_option_optimizer_tracking_pre_option" <?php \checked( $settings['option_tracking'], 'pre_option' ); ?>>
					<?php \esc_html_e( 'Pre option', 'aaa-option-optimizer' ); ?>
				</label>
				<label for="aaa_option_optimizer_tracking_legacy">
					<input type="radio" name="<?php echo \esc_attr( self::OPTION_NAME ); ?>[settings][option_tracking]" value="legacy" id="aaa_option_optimizer_tracking_legacy" <?php \checked( $settings['option_tracking'], 'legacy' ); ?>>
					<?php \esc_html_e( 'Legacy', 'aaa-option-optimizer' ); ?>
				</label>
			</fieldset>

			<h2><?php \esc_html_e( 'Remote data', 'aaa-option-optimizer' ); ?></h2>
			<fieldset class="aaa-option-optimizer-consent-fieldset">
				<label for="aaa_option_optimizer_remote_data_consent">
					<input type="checkbox" name="<?php echo \esc_attr( self::OPTION_NAME ); ?>[settings][remote_data_consent]" value="1" id="aaa_option_optimizer_remote_data_consent" <?php \checked( ! empty( $settings['remote_data_consent'] ) ); ?>>
					<?php \esc_html_e( 'Keep the known-plugins list up to date automatically', 'aaa-option-optimizer' ); ?>
				</label>
				<p class="description">
					<?php
					printf(
						/* translators: %s is the host the data is fetched from. */
						\esc_html__( 'When enabled, the plugin fetches an updated known-plugins list once a day from %s, and sends your plugin and WordPress version so we can keep anonymous usage statistics. No site identity is sent. When disabled, only the list bundled with the plugin is used.', 'aaa-option-optimizer' ),
						'<code>option-optimizer-api.progressplanner.com</code>'
					);
					?>
				</p>
			</fieldset>
			<?php \submit_button( \__( 'Save Settings', 'aaa-option-optimizer' ) ); ?>
		</form>
		<?php
	}
}
