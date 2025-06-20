/* global jQuery, aaaOptionOptimizer, Option, DataTable */

/**
 * JavaScript for the admin page.
 *
 * @package
 */

/**
 * Initializes the data tables and sets up event handlers.
 */
jQuery( document ).ready( function () {
	/**
	 * Array of table selectors to initialize.
	 *
	 * @type {string[]}
	 */
	const tablesToInitialize = [
		'#unused_options_table',
		'#used_not_autoloaded_table',
		'#requested_do_not_exist_table',
	];

	jQuery( '#all_options_table' ).hide();
	jQuery( '#aaa_get_all_options' ).on( 'click', function ( e ) {
		e.preventDefault();
		jQuery( '#all_options_table' ).show();
		initializeDataTable( '#all_options_table' );
		jQuery( this ).hide();
	} );

	/**
	 * Initializes the DataTable for the given selector.
	 *
	 * @param {string} selector - The table selector.
	 */
	function initializeDataTable( selector ) {
		const options = {
			pageLength: 25,
			autoWidth: false,
			responsive: true,
			columns: getColumns( selector ),
			initComplete() {
				this.api().columns( 'source:name' ).every( setupColumnFilters );
			},
			language: aaaOptionOptimizer.i18n,
		};

		if ( selector === '#unused_options_table' ) {
			options.ajax = {
				url:
					aaaOptionOptimizer.root +
					'aaa-option-optimizer/v1/unused-options',
				headers: { 'X-WP-Nonce': aaaOptionOptimizer.nonce },
				type: 'GET',
				dataSrc: 'data',
			};
			options.rowId = 'row_id';
			options.serverSide = true;
			options.processing = true;
			options.language = {
				sZeroRecords: aaaOptionOptimizer.i18n.noAutoloadedButNotUsed,
			};
		}

		if ( selector === '#used_not_autoloaded_table' ) {
			options.ajax = {
				url:
					aaaOptionOptimizer.root +
					'aaa-option-optimizer/v1/used-not-autoloaded-options',
				headers: { 'X-WP-Nonce': aaaOptionOptimizer.nonce },
				type: 'GET',
				dataSrc: 'data',
			};
			options.rowId = 'row_id';
			options.serverSide = true;
			options.processing = true;
			options.language = {
				sZeroRecords: aaaOptionOptimizer.i18n.noUsedButNotAutoloaded,
			};
		}

		if ( selector === '#requested_do_not_exist_table' ) {
			options.ajax = {
				url:
					aaaOptionOptimizer.root +
					'aaa-option-optimizer/v1/options-that-do-not-exist',
				headers: { 'X-WP-Nonce': aaaOptionOptimizer.nonce },
				type: 'GET',
				dataSrc: 'data',
			};
			options.rowId = 'row_id';
			options.serverSide = true;
			options.processing = true;
		}

		if ( selector === '#all_options_table' ) {
			options.ajax = {
				url:
					aaaOptionOptimizer.root +
					'aaa-option-optimizer/v1/all-options',
				headers: { 'X-WP-Nonce': aaaOptionOptimizer.nonce },
				type: 'GET',
				dataSrc: 'data',
			};
			options.rowId = 'row_id';
		}

		new DataTable( selector, options ).columns.adjust().responsive.recalc();
	}

	/**
	 * Retrieves the columns configuration based on the selector.
	 *
	 * @param {string} selector - The table selector.
	 *
	 * @return {Object[]} - The columns configuration.
	 */
	function getColumns( selector ) {
		const commonColumns = [
			{ name: 'name', data: 'name' },
			{ name: 'source', data: 'plugin' },
			{ name: 'size', data: 'size', searchable: false },
			{
				name: 'autoload',
				data: 'autoload',
				className: 'autoload',
				searchable: false,
				orderable: false,
			},
			{
				name: 'value',
				data: 'value',
				render: ( data, type, row ) => renderValueColumn( row ),
				orderable: false,
				searchable: false,
				className: 'actions',
			},
		];

		if ( selector === '#requested_do_not_exist_table' ) {
			return [
				{ name: 'option', data: 'name' },
				{ name: 'source', data: 'plugin', searchable: false },
				{ name: 'calls', data: 'count', searchable: false },
				{
					name: 'option_name',
					data: 'option_name',
					render: ( data, type, row ) =>
						renderNonExistingOptionsColumn( row ),
					searchable: false,
					orderable: false,
					className: 'actions',
				},
			];
		} else if ( selector === '#used_not_autoloaded_table' ) {
			return [
				{ name: 'name', data: 'name' },
				{ name: 'source', data: 'plugin' },
				{ name: 'size', data: 'size', searchable: false },
				{
					name: 'autoload',
					data: 'autoload',
					className: 'autoload',
					searchable: false,
					orderable: false,
				},
				{ name: 'calls', data: 'count', searchable: false },
				{
					name: 'value',
					data: 'value',
					render: ( data, type, row ) => renderValueColumn( row ),
					orderable: false,
					searchable: false,
					className: 'actions',
				},
			];
		} else if ( selector === '#all_options_table' ) {
			return [
				{ name: 'name', data: 'name' },
				{ name: 'source', data: 'plugin' },
				{
					name: 'size',
					data: 'size',
					searchable: false,
					render: ( data ) => '<span class="num">' + data + '</span>',
				},
				{
					name: 'autoload',
					data: 'autoload',
					className: 'autoload',
					searchable: false,
				},
				{
					name: 'value',
					data: 'value',
					render: ( data, type, row ) => renderValueColumn( row ),
					orderable: false,
					searchable: false,
					className: 'actions',
				},
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
		select.add(
			new Option( aaaOptionOptimizer.i18n.filterBySource, '', true, true )
		);
		column.footer().replaceChildren( select );

		select.addEventListener( 'change', function () {
			column.search( select.value, { exact: true } ).draw();
		} );

		column
			.data()
			.unique()
			.sort()
			.each( function ( d ) {
				select.add( new Option( d ) );
			} );
	}

	/**
	 * Renders the value column for a row.
	 *
	 * @param {Object} row - The row data.
	 *
	 * @return {string} - The HTML for the value column.
	 */
	function renderValueColumn( row ) {
		const popoverContent =
			'<div id="popover_' +
			row.name +
			'" popover class="aaa-option-optimizer-popover">' +
			'<button class="aaa-option-optimizer-popover__close" popovertarget="popover_' +
			row.name +
			'" popovertargetaction="hide">X</button>' +
			'<p><strong>Value of <code>' +
			row.name +
			'</code></strong></p>' +
			'<pre>' +
			row.value +
			'</pre>' +
			'</div>';

		const actions = [
			'<button class="button dashicon" popovertarget="popover_' +
				row.name +
				'"><span class="dashicons dashicons-search"></span>' +
				aaaOptionOptimizer.i18n.showValue +
				'</button>',
			popoverContent,
			row.autoload === 'no'
				? '<button class="button dashicon add-autoload" data-option="' +
				  row.name +
				  '"><span class="dashicons dashicons-plus"></span>' +
				  aaaOptionOptimizer.i18n.addAutoload +
				  '</button>'
				: '<button class="button dashicon remove-autoload" data-option="' +
				  row.name +
				  '"><span class="dashicons dashicons-minus"></span>' +
				  aaaOptionOptimizer.i18n.removeAutoload +
				  '</button>',
			'<button class="button button-delete delete-option" data-option="' +
				row.name +
				'"><span class="dashicons dashicons-trash"></span>' +
				aaaOptionOptimizer.i18n.deleteOption +
				'</button >',
		];

		return actions.join( '' );
	}

	/**
	 * Renders the value column for a row.
	 *
	 * @param {Object} row - The row data.
	 *
	 * @return {string} - The HTML for the value column.
	 */
	function renderNonExistingOptionsColumn( row ) {
		return (
			'<button class="button button-primary create-option-false" data-option="' +
			row.name +
			'">' +
			aaaOptionOptimizer.i18n.createOptionFalse +
			'</button>'
		);
	}

	jQuery( '#aaa-option-reset-data' ).on( 'click', function ( e ) {
		e.preventDefault();
		jQuery.ajax( {
			url: aaaOptionOptimizer.root + 'aaa-option-optimizer/v1/reset',
			method: 'POST',
			beforeSend: ( xhr ) =>
				xhr.setRequestHeader( 'X-WP-Nonce', aaaOptionOptimizer.nonce ),
			success: (
				response // eslint-disable-line no-unused-vars
			) =>
				( window.location =
					window.location.href + '&tracking_reset=true' ),
			error: ( response ) =>
				console.error( 'Failed to reset tracking.', response ), // eslint-disable-line no-console
		} );
	} );

	/**
	 * Handles the table actions (add-autoload, remove-autoload, delete-option).
	 *
	 * @param {Event} e - The click event.
	 */
	function handleTableActions( e ) {
		e.preventDefault();
		const button = jQuery( this );
		const table = button.closest( 'table' ).DataTable();
		const optionName = button.data( 'option' );

		const requestData = { option_name: optionName };
		let action = '';
		let route = '';

		if ( button.hasClass( 'create-option-false' ) ) {
			action = route = 'create-option-false';
		} else if ( button.hasClass( 'delete-option' ) ) {
			action = route = 'delete-option';
		} else {
			action = button.hasClass( 'add-autoload' )
				? 'add-autoload'
				: 'remove-autoload';
			route = 'update-autoload';
			requestData.autoload = action === 'add-autoload' ? 'yes' : 'no';
		}

		jQuery.ajax( {
			url: aaaOptionOptimizer.root + 'aaa-option-optimizer/v1/' + route,
			method: 'POST',
			beforeSend: ( xhr ) =>
				xhr.setRequestHeader( 'X-WP-Nonce', aaaOptionOptimizer.nonce ),
			data: requestData,
			success: ( response ) =>
				updateRowOnSuccess( response, table, optionName, action ),
			error: ( response ) =>
				// eslint-disable-next-line no-console
				console.error(
					'Failed to ' + action + ' for ' + optionName + '.',
					response
				),
		} );
	}

	/**
	 * Updates the row on successful AJAX response.
	 *
	 * @param {Object}    response   - The AJAX response.
	 * @param {DataTable} table      - The DataTable instance.
	 * @param {string}    optionName - The option name.
	 * @param {string}    action     - The action performed.
	 */
	function updateRowOnSuccess( response, table, optionName, action ) {
		if ( action === 'delete-option' || action === 'create-option-false' ) {
			table
				.row( 'tr#option_' + optionName )
				.remove()
				.draw( 'full-hold' );
		} else if (
			action === 'add-autoload' ||
			action === 'remove-autoload'
		) {
			const autoloadStatus = action === 'add-autoload' ? 'yes' : 'no';
			const buttonHTML =
				action === 'add-autoload'
					? '<button class="button dashicon remove-autoload" data-option="' +
					  optionName +
					  '"><span class="dashicons dashicons-minus"></span>' +
					  aaaOptionOptimizer.i18n.removeAutoload +
					  '</button>'
					: '<button class="button dashicon add-autoload" data-option="' +
					  optionName +
					  '"><span class="dashicons dashicons-plus"></span>' +
					  aaaOptionOptimizer.i18n.addAutoload +
					  '</button>';

			jQuery( 'tr#option_' + optionName )
				.find( 'td.autoload' )
				.text( autoloadStatus );
			const oldButton =
				'button.' +
				( action === 'add-autoload' ? 'add' : 'remove' ) +
				'-autoload';
			jQuery( 'tr#option_' + optionName + ' ' + oldButton ).replaceWith(
				buttonHTML
			);
		}
	}

	// AJAX Event Handling (add-autoload, remove-autoload, delete-option).
	jQuery( 'table tbody' ).on(
		'click',
		'.add-autoload, .remove-autoload, .delete-option, .create-option-false',
		handleTableActions
	);

	// Initialize data tables.
	tablesToInitialize.forEach( function ( selector ) {
		if ( jQuery( selector ).length ) {
			initializeDataTable( selector );
		}
	} );
} );
