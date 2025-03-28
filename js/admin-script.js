/**
 * JavaScript for the admin page.
 *
 * @package Emilia\MetaOptimizer
 */

/**
 * Initializes the data tables and sets up event handlers.
 */
jQuery( document ).ready(
	function ($) {
		/**
		 * Array of table selectors to initialize.
		 *
		 * @type {string[]}
		 */
		const tablesToInitialize = [
			'#unused_options_table',
			'#used_not_autoloaded_table',
		];

		$( '#all_options_table' ).hide();
		$( '#aaa_get_all_options' ).on(
			'click',
			function (e) {
				e.preventDefault();
				$( '#all_options_table' ).show();
				initializeDataTable( '#all_options_table' );
				$( this ).hide();
			}
		);

		/**
		 * Initializes the DataTable for the given selector.
		 *
		 * @param {string} selector - The table selector.
		 */
		function initializeDataTable(selector) {
			const options = {
				pageLength: 25,
				autoWidth: false,
				responsive: true,
				columns: getColumns( selector ),
				initComplete: function () {
					this.api().columns( 'source:name' ).every( setupColumnFilters );
				}
			};

			if (selector === '#all_options_table') {
				options.ajax  = {
					url: aaaMetaOptimizer.root + 'aaa-option-optimizer/v1/all-options',
					headers: { 'X-WP-Nonce': aaaMetaOptimizer.nonce },
					type: 'GET',
					dataSrc: 'data'
				};
				options.rowId = 'row_id';
			}

			options.language = aaaMetaOptimizer.i18n;

			const dataTable = new DataTable( selector, options ).columns.adjust().responsive.recalc();;
		}

		/**
		 * Retrieves the columns configuration based on the selector.
		 *
		 * @param {string} selector - The table selector.
		 *
		 * @returns {Object[]} - The columns configuration.
		 */
		function getColumns(selector) {
			const commonColumns = [
				{ name: 'name' },
				{ name: 'source' },
				{ name: 'actions', searchable: false, orderable: false }
			];

			if (selector === '#requested_do_not_exist_table') {
				return [
					{ name: 'name' },
					{ name: 'source', searchable: false },
					{ name: 'calls', searchable: false },
					{ name: 'actions', searchable: false, orderable: false }
				];
			} else if (selector === '#used_not_autoloaded_table') {
				return [
					{ name: 'name' },
					{ name: 'source' },
					{ name: 'calls', searchable: false },
					{ name: 'actions', searchable: false, orderable: false }
				]
			} else if (selector === '#all_options_table') {
				return [
				{ name: 'name', data: 'name' },
				{ name: 'source', data: 'plugin' },
				{
					name: 'size',
					data: 'size',
					searchable: false,
					render: data => '<span class="num">' + data + '</span>'
				},
				{ name: 'autoload', data: 'autoload', className: 'autoload', searchable: false },
				{
					name: 'value',
					data: 'value',
					render: (data, type, row) => renderValueColumn( row ),
					orderable: false,
					searchable: false,
					className: 'actions'
				}
				];
			}

			return commonColumns;
		}

		/**
		 * Sets up the column filters for the DataTable.
		 */
		function setupColumnFilters() {
			const column = this;
			const select = document.createElement( 'select' );
			select.add( new Option( aaaMetaOptimizer.i18n.filterBySource, '', true, true ) );
			column.footer().replaceChildren( select );

			select.addEventListener(
				'change',
				function () {
					column.search( select.value, { exact: true } ).draw();
				}
			);

			column.data().unique().sort().each(
				function (d) {
					select.add( new Option( d ) );
				}
			);
		}

		/**
		 * Renders the value column for a row.
		 *
		 * @param {Object} row - The row data.
		 *
		 * @returns {string} - The HTML for the value column.
		 */
		function renderValueColumn(row) {
			const popoverContent = '<div id="popover_' + row.name + '" popover class="aaa-option-optimizer-popover">' +
			'<button class="aaa-option-optimizer-popover__close" popovertarget="popover_' + row.name + '" popovertargetaction="hide">X</button>' +
			'<p><strong>Value of <code>' + row.name + '</code></strong></p>' +
			'<pre>' + row.value + '</pre>' +
			'</div>';

			const actions = [
				'<button class="button dashicon" popovertarget="popover_' + row.name + '"><span class="dashicons dashicons-search"></span>' + aaaMetaOptimizer.i18n.showValue + '</button>',
				popoverContent,
				row.autoload === 'no' ?
					'<button class="button dashicon add-autoload" data-option="' + row.name + '"><span class="dashicons dashicons-plus"></span>' + aaaMetaOptimizer.i18n.addAutoload + '</button>' :
					'<button class="button dashicon remove-autoload" data-option="' + row.name + '"><span class="dashicons dashicons-minus"></span>' + aaaMetaOptimizer.i18n.removeAutoload + '</button>',
					'<button class="button button-delete delete-option" data-option="' + row.name + '"><span class="dashicons dashicons-trash"></span>' + aaaMetaOptimizer.i18n.deleteOption + '</button >'
			];

			return actions.join( '' );
		}

		$( '#aaa-meta-reset-data' ).on(
			'click',
			function (e) {
				e.preventDefault();
				$.ajax(
					{
						url: aaaMetaOptimizer.root + 'aaa-meta-optimizer/v1/reset',
						method: 'POST',
						beforeSend: xhr => xhr.setRequestHeader( 'X-WP-Nonce', aaaMetaOptimizer.nonce ),
						success: response => window.location = window.location.href + '&tracking_reset=true',
						error: response => console.error(
							'Failed to reset tracking.',
							response
						)
					}
				);
			}
		);

		/**
		 * Handles the table actions (add-autoload, remove-autoload, delete-option).
		 *
		 * @param {Event} e - The click event.
		 */
		function handleTableActions(e) {
			e.preventDefault();
			const button     = $( this );
			const table      = button.closest( 'table' ).DataTable();
			const optionName = button.data( 'option' );

			let requestData = { option_name: optionName };
			let action      = '';
			let route       = '';

			if ( button.hasClass( 'create-option-false' ) ) {
				action = route = 'create-option-false';
			} else if ( button.hasClass( 'delete-option' ) ) {
				action = route = 'delete-option';
			} else {
				action               = button.hasClass( 'add-autoload' ) ? 'add-autoload' : 'remove-autoload';
				route                = 'update-autoload';
				requestData.autoload = ( action === 'add-autoload' ) ? 'yes' : 'no';
			}

			$.ajax(
				{
					url: aaaMetaOptimizer.root + 'aaa-option-optimizer/v1/' + route,
					method: 'POST',
					beforeSend: xhr => xhr.setRequestHeader( 'X-WP-Nonce', aaaMetaOptimizer.nonce ),
					data: requestData,
					success: response => updateRowOnSuccess( response, table, optionName, action ),
					error: response => console.error(
						'Failed to ' + action + ' for ' + optionName + '.',
						response
					)
				}
			);
		}

		/**
		 * Updates the row on successful AJAX response.
		 *
		 * @param {Object} response - The AJAX response.
		 * @param {DataTable} table - The DataTable instance.
		 * @param {string} optionName - The option name.
		 * @param {string} action - The action performed.
		 */
		function updateRowOnSuccess(response, table, optionName, action) {
			if ( action === 'delete-option' || action === 'create-option-false' ) {
				table.row( 'tr#option_' + optionName ).remove().draw( 'page' );
			} else if ( action === 'add-autoload' || action === 'remove-autoload' ) {
				const autoloadStatus = action === 'add-autoload' ? 'yes' : 'no';
				const buttonHTML     = action === 'add-autoload' ?
				'<button class="button dashicon remove-autoload" data-option="' + optionName + '"><span class="dashicons dashicons-minus"></span>' + aaaMetaOptimizer.i18n.removeAutoload + '</button>':
				'<button class="button dashicon add-autoload" data-option="' + optionName + '"><span class="dashicons dashicons-plus"></span>' + aaaMetaOptimizer.i18n.addAutoload + '</button>';

				$( 'tr#option_' + optionName ).find( 'td.autoload' ).text( autoloadStatus );
				const oldButton = 'button.' + ( action === 'add-autoload' ? 'add' : 'remove' ) + '-autoload';
				$( 'tr#option_' + optionName + ' ' + oldButton ).replaceWith( buttonHTML );
			}
		}

		// AJAX Event Handling (add-autoload, remove-autoload, delete-option).
		$( 'table tbody' ).on( 'click', '.add-autoload, .remove-autoload, .delete-option, .create-option-false', handleTableActions );

		// Initialize data tables.
		tablesToInitialize.forEach(
			function (selector) {
				if ($( selector ).length) {
					initializeDataTable( selector );
				}
			}
		);
	}
);
