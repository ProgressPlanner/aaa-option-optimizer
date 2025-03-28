<?php
/**
 * Admin page functionality for AAA Option Optimizer.
 *
 * @package Emilia\MetaOptimizer
 */

namespace Emilia\MetaOptimizer;

/**
 * Admin page functionality for AAA Option Optimizer.
 */
class Admin_Page {

	/**
	 * The map plugin to options class.
	 *
	 * @var Map_Plugin_To_Options
	 */
	private $map_plugin_to_options;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		$this->map_plugin_to_options = new Map_Plugin_To_Options();

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
			$this_plugin = \plugin_basename( AAA_META_OPTIMIZER_FILE );
		}

		if ( $file === $this_plugin ) {
			$settings_link = '<a href="' . \admin_url( 'tools.php?page=aaa-meta-optimizer' ) . '">' . \__( 'Optimize Meta Fields', 'aaa-meta-optimizer' ) . '</a>';
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
			__( 'AAA Meta Optimizer', 'aaa-meta-optimizer' ),
			__( 'Meta Optimizer', 'aaa-meta-optimizer' ),
			'manage_options',
			'aaa-meta-optimizer',
			[ $this, 'render_admin_page' ]
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
		if ( $hook !== 'tools_page_aaa-meta-optimizer' ) {
			return;
		}

		\wp_enqueue_style(
			'aaa-meta-optimizer',
			plugin_dir_url( AAA_META_OPTIMIZER_FILE ) . 'css/style.css',
			[],
			'2.0.1'
		);

		\wp_enqueue_script(
			'datatables',
			plugin_dir_url( AAA_META_OPTIMIZER_FILE ) . 'js/vendor/datatables.min.js',
			[], // Dependencies.
			'2.0.1',
			true // In footer.
		);

		\wp_enqueue_style(
			'datatables',
			plugin_dir_url( AAA_META_OPTIMIZER_FILE ) . 'js/vendor/datatables.min.css',
			[],
			'2.0.1'
		);

		\wp_enqueue_script(
			'aaa-meta-optimizer-admin-js',
			plugin_dir_url( AAA_META_OPTIMIZER_FILE ) . 'js/admin-script.js',
			[ 'jquery', 'datatables' ], // Dependencies.
			filemtime( plugin_dir_path( AAA_META_OPTIMIZER_FILE ) . 'js/admin-script.js' ), // Version.
			true // In footer.
		);

		\wp_localize_script(
			'aaa-meta-optimizer-admin-js',
			'aaaMetaOptimizer',
			[
				'root'  => esc_url_raw( rest_url() ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => [
					'filterBySource' => esc_html__( 'Filter by source', 'aaa-meta-optimizer' ),
					'showValue'      => esc_html__( 'Show', 'aaa-meta-optimizer' ),
					'addAutoload'    => esc_html__( 'Add autoload', 'aaa-meta-optimizer' ),
					'removeAutoload' => esc_html__( 'Remove autoload', 'aaa-meta-optimizer' ),
					'deleteOption'   => esc_html__( 'Delete', 'aaa-meta-optimizer' ),

					'search'         => esc_html__( 'Search:', 'aaa-meta-optimizer' ),
					'entries'        => [
						'_' => \esc_html__( 'entries', 'aaa-meta-optimizer' ),
						'1' => \esc_html__( 'entry', 'aaa-meta-optimizer' ),
					],
					'sInfo'          => sprintf(
						// translators: %1$s is the start, %2$s is the end, %3$s is the total, %4$s is the entries.
						esc_html__( 'Showing %1$s to %2$s of %3$s %4$s', 'aaa-meta-optimizer' ),
						'_START_',
						'_END_',
						'_TOTAL_',
						'_ENTRIES-TOTAL_'
					),
					'sInfoEmpty'     => esc_html__( 'Showing 0 to 0 of 0 entries', 'aaa-meta-optimizer' ),
					'sInfoFiltered'  => sprintf(
						// translators: %1$s is the max, %2$s is the entries-max.
						esc_html__( '(filtered from %1$s total %2$s)', 'aaa-meta-optimizer' ),
						'_MAX_',
						'_ENTRIES-MAX_'
					),
					'sZeroRecords'   => esc_html__( 'No matching records found', 'aaa-meta-optimizer' ),
					'oAria'          => [
						'orderable'        => esc_html__( ': Activate to sort', 'aaa-meta-optimizer' ),
						'orderableReverse' => esc_html__( ': Activate to invert sorting', 'aaa-meta-optimizer' ),
						'orderableRemove'  => esc_html__( ': Activate to remove sorting', 'aaa-meta-optimizer' ),
						'paginate'         => [
							'first'    => esc_html__( 'First', 'aaa-meta-optimizer' ),
							'last'     => esc_html__( 'Last', 'aaa-meta-optimizer' ),
							'next'     => esc_html__( 'Next', 'aaa-meta-optimizer' ),
							'previous' => esc_html__( 'Previous', 'aaa-meta-optimizer' ),
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
					echo '<th class="actions">' . esc_html__( 'Actions', 'aaa-meta-optimizer' ) . '</th>';
					break;
				case 'calls':
					echo '<th>' . esc_html__( '# Calls', 'aaa-meta-optimizer' ) . '</th>';
					break;
				case 'option':
					echo '<th>' . esc_html__( 'Option', 'aaa-meta-optimizer' ) . '</th>';
					break;
				case 'source':
					echo '<th class="source">' . esc_html__( 'Source', 'aaa-meta-optimizer' ) . '</th>';
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
	public function render_admin_page() {
		global $wpdb;
		$meta_optimizer = get_option( 'meta_optimizer', [ 'used_meta_fields' => [] ] );

		$all_meta_keys = $wpdb->get_results( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$all_meta_keys = wp_list_pluck( $all_meta_keys, 'meta_key' );

		$unused_meta_fields = [];

		// Get the meta fields that aren't used.
		foreach ( $all_meta_keys as $meta_key ) {
			if ( isset( $meta_optimizer['used_meta_fields'][ $meta_key ] ) ) {
				continue;
			}
			$unused_meta_fields[ $meta_key ] = true;
		}

		// Start HTML output.
		echo '<div class="wrap"><h1>' . esc_html__( 'AAA Meta Optimizer', 'aaa-meta-optimizer' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is used for REST API.
		if ( isset( $_GET['tracking_reset'] ) && $_GET['tracking_reset'] === 'true' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Tracking data has been reset.', 'aaa-meta-optimizer' ) . '</p></div>';
			// Take the parameter out of the URL without reloading the page.
			echo '<script>window.history.pushState({}, document.title, window.location.href.replace( \'&tracking_reset=true\', \'\' ) );</script>';
		}
		echo '<div class="aaa-option-optimizer-reset"><button id="aaa-meta-reset-data" class="button button-delete reset-data">' . esc_html__( 'Reset data', 'aaa-meta-optimizer' ) . '</button></div>';

		?>
	<div class="aaa-option-optimizer-tabs">
			<input class="input" name="tabs" type="radio" id="tab-1" checked="checked"/>
			<label class="label" for="tab-1"><?php esc_html_e( 'Unused meta fields', 'aaa-meta-optimizer' ); ?></label>
			<div class="panel">
		<?php
		echo '<h2 id="unused-meta-fields">' . esc_html__( 'Unused meta fields', 'aaa-meta-optimizer' ) . '</h2>';
		if ( ! empty( $unused_meta_fields ) ) {
			echo '<p>' . esc_html__( 'The following meta fields are not used.', 'aaa-meta-optimizer' );
			echo '<table style="width:100%" id="unused_options_table" class="aaa_option_table">';
			$this->table_section( 'thead', [ 'option', 'source', 'actions' ] );
			echo '<tbody>';
			foreach ( $unused_meta_fields as $option => $value ) {
				echo '<tr id="option_' . esc_attr( str_replace( ':', '', str_replace( '.', '', $option ) ) ) . '"><td>' . esc_html( $option ) . '</td>';
				echo '<td>' . esc_html( $this->get_plugin_name( $option ) ) . '</td>';
				echo '<td class="actions">';
				echo '</td></tr>';
			}
			echo '</tbody>';
			$this->table_section( 'tfoot', [ 'option', 'source', 'actions' ] );
			echo '</table>';
		} else {
			echo '<p>' . esc_html__( 'All meta fields are in use.', 'aaa-meta-optimizer' ) . '</p>';
		}
		?>
		</div>
		<input class="input" name="tabs" type="radio" id="tab-2"/>
			<label class="label" for="tab-2"><?php esc_html_e( 'Used meta fields', 'aaa-meta-optimizer' ); ?></label>
			<div class="panel">
		<?php
		if ( ! empty( $meta_optimizer['used_meta_fields'] ) ) {
			echo '<h2 id="used-meta-fields">' . esc_html__( 'Used meta fields', 'aaa-meta-optimizer' ) . '</h2>';
			echo '<p>' . esc_html__( 'The following meta fields are being used.', 'aaa-meta-optimizer' );
			echo '<table style="width:100%;" id="used_not_autoloaded_table" class="aaa_option_table">';
			$this->table_section( 'thead', [ 'option', 'source', 'calls', 'actions' ] );
			echo '<tbody>';
			foreach ( $meta_optimizer['used_meta_fields'] as $option => $arr ) {
				echo '<tr id="option_' . esc_attr( str_replace( ':', '', str_replace( '.', '', $option ) ) ) . '">';
				echo '<td>' . esc_html( $option ) . '</td>';
				echo '<td>' . esc_html( $this->get_plugin_name( $option ) ) . '</td>';
				echo '<td>' . esc_html( $arr ) . '</td>';
				echo '<td class="actions">';
				echo '</td></tr>';
			}
			echo '</tbody>';
			$this->table_section( 'tfoot', [ 'option', 'source', 'calls', 'actions' ] );
			echo '</table>';
		} else {
			echo '<p>' . esc_html__( 'There are no used meta fields.', 'aaa-meta-optimizer' ) . '</p>';
		}
		?>
		</div>
	</div>
		<?php
	}

	/**
	 * Find plugin in known plugin prefixes list.
	 *
	 * @param string $option The option name.
	 *
	 * @return string
	 */
	private function get_plugin_name( $option ) {
		return $this->map_plugin_to_options->get_plugin_name( $option );
	}
}
