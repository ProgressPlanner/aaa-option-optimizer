/* global jQuery, aaaOptionOptimizer, Option, DataTable, alert */

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
	 * Generate row ID for an option name.
	 *
	 * @param {string} optionName - The option name.
	 * @return {string} The row ID.
	 */
	function generateRowId( optionName ) {
		return 'option_' + optionName.replace( /\./g, '_' );
	}

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
			rowId( data ) {
				return generateRowId( data.name );
			},
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
			options.serverSide = true;
			options.processing = true;
			options.language = {
				sZeroRecords: aaaOptionOptimizer.i18n.noAutoloadedButNotUsed,
			};
			options.initComplete = function () {
				getBulkActionsForm( selector, [ 'autoload-off' ] ).call( this );
				this.api().columns( 'source:name' ).every( setupColumnFilters );
			};
			options.order = [ [ 1, 'asc' ] ]; // Order by 2nd column, first column is checkbox.
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
			options.serverSide = true;
			options.processing = true;
			options.language = {
				sZeroRecords: aaaOptionOptimizer.i18n.noUsedButNotAutoloaded,
			};
			options.initComplete = function () {
				getBulkActionsForm( selector, [ 'autoload-on' ] ).call( this );
				this.api().columns( 'source:name' ).every( setupColumnFilters );
			};
			options.order = [ [ 1, 'asc' ] ]; // Order by 2nd column, first column is checkbox.
		}

		if ( selector === '#requested_do_not_exist_table' ) {
			options.ajax = {
				url: `${ aaaOptionOptimizer.root }aaa-option-optimizer/v1/options-that-do-not-exist`,
				headers: { 'X-WP-Nonce': aaaOptionOptimizer.nonce },
				type: 'GET',
				dataSrc: 'data',
			};
			options.serverSide = true;
			options.processing = true;
		}

		if ( selector === '#all_options_table' ) {
			options.ajax = {
				url: `${ aaaOptionOptimizer.root }aaa-option-optimizer/v1/all-options`,
				headers: { 'X-WP-Nonce': aaaOptionOptimizer.nonce },
				type: 'GET',
				dataSrc: 'data',
			};
			options.initComplete = function () {
				getBulkActionsForm( selector, [
					'autoload-on',
					'autoload-off',
				] ).call( this );
				this.api().columns( 'source:name' ).every( setupColumnFilters );
			};
			options.order = [ [ 1, 'asc' ] ]; // Order by 2nd column, first column is checkbox.
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
			{
				name: 'checkbox',
				data: 'name',
				render: ( data, type, row ) => renderCheckboxColumn( row ),
				orderable: false,
				searchable: false,
				className: 'select-all',
			},
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
				{
					name: 'checkbox',
					data: 'name',
					render: ( data, type, row ) => renderCheckboxColumn( row ),
					orderable: false,
					searchable: false,
					className: 'select-all',
				},
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
				{
					name: 'checkbox',
					data: 'name',
					render: ( data, type, row ) => renderCheckboxColumn( row ),
					orderable: false,
					searchable: false,
					className: 'select-all',
				},
				{ name: 'name', data: 'name' },
				{ name: 'source', data: 'plugin' },
				{
					name: 'size',
					data: 'size',
					searchable: false,
					render: ( data ) => `<span class="num">${ data }</span>`,
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
		const popoverContent = `<div id="popover_${ row.name }" popover class="aaa-option-optimizer-popover">
			<button class="aaa-option-optimizer-popover__close" popovertarget="popover_${ row.name }" popovertargetaction="hide">X</button>
			<p><strong>Value of <code>${ row.name }</code></strong></p>
			<pre>${ row.value }</pre>
		</div>`;

		const actions = [
			`<button class="button dashicon" popovertarget="popover_${ row.name }">
				<span class="dashicons dashicons-search"></span>
				${ aaaOptionOptimizer.i18n.showValue }
			</button>`,
			popoverContent,
			row.autoload === 'no'
				? `<button class="button dashicon add-autoload" data-option="${ row.name }">
					<span class="dashicons dashicons-plus"></span>
					${ aaaOptionOptimizer.i18n.addAutoload }
				</button>`
				: `<button class="button dashicon remove-autoload" data-option="${ row.name }">
					<span class="dashicons dashicons-minus"></span>
					${ aaaOptionOptimizer.i18n.removeAutoload }
				</button>`,
			`<button class="button button-delete delete-option" data-option="${ row.name }">
				<span class="dashicons dashicons-trash"></span>
				${ aaaOptionOptimizer.i18n.deleteOption }
			</button>`,
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
		return `<button class="button button-primary create-option-false" data-option="${ row.name }">
				${ aaaOptionOptimizer.i18n.createOptionFalse }
			</button>`;
	}

	/**
	 * Renders the checkbox column for a row.
	 *
	 * @param {Object} row - The row data.
	 *
	 * @return {string} - The HTML for the value column.
	 */
	function renderCheckboxColumn( row ) {
		return `<label for="select-option-${ row.name }">
				<input type="checkbox" id="select-option-${ row.name }" class="select-option" data-option="${ row.name }">
			</label>`;
	}

	jQuery( '#aaa-option-reset-data' ).on( 'click', function ( e ) {
		e.preventDefault();
		jQuery.ajax( {
			url: `${ aaaOptionOptimizer.root }aaa-option-optimizer/v1/reset`,
			method: 'POST',
			beforeSend: ( xhr ) =>
				xhr.setRequestHeader( 'X-WP-Nonce', aaaOptionOptimizer.nonce ),
			success: (
				response // eslint-disable-line no-unused-vars
			) =>
				( window.location = `${ window.location.href }&tracking_reset=true` ),
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
			url: `${ aaaOptionOptimizer.root }aaa-option-optimizer/v1/${ route }`,
			method: 'POST',
			beforeSend: ( xhr ) =>
				xhr.setRequestHeader( 'X-WP-Nonce', aaaOptionOptimizer.nonce ),
			data: requestData,
			success: ( response ) =>
				updateRowOnSuccess( response, table, optionName, action ),
			error: ( response ) =>
				// eslint-disable-next-line no-console
				console.error(
					`Failed to ${ action } for ${ optionName }.`,
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
		// Get the row ID for the option name.
		const rowId = generateRowId( optionName );
		if ( action === 'delete-option' || action === 'create-option-false' ) {
			table
				.row( 'tr#' + rowId )
				.remove()
				.draw( 'full-hold' );
		} else if (
			action === 'add-autoload' ||
			action === 'remove-autoload'
		) {
			const autoloadStatus = action === 'add-autoload' ? 'yes' : 'no';
			const buttonHTML =
				action === 'add-autoload'
					? `<button class="button dashicon remove-autoload" data-option="${ optionName }">
						<span class="dashicons dashicons-minus"></span>
						${ aaaOptionOptimizer.i18n.removeAutoload }
					</button>`
					: `<button class="button dashicon add-autoload" data-option="${ optionName }">
						<span class="dashicons dashicons-plus"></span>
						${ aaaOptionOptimizer.i18n.addAutoload }
					</button>`;

			jQuery( `tr#${ rowId }` )
				.find( 'td.autoload' )
				.text( autoloadStatus );
			const oldButton = `button.${
				action === 'add-autoload' ? 'add' : 'remove'
			}-autoload`;
			jQuery( `tr#${ rowId } ${ oldButton }` ).replaceWith( buttonHTML );
		}
	}

	// AJAX Event Handling (add-autoload, remove-autoload, delete-option).
	jQuery( 'table tbody' ).on(
		'click',
		'.add-autoload, .remove-autoload, .delete-option, .create-option-false',
		handleTableActions
	);

	// Select all options.
	jQuery( '.select-all-checkbox' ).on( 'change', function () {
		const table = jQuery( this ).closest( 'table' );
		const selectValue = jQuery( this ).prop( 'checked' );
		const selectedOptions = table.find( 'input.select-option' );
		selectedOptions.prop( 'checked', selectValue );
	} );

	// Generates bulk actions form for DataTable.
	function getBulkActionsForm( selector, options ) {
		return function () {
			const container = jQuery( this.api().table().container() );

			const form = jQuery(
				'<form class="aaaoo-bulk-form" action="#" method="post" style="display:flex;gap:10px;"></form>'
			);

			let selectOptions = '';

			if ( options.includes( 'autoload-on' ) ) {
				selectOptions = `<option value="autoload-on">${ aaaOptionOptimizer.i18n.addAutoload }</option>`;
			}

			if ( options.includes( 'autoload-off' ) ) {
				selectOptions += `<option value="autoload-off">${ aaaOptionOptimizer.i18n.removeAutoload }</option>`;
			}

			const select = jQuery(
				`<select class="aaaoo-bulk-select">
					<option value="">${ aaaOptionOptimizer.i18n.bulkActions }</option>
					${ selectOptions }
					<option value="delete">${ aaaOptionOptimizer.i18n.delete }</option>
				</select>`
			);

			const button = jQuery(
				`<button type="submit" class="button aaaoo-apply-bulk-action" data-table="${ selector }">
					${ aaaOptionOptimizer.i18n.apply }
				</button>`
			);

			form.append( select, button );

			// Add the form to the .dt-start cell
			container.find( '.dt-layout-cell.dt-start ' ).prepend( form );

			// Move .dt-length to .dt-layout-cell.dt-end
			// const lengthSelector = container.find(".dt-length"); // same as div.dt-length
			// const targetEndCell = container.find(".dt-layout-cell.dt-end");
			// if (lengthSelector.length && targetEndCell.length) {
			// 	targetEndCell.append(lengthSelector);
			// }
		};
	}

	// Apply bulk action.
	jQuery( '.aaa-option-optimizer-tabs' ).on(
		'click',
		'.aaaoo-apply-bulk-action',
		function ( e ) {
			e.preventDefault();
			const button = jQuery( this );
			const select = jQuery( button ).siblings( '.aaaoo-bulk-select' );
			const bulkAction = select.val();

			if ( ! bulkAction ) {
				alert( aaaOptionOptimizer.i18n.noBulkActionSelected ); // eslint-disable-line no-alert
				return;
			}

			const table = jQuery( button.data( 'table' ) );
			const selectedOptions = table.find( 'input.select-option:checked' );
			if ( selectedOptions.length === 0 ) {
				alert( aaaOptionOptimizer.i18n.noOptionsSelected ); // eslint-disable-line no-alert
				return;
			}

			// For now we only have delete in bulk action.

			const requestData = {
				option_names: Array.from( selectedOptions ).map( ( option ) =>
					option.getAttribute( 'data-option' )
				),
			};

			const endpoint =
				'delete' === bulkAction
					? 'delete-options'
					: 'set-autoload-options';

			if ( bulkAction !== 'delete' ) {
				requestData.autoload =
					bulkAction === 'autoload-on' ? 'yes' : 'no';
			}

			jQuery.ajax( {
				url:
					aaaOptionOptimizer.root +
					'aaa-option-optimizer/v1/' +
					endpoint,
				method: 'POST',
				beforeSend: ( xhr ) =>
					xhr.setRequestHeader(
						'X-WP-Nonce',
						aaaOptionOptimizer.nonce
					),
				data: requestData,
				success: () => {
					const dt = table.DataTable();

					requestData.option_names.forEach( ( optionName ) => {
						dt.row( 'tr#option_' + optionName ).remove();
					} );

					dt.draw( 'full-hold' );

					// Clear the select-all checkbox.
					table
						.find( '.select-all-checkbox' )
						.prop( 'checked', false );
				},
				error: ( response ) => {
					// eslint-disable-next-line no-console
					console.error( 'Failed to delete options.', response );
				},
			} );
		}
	);

	// Initialize data tables.
	tablesToInitialize.forEach( function ( selector ) {
		if ( jQuery( selector ).length ) {
			initializeDataTable( selector );
		}
	} );
} );
