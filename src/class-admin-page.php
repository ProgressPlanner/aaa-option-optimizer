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

		// Sanitize the quarantine retention days (1..30, default 7).
		$retention = Quarantine::DEFAULT_RETENTION_DAYS;
		if ( isset( $input['settings']['quarantine_retention_days'] ) ) {
			$retention = (int) $input['settings']['quarantine_retention_days'];
			$retention = \max( 1, \min( 30, $retention ) );
		}
		$existing['settings']['quarantine_retention_days'] = $retention;

		// Sanitize the quarantine expiry action ('keep' or 'delete', default 'keep').
		$expiry_action = Quarantine::DEFAULT_EXPIRY_ACTION;
		if ( isset( $input['settings']['quarantine_expiry_action'] ) ) {
			$candidate = \sanitize_text_field( $input['settings']['quarantine_expiry_action'] );
			if ( \in_array( $candidate, [ 'keep', 'delete' ], true ) ) {
				$expiry_action = $candidate;
			}
		}
		$existing['settings']['quarantine_expiry_action'] = $expiry_action;

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
			'option_tracking'           => 'pre_option',
			'quarantine_retention_days' => Quarantine::DEFAULT_RETENTION_DAYS,
			'quarantine_expiry_action'  => Quarantine::DEFAULT_EXPIRY_ACTION,
		];

		$option_optimizer = \get_option( self::OPTION_NAME, [] );
		$settings         = isset( $option_optimizer['settings'] ) ? $option_optimizer['settings'] : [];

		return \wp_parse_args( $settings, $defaults );
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

		\wp_localize_script(
			'aaa-option-optimizer-admin-js',
			'aaaOptionOptimizer',
			[
				'root'              => \esc_url_raw( \rest_url() ),
				'nonce'             => \wp_create_nonce( 'wp_rest' ),
				'migration'         => Database::get_migration_status(),
				'protectedOptions'  => Protected_Options::get_protected_map(),
				'protectedPrefixes' => [
					'option_optimizer',
					'aaa_option_optimizer',
					'_aaaoo_q__',
					'_transient_',
					'_site_transient_',
				],
				'i18n'              => [
					'filterBySource'         => \esc_html__( 'Filter by source', 'aaa-option-optimizer' ),
					'showValue'              => \esc_html__( 'Show', 'aaa-option-optimizer' ),
					'addAutoload'            => \esc_html__( 'Add autoload', 'aaa-option-optimizer' ),
					'removeAutoload'         => \esc_html__( 'Remove autoload', 'aaa-option-optimizer' ),
					'deleteOption'           => \esc_html__( 'Delete', 'aaa-option-optimizer' ),
					'createOptionFalse'      => \esc_html__( 'Create option with value false', 'aaa-option-optimizer' ),
					'noAutoloadedButNotUsed' => \esc_html__( 'All autoloaded options are in use.', 'aaa-option-optimizer' ),
					'noUsedButNotAutoloaded' => \esc_html__( 'All options that are used are autoloaded.', 'aaa-option-optimizer' ),
					'noOptionsSelected'      => \esc_html__( 'No options selected.', 'aaa-option-optimizer' ),
					'bulkActions'            => \esc_html__( 'Bulk actions', 'aaa-option-optimizer' ),
					'noBulkActionSelected'   => \esc_html__( 'No action selected.', 'aaa-option-optimizer' ),
					'delete'                 => \esc_html__( 'Delete', 'aaa-option-optimizer' ),
					'apply'                  => \esc_html__( 'Apply', 'aaa-option-optimizer' ),
					'export'                 => \esc_html__( 'Export', 'aaa-option-optimizer' ),
					'exportSelected'         => \esc_html__( 'Export selected', 'aaa-option-optimizer' ),
					'protectedTooltip'       => \esc_html__( 'Protected — cannot delete', 'aaa-option-optimizer' ),
					'restore'                => \esc_html__( 'Restore', 'aaa-option-optimizer' ),
					'permanentlyDelete'      => \esc_html__( 'Permanently delete', 'aaa-option-optimizer' ),
					'confirmPermanentDelete' => \esc_html__( 'Permanently delete this option? This cannot be undone.', 'aaa-option-optimizer' ),
					/* translators: %d: number of selected options */
					'confirmBulkQuarantine'  => \esc_html__( 'You are about to quarantine %d options. They can be restored from the Quarantine tab. Continue?', 'aaa-option-optimizer' ),
					'importSelectFile'       => \esc_html__( 'Select JSON file', 'aaa-option-optimizer' ),
					'importOverwriteLabel'   => \esc_html__( 'Overwrite existing options', 'aaa-option-optimizer' ),
					'importButton'           => \esc_html__( 'Import', 'aaa-option-optimizer' ),
					/* translators: %1$d imported, %2$d skipped */
					'importResult'           => \esc_html__( 'Imported %1$d options, skipped %2$d.', 'aaa-option-optimizer' ),
					'importInvalidJson'      => \esc_html__( 'Selected file is not valid JSON.', 'aaa-option-optimizer' ),
					'quarantineEmpty'        => \esc_html__( 'No options are currently in quarantine.', 'aaa-option-optimizer' ),
					'quarantineColOption'    => \esc_html__( 'Option', 'aaa-option-optimizer' ),
					'quarantineColSize'      => \esc_html__( 'Size (KB)', 'aaa-option-optimizer' ),
					'quarantineColAutoload'  => \esc_html__( 'Autoload', 'aaa-option-optimizer' ),
					'quarantineColExpires'   => \esc_html__( 'Expires', 'aaa-option-optimizer' ),
					'quarantineColQueuedAt'  => \esc_html__( 'Quarantined at', 'aaa-option-optimizer' ),

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
				<label class="label" for="tab-5"><?php \esc_html_e( 'Quarantine', 'aaa-option-optimizer' ); ?></label>
				<div class="panel">
					<h2 id="quarantine"><?php \esc_html_e( 'Quarantine', 'aaa-option-optimizer' ); ?></h2>
					<p><?php \esc_html_e( 'Options moved here are removed from wp_options but kept recoverable for the configured retention period. Use Restore to put one back, or Permanently delete to drop it for good.', 'aaa-option-optimizer' ); ?></p>
					<table style="width:100%" id="quarantine_table" class="aaa_option_table">
						<thead>
							<tr>
								<th><?php \esc_html_e( 'Option', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Size (KB)', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Autoload', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Quarantined at', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Expires', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Actions', 'aaa-option-optimizer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
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
								<th><?php \esc_html_e( 'Option', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Size (KB)', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Autoload', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Quarantined at', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Expires', 'aaa-option-optimizer' ); ?></th>
								<th><?php \esc_html_e( 'Actions', 'aaa-option-optimizer' ); ?></th>
							</tr>
						</tfoot>
					</table>
				</div>

				<input class="input" name="tabs" type="radio" id="tab-6"/>
				<label class="label" for="tab-6"><?php \esc_html_e( 'Import', 'aaa-option-optimizer' ); ?></label>
				<div class="panel">
					<h2 id="import"><?php \esc_html_e( 'Import options', 'aaa-option-optimizer' ); ?></h2>
					<p><?php \esc_html_e( 'Restore options from a previous export. Protected options are always skipped. By default existing options are not overwritten; tick the box below to overwrite them.', 'aaa-option-optimizer' ); ?></p>
					<form id="aaa_import_form" method="post" enctype="multipart/form-data">
						<p>
							<label for="aaa_import_file"><?php \esc_html_e( 'JSON file', 'aaa-option-optimizer' ); ?>:</label>
							<input type="file" id="aaa_import_file" name="aaa_import_file" accept="application/json,.json" />
						</p>
						<p>
							<label for="aaa_import_overwrite">
								<input type="checkbox" id="aaa_import_overwrite" name="aaa_import_overwrite" value="1" />
								<?php \esc_html_e( 'Overwrite existing options', 'aaa-option-optimizer' ); ?>
							</label>
						</p>
						<p>
							<button type="submit" id="aaa_import_submit" class="button button-primary">
								<?php \esc_html_e( 'Import', 'aaa-option-optimizer' ); ?>
							</button>
						</p>
						<div id="aaa_import_result" style="margin-top:10px;"></div>
					</form>
				</div>

				<input class="input" name="tabs" type="radio" id="tab-7"/>
				<label class="label" for="tab-7"><?php \esc_html_e( 'Settings', 'aaa-option-optimizer' ); ?></label>
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

			<h2><?php \esc_html_e( 'Quarantine', 'aaa-option-optimizer' ); ?></h2>
			<p><?php \esc_html_e( 'When an option is deleted from the tables above, it is moved here first so you can restore it if something breaks.', 'aaa-option-optimizer' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="aaa_option_optimizer_retention_days"><?php \esc_html_e( 'Retention (days)', 'aaa-option-optimizer' ); ?></label>
					</th>
					<td>
						<input type="number" min="1" max="30" step="1" id="aaa_option_optimizer_retention_days" name="<?php echo \esc_attr( self::OPTION_NAME ); ?>[settings][quarantine_retention_days]" value="<?php echo \esc_attr( (string) $settings['quarantine_retention_days'] ); ?>" class="small-text" />
						<p class="description"><?php \esc_html_e( 'How long an option stays in quarantine before the expiry action below applies. Range: 1–30 days.', 'aaa-option-optimizer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php \esc_html_e( 'On expiry', 'aaa-option-optimizer' ); ?></th>
					<td>
						<fieldset>
							<label for="aaa_option_optimizer_expiry_keep">
								<input type="radio" name="<?php echo \esc_attr( self::OPTION_NAME ); ?>[settings][quarantine_expiry_action]" value="keep" id="aaa_option_optimizer_expiry_keep" <?php \checked( $settings['quarantine_expiry_action'], 'keep' ); ?>>
								<?php \esc_html_e( 'Keep in quarantine (recommended) — require manual action.', 'aaa-option-optimizer' ); ?>
							</label><br>
							<label for="aaa_option_optimizer_expiry_delete">
								<input type="radio" name="<?php echo \esc_attr( self::OPTION_NAME ); ?>[settings][quarantine_expiry_action]" value="delete" id="aaa_option_optimizer_expiry_delete" <?php \checked( $settings['quarantine_expiry_action'], 'delete' ); ?>>
								<?php \esc_html_e( 'Permanently delete automatically after expiry.', 'aaa-option-optimizer' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
			</table>

			<?php \submit_button( \__( 'Save Settings', 'aaa-option-optimizer' ) ); ?>
		</form>
		<?php
	}
}
