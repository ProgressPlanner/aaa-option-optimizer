<?php
/**
 * Admin page functionality for AAA Option Optimizer.
 *
 * @package Emilia\OptionOptimizer
 */

namespace Emilia\OptionOptimizer;

/**
 * Admin page functionality for AAA Option Optimizer.
 */
class Admin_Page {

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
			'datatables',
			plugin_dir_url( AAA_OPTION_OPTIMIZER_FILE ) . 'js/vendor/datatables.min.js',
			[], // Dependencies.
			'2.0.1',
			true // In footer.
		);

		\wp_enqueue_style(
			'datatables',
			plugin_dir_url( AAA_OPTION_OPTIMIZER_FILE ) . 'js/vendor/datatables.min.css',
			[],
			'2.0.1'
		);

		\wp_enqueue_script(
			'aaa-option-optimizer-admin-js',
			plugin_dir_url( AAA_OPTION_OPTIMIZER_FILE ) . 'js/admin-script.js',
			[ 'jquery', 'datatables' ], // Dependencies.
			filemtime( plugin_dir_path( AAA_OPTION_OPTIMIZER_FILE ) . 'js/admin-script.js' ), // Version.
			true // In footer.
		);

		\wp_localize_script(
			'aaa-option-optimizer-admin-js',
			'aaaOptionOptimizer',
			[
				'root'  => esc_url_raw( rest_url() ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => [
					'filterBySource'         => esc_html__( 'Filter by source', 'aaa-option-optimizer' ),
					'showValue'              => esc_html__( 'Show', 'aaa-option-optimizer' ),
					'addAutoload'            => esc_html__( 'Add autoload', 'aaa-option-optimizer' ),
					'removeAutoload'         => esc_html__( 'Remove autoload', 'aaa-option-optimizer' ),
					'deleteOption'           => esc_html__( 'Delete', 'aaa-option-optimizer' ),
					'createOptionFalse'      => esc_html__( 'Create option with value false', 'aaa-option-optimizer' ),
					'noAutoloadedButNotUsed' => esc_html__( 'All autoloaded options are in use.', 'aaa-option-optimizer' ),
					'noUsedButNotAutoloaded' => esc_html__( 'All options that are used are autoloaded.', 'aaa-option-optimizer' ),

					'search'                 => esc_html__( 'Search:', 'aaa-option-optimizer' ),
					'entries'                => [
						'_' => \esc_html__( 'entries', 'aaa-option-optimizer' ),
						'1' => \esc_html__( 'entry', 'aaa-option-optimizer' ),
					],
					'sInfo'                  => sprintf(
						// translators: %1$s is the start, %2$s is the end, %3$s is the total, %4$s is the entries.
						esc_html__( 'Showing %1$s to %2$s of %3$s %4$s', 'aaa-option-optimizer' ),
						'_START_',
						'_END_',
						'_TOTAL_',
						'_ENTRIES-TOTAL_'
					),
					'sInfoEmpty'             => esc_html__( 'Showing 0 to 0 of 0 entries', 'aaa-option-optimizer' ),
					'sInfoFiltered'          => sprintf(
						// translators: %1$s is the max, %2$s is the entries-max.
						esc_html__( '(filtered from %1$s total %2$s)', 'aaa-option-optimizer' ),
						'_MAX_',
						'_ENTRIES-MAX_'
					),
					'sZeroRecords'           => esc_html__( 'No matching records found 123', 'aaa-option-optimizer' ),
					'oAria'                  => [
						'orderable'        => esc_html__( ': Activate to sort', 'aaa-option-optimizer' ),
						'orderableReverse' => esc_html__( ': Activate to invert sorting', 'aaa-option-optimizer' ),
						'orderableRemove'  => esc_html__( ': Activate to remove sorting', 'aaa-option-optimizer' ),
						'paginate'         => [
							'first'    => esc_html__( 'First', 'aaa-option-optimizer' ),
							'last'     => esc_html__( 'Last', 'aaa-option-optimizer' ),
							'next'     => esc_html__( 'Next', 'aaa-option-optimizer' ),
							'previous' => esc_html__( 'Previous', 'aaa-option-optimizer' ),
						],
					],
				],
			]
		);
	}

	/**
	 * Render a table section.
	 *
	 * @param string   $section The section (usually thead or tfoot).
	 * @param string[] $columns The columns to render.
	 *
	 * @return void
	 */
	public function table_section( $section, $columns ) {
		echo '<' . esc_html( $section ) . '>';
		echo '<tr>';
		foreach ( $columns as $column ) {
			switch ( $column ) {
				case 'actions':
					echo '<th class="actions">' . esc_html__( 'Actions', 'aaa-option-optimizer' ) . '</th>';
					break;
				case 'autoload':
					echo '<th>' . esc_html__( 'Autoload', 'aaa-option-optimizer' ) . '</th>';
					break;
				case 'calls':
					echo '<th>' . esc_html__( '# Calls', 'aaa-option-optimizer' ) . '</th>';
					break;
				case 'option':
					echo '<th>' . esc_html__( 'Option', 'aaa-option-optimizer' ) . '</th>';
					break;
				case 'size':
					echo '<th>' . esc_html__( 'Size (KB)', 'aaa-option-optimizer' ) . '</th>';
					break;
				case 'source':
					echo '<th class="source">' . esc_html__( 'Source', 'aaa-option-optimizer' ) . '</th>';
					break;
				case 'select-all':
					echo '<th class="select-all"><input type="checkbox" class="select-all-checkbox" /></th>';
					break;
			}
		}
		echo '</tr>';
		echo '</' . esc_html( $section ) . '>';
	}

	/**
	 * Renders the admin page.
	 *
	 * @return void
	 */
	public function render_admin_page_ajax() {
		$option_optimizer = get_option( 'option_optimizer', [ 'used_options' => [] ] );

		// Start HTML output.
		echo '<div class="wrap"><h1>' . esc_html__( 'AAA Option Optimizer', 'aaa-option-optimizer' ) . '</h1>';

		global $wpdb;
		$autoload_values = \wp_autoload_values_to_autoload();
		$placeholders    = implode( ',', array_fill( 0, count( $autoload_values ), '%s' ) );

		// phpcs:disable WordPress.DB
		$result = $wpdb->get_row(
			$wpdb->prepare( "SELECT count(*) AS count, SUM( LENGTH( option_value ) ) as autoload_size FROM {$wpdb->options} WHERE autoload IN ( $placeholders )", $autoload_values )
		);
		// phpcs:enable WordPress.DB

		echo '<h2>' . esc_html__( 'Stats', 'aaa-option-optimizer' ) . '</h2>';
		echo '<p>' .
			sprintf(
				// translators: %1$s is the date, %2$s is the number of options at stat, %3$s is the size at start in KB, %4$s is the number of options now, %5$s is the size in KB now.
				esc_html__( 'When you started on %1$s you had %2$s autoloaded options, for %3$sKB of memory. Now you have %4$s options, for %5$sKB of memory.', 'aaa-option-optimizer' ),
				esc_html( gmdate( 'Y-m-d', strtotime( $option_optimizer['starting_point_date'] ) ) ),
				isset( $option_optimizer['starting_point_num'] ) ? esc_html( $option_optimizer['starting_point_num'] ) : '-',
				number_format( ( $option_optimizer['starting_point_kb'] ), 1 ),
				esc_html( $result->count ),
				number_format( ( $result->autoload_size / 1024 ), 1 )
			) . '</p>';

		echo '<h2>' . esc_html__( 'Optimize', 'aaa-option-optimizer' ) . '</h2>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is used for REST API.
		if ( isset( $_GET['tracking_reset'] ) && $_GET['tracking_reset'] === 'true' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Tracking data has been reset.', 'aaa-option-optimizer' ) . '</p></div>';
			// Take the parameter out of the URL without reloading the page.
			echo '<script>window.history.pushState({}, document.title, window.location.href.replace( \'&tracking_reset=true\', \'\' ) );</script>';
		}
		echo '<div class="aaa-option-optimizer-reset"><button id="aaa-option-reset-data" class="button button-delete reset-data">' . esc_html__( 'Reset data', 'aaa-option-optimizer' ) . '</button></div>';
		echo '<p>' . esc_html__( 'We\'ve found the following things you can maybe optimize:', 'aaa-option-optimizer' ) . '</p>';

		?>
	<div class="aaa-option-optimizer-tabs">
			<input class="input" name="tabs" type="radio" id="tab-1" checked="checked"/>
			<label class="label" for="tab-1"><?php esc_html_e( 'Unused, but autoloaded', 'aaa-option-optimizer' ); ?></label>
			<div class="panel">
		<?php
		echo '<h2 id="unused-autoloaded">' . esc_html__( 'Unused, but autoloaded', 'aaa-option-optimizer' ) . '</h2>';
		echo '<button class="button button-delete delete-selected" data-table="unused_options_table">' . esc_html__( 'Delete selected options', 'aaa-option-optimizer' ) . '</button>';
		echo '<p>' . esc_html__( 'The following options are autoloaded on each pageload, but AAA Option Optimizer has not been able to detect them being used.', 'aaa-option-optimizer' );
		echo '<table style="width:100%" id="unused_options_table" class="aaa_option_table">';
		$this->table_section( 'thead', [ 'option', 'source', 'size', 'autoload', 'actions', 'select-all' ] );
		?>
		<tbody>
		<tr>
			<td></td>
			<td></td>
			<td></td>
			<td></td>
			<td class="actions"></td>
			<td class="select-all"></td>
		</tr>
		</tbody>
		<?php
		$this->table_section( 'tfoot', [ 'option', 'source', 'size', 'autoload', 'actions', 'select-all' ] );
		?>
		</table>
		</div>
		<input class="input" name="tabs" type="radio" id="tab-2"/>
			<label class="label" for="tab-2"><?php esc_html_e( 'Used, but not autoloaded', 'aaa-option-optimizer' ); ?></label>
			<div class="panel">
		<?php
		// Render differences.
			echo '<h2 id="used-not-autoloaded">' . esc_html__( 'Used, but not autoloaded options', 'aaa-option-optimizer' ) . '</h2>';
			echo '<button class="button button-delete delete-selected" data-table="used_not_autoloaded_table">' . esc_html__( 'Delete selected options', 'aaa-option-optimizer' ) . '</button>';
			echo '<p>' . esc_html__( 'The following options are *not* autoloaded on each pageload, but AAA Option Optimizer has detected that they are being used. If one of the options below has been called a lot and is not very big, you might consider adding autoload to that option.', 'aaa-option-optimizer' );
			echo '<table style="width:100%;" id="used_not_autoloaded_table" class="aaa_option_table">';
			$this->table_section( 'thead', [ 'option', 'source', 'size', 'autoload', 'calls', 'actions', 'select-all' ] );
		?>
			<tbody>
			<tr>
				<td></td>
				<td></td>
				<td></td>
				<td></td>
				<td></td>
				<td class="actions"></td>
				<td class="select-all"></td>
			</tr>
			</tbody>
			<?php $this->table_section( 'tfoot', [ 'option', 'source', 'size', 'autoload', 'calls', 'actions', 'select-all' ] ); ?>
		</table>
		</div>
		<input class="input" name="tabs" type="radio" id="tab-3"/>
			<label class="label" for="tab-3"><?php esc_html_e( 'Requested options that do not exist', 'aaa-option-optimizer' ); ?></label>
			<div class="panel">
		<?php
		echo '<h2 id="requested-do-not-exist">' . esc_html__( 'Requested options that do not exist', 'aaa-option-optimizer' ) . '</h2>';
		echo '<p>' . esc_html__( 'The following options are requested sometimes, but AAA Option Optimizer has detected that they do not exist. If one of the options below has been called a lot, it might help to create it with a value of false.', 'aaa-option-optimizer' );
		echo '<table width="100%" id="requested_do_not_exist_table" class="aaa_option_table">';
		$this->table_section( 'thead', [ 'option', 'source', 'calls', 'actions' ] );
		?>
		<tbody>
		<tr>
			<td></td>
			<td></td>
			<td></td>
			<td class="actions"></td>
		</tr>
		</tbody>
		<?php
		$this->table_section( 'tfoot', [ 'option', 'source', 'calls', 'actions' ] );
		?>
		</table>
		</div>
		<input class="input" name="tabs" type="radio" id="tab-4"/>
		<label class="label" for="tab-4"><?php esc_html_e( 'All options', 'aaa-option-optimizer' ); ?></label>
		<div class="panel">
			<p><?php esc_html_e( 'If you want to browse all the options in the database, you can do so here:', 'aaa-option-optimizer' ); ?></p>
			<button class="button button-delete delete-selected" data-table="all_options_table" style="display: none;"><?php echo esc_html__( 'Delete selected options', 'aaa-option-optimizer' ); ?></button>
			<button id="aaa_get_all_options" class="button button-primary"><?php esc_html_e( 'Get all options', 'aaa-option-optimizer' ); ?></button>
			<table class="aaa_option_table" id="all_options_table" style="display:none;">
				<?php $this->table_section( 'thead', [ 'option', 'source', 'size', 'autoload', 'actions', 'select-all' ] ); ?>
				<tbody>
					<tr>
						<td></td>
						<td></td>
						<td></td>
						<td></td>
						<td class="actions"></td>
						<td class="select-all"></td>
					</tr>
				</tbody>
				<?php $this->table_section( 'tfoot', [ 'option', 'source', 'size', 'autoload', 'actions', 'select-all' ] ); ?>
			</table>
		</div>
	</div>
		<?php
	}
}
