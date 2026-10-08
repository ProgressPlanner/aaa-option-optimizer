/* global jQuery, aaaOptionOptimizer, Option, DataTable, alert, Blob, URL, FileReader */

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
		'#quarantine_table',
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
	 * Store sources (plugin names) for each table from AJAX responses.
	 *
	 * @type {Object}
	 */
	const tableSources = {};

	/**
	 * Creates a column filter setup function bound to a specific table selector.
	 *
	 * @param {string} tableSelector - The table selector.
	 * @return {Function} The filter setup function.
	 */
	function createColumnFilterSetup( tableSelector ) {
		return function () {
			setupColumnFilters.call( this, tableSelector );
		};
	}

	/**
	 * Initializes the DataTable for the given selector.
	 *
	 * @param {string} selector - The table selector.
	 */
	function initializeDataTable( selector ) {
		const filterSetup = createColumnFilterSetup( selector );

		const options = {
			pageLength: 25,
			autoWidth: false,
			responsive: true,
			columns: getColumns( selector ),
			rowId( data ) {
				return generateRowId( data.name );
			},
			initComplete() {
				this.api().columns( 'source:name' ).every( filterSetup );
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
				dataSrc( json ) {
					tableSources[ selector ] = json.sources || [];
					return json.data;
				},
			};
			options.serverSide = true;
			options.processing = true;
			options.language = {
				sZeroRecords: aaaOptionOptimizer.i18n.noAutoloadedButNotUsed,
			};
			options.initComplete = function () {
				getBulkActionsForm( selector, [ 'autoload-off' ] ).call( this );
				this.api().columns( 'source:name' ).every( filterSetup );
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
				dataSrc( json ) {
					tableSources[ selector ] = json.sources || [];
					return json.data;
				},
			};
			options.serverSide = true;
			options.processing = true;
			options.language = {
				sZeroRecords: aaaOptionOptimizer.i18n.noUsedButNotAutoloaded,
			};
			options.initComplete = function () {
				getBulkActionsForm( selector, [ 'autoload-on' ] ).call( this );
				this.api().columns( 'source:name' ).every( filterSetup );
			};
			options.order = [ [ 1, 'asc' ] ]; // Order by 2nd column, first column is checkbox.
		}

		if ( selector === '#requested_do_not_exist_table' ) {
			options.ajax = {
				url: `${ aaaOptionOptimizer.root }aaa-option-optimizer/v1/options-that-do-not-exist`,
				headers: { 'X-WP-Nonce': aaaOptionOptimizer.nonce },
				type: 'GET',
				dataSrc( json ) {
					tableSources[ selector ] = json.sources || [];
					return json.data;
				},
			};
			options.serverSide = true;
			options.processing = true;
			options.initComplete = function () {
				this.api().columns( 'source:name' ).every( filterSetup );
			};
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
				this.api().columns( 'source:name' ).every( filterSetup );
			};
			options.order = [ [ 1, 'asc' ] ]; // Order by 2nd column, first column is checkbox.
		}

		if ( selector === '#quarantine_table' ) {
			options.ajax = {
				url: `${ aaaOptionOptimizer.root }aaa-option-optimizer/v1/quarantine`,
				headers: { 'X-WP-Nonce': aaaOptionOptimizer.nonce },
				type: 'GET',
				dataSrc: 'data',
			};
			options.columns = [
				{ name: 'name', data: 'name' },
				{ name: 'size', data: 'size', searchable: false },
				{
					name: 'autoload',
					data: 'autoload',
					searchable: false,
				},
				{
					name: 'quarantined_at',
					data: 'quarantined_at',
					searchable: false,
				},
				{
					name: 'expires_at',
					data: 'expires_at',
					searchable: false,
				},
				{
					name: 'actions',
					data: 'name',
					render: ( data, type, row ) =>
						renderQuarantineActionsColumn( row ),
					orderable: false,
					searchable: false,
					className: 'actions',
				},
			];
			options.order = [ [ 3, 'desc' ] ];
			options.language = {
				sZeroRecords: aaaOptionOptimizer.i18n.quarantineEmpty,
			};
			delete options.initComplete;
		}

		new DataTable( selector, options ).columns.adjust().responsive.recalc();
	}

	/**
	 * Renders the Actions column for a quarantine row.
	 *
	 * @param {Object} row - The row data.
	 * @return {string} HTML.
	 */
	function renderQuarantineActionsColumn( row ) {
		return `<button class="button dashicon restore-option" data-option="${ row.name }">
				<span class="dashicons dashicons-undo"></span>
				${ aaaOptionOptimizer.i18n.restore }
			</button>
			<button class="button button-delete permanently-delete" data-option="${ row.name }">
				<span class="dashicons dashicons-trash"></span>
				${ aaaOptionOptimizer.i18n.permanentlyDelete }
			</button>`;
	}

	/**
	 * Handles quarantine table actions (restore, permanently-delete).
	 *
	 * @param {Event} e - The click event.
	 */
	function handleQuarantineActions( e ) {
		e.preventDefault();
		const button = jQuery( this );
		const optionName = button.data( 'option' );
		const dt = jQuery( '#quarantine_table' ).DataTable();

		let route;
		if ( button.hasClass( 'restore-option' ) ) {
			route = 'quarantine/restore';
		} else {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( aaaOptionOptimizer.i18n.confirmPermanentDelete ) ) {
				return;
			}
			route = 'quarantine/delete';
		}

		jQuery.ajax( {
			url: `${ aaaOptionOptimizer.root }aaa-option-optimizer/v1/${ route }`,
			method: 'POST',
			beforeSend: ( xhr ) =>
				xhr.setRequestHeader( 'X-WP-Nonce', aaaOptionOptimizer.nonce ),
			data: { option_name: optionName },
			success: () => {
				dt.ajax.reload( null, false );
			},
			error: ( response ) =>
				// eslint-disable-next-line no-console
				console.error( 'Quarantine action failed.', response ),
		} );
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
	 *
	 * @param {string} tableSelector - The table selector to get sources from.
	 */
	function setupColumnFilters( tableSelector ) {
		const column = this;
		const select = document.createElement( 'select' );
		select.add(
			new Option( aaaOptionOptimizer.i18n.filterBySource, '', true, true )
		);
		column.footer().replaceChildren( select );

		select.addEventListener( 'change', function () {
			column.search( select.value, { exact: true } ).draw();
		} );

		// Use sources from AJAX response if available (for server-side processing),
		// otherwise fall back to column data (for client-side processing).
		const sources = tableSources[ tableSelector ];
		if ( sources && sources.length > 0 ) {
			sources.forEach( function ( source ) {
				select.add( new Option( source ) );
			} );
		} else {
			column
				.data()
				.unique()
				.sort()
				.each( function ( d ) {
					select.add( new Option( d ) );
				} );
		}
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

		const protectedRow = isProtected( row.name );
		const autoloadBtn = protectedRow
			? ''
			: row.autoload === 'no'
				? `<button class="button dashicon add-autoload" data-option="${ row.name }">
					<span class="dashicons dashicons-plus"></span>
					${ aaaOptionOptimizer.i18n.addAutoload }
				</button>`
				: `<button class="button dashicon remove-autoload" data-option="${ row.name }">
					<span class="dashicons dashicons-minus"></span>
					${ aaaOptionOptimizer.i18n.removeAutoload }
				</button>`;

		const deleteBtn = protectedRow
			? `<span class="button dashicon button-disabled aaa-protected" title="${ aaaOptionOptimizer.i18n.protectedTooltip }" aria-disabled="true">
				<span class="dashicons dashicons-lock"></span>
				${ aaaOptionOptimizer.i18n.deleteOption }
			</span>`
			: `<button class="button button-delete delete-option" data-option="${ row.name }">
				<span class="dashicons dashicons-trash"></span>
				${ aaaOptionOptimizer.i18n.deleteOption }
			</button>`;

		const exportBtn = `<button class="button dashicon export-option" data-option="${ row.name }">
				<span class="dashicons dashicons-download"></span>
				${ aaaOptionOptimizer.i18n.export }
			</button>`;

		const actions = [
			`<button class="button dashicon" popovertarget="popover_${ row.name }">
				<span class="dashicons dashicons-search"></span>
				${ aaaOptionOptimizer.i18n.showValue }
			</button>`,
			popoverContent,
			autoloadBtn,
			deleteBtn,
			exportBtn,
		];

		return actions.join( '' );
	}

	/**
	 * Checks whether the given option name is protected according to the
	 * server-localized lookup map and prefix list.
	 *
	 * @param {string} optionName - The option name.
	 * @return {boolean} - Whether the option is protected.
	 */
	function isProtected( optionName ) {
		const map = aaaOptionOptimizer.protectedOptions || {};
		if ( map[ optionName ] ) {
			return true;
		}
		const prefixes = aaaOptionOptimizer.protectedPrefixes || [];
		for ( let i = 0; i < prefixes.length; i++ ) {
			if ( optionName.indexOf( prefixes[ i ] ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Triggers a browser download of the given JSON payload.
	 *
	 * @param {Object} payload  - The JSON payload to download.
	 * @param {string} filename - Suggested filename.
	 */
	function downloadJson( payload, filename ) {
		const blob = new Blob( [ JSON.stringify( payload, null, 2 ) ], {
			type: 'application/json',
		} );
		const url = URL.createObjectURL( blob );
		const a = document.createElement( 'a' );
		a.href = url;
		a.download = filename || 'aaa-option-optimizer-export.json';
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		URL.revokeObjectURL( url );
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

		// Per-row export bypasses the standard AJAX/update pattern.
		if ( button.hasClass( 'export-option' ) ) {
			exportOptions( [ optionName ] );
			return;
		}

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
	 * Requests an export from the REST API and triggers a browser download.
	 *
	 * @param {string[]} optionNames - The option names to export.
	 */
	function exportOptions( optionNames ) {
		jQuery.ajax( {
			url: `${ aaaOptionOptimizer.root }aaa-option-optimizer/v1/export`,
			method: 'POST',
			beforeSend: ( xhr ) =>
				xhr.setRequestHeader( 'X-WP-Nonce', aaaOptionOptimizer.nonce ),
			data: { option_names: optionNames },
			success: ( payload, status, xhr ) => {
				const filename =
					xhr.getResponseHeader( 'X-AAAOO-Filename' ) ||
					'aaa-option-optimizer-export.json';
				downloadJson( payload, filename );
			},
			error: ( response ) =>
				// eslint-disable-next-line no-console
				console.error( 'Failed to export options.', response ),
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

	// AJAX Event Handling (add-autoload, remove-autoload, delete-option, export-option).
	jQuery( 'table tbody' ).on(
		'click',
		'.add-autoload, .remove-autoload, .delete-option, .create-option-false, .export-option',
		handleTableActions
	);

	// Quarantine actions (restore / permanently delete) live on the quarantine table only.
	jQuery( document ).on(
		'click',
		'#quarantine_table .restore-option, #quarantine_table .permanently-delete',
		handleQuarantineActions
	);

	// Select all options.
	jQuery( '.select-all-checkbox' ).on( 'change', function () {
		const table = jQuery( this ).closest( 'table' );
		const selectValue = jQuery( this ).prop( 'checked' );
		const selectedOptions = table.find( 'input.select-option' );
		selectedOptions.prop( 'checked', selectValue );

		// Match the checked value in the other select-all checkbox (not this one).
		const otherSelectAll = table.find( '.select-all-checkbox' ).not( this );
		otherSelectAll.prop( 'checked', selectValue );
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
					<option value="export">${ aaaOptionOptimizer.i18n.exportSelected }</option>
				</select>`
			);

			const button = jQuery(
				`<button type="submit" class="button aaaoo-apply-bulk-action" data-table="${ selector }">
					${ aaaOptionOptimizer.i18n.apply }
				</button>`
			);

			form.append( select, button );

			// Add the form to the .dt-start cell
			container.find( '.dt-layout-cell.dt-layout-start' ).prepend( form );

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

			const requestData = {
				option_names: Array.from( selectedOptions ).map( ( option ) =>
					option.getAttribute( 'data-option' )
				),
			};

			// Bulk export bypasses the row-removal AJAX flow and just downloads.
			if ( bulkAction === 'export' ) {
				exportOptions( requestData.option_names );
				return;
			}

			// Warn before quarantining a large batch — quarantine is recoverable
			// but sifting through hundreds of rows to find a culprit is painful.
			if ( bulkAction === 'delete' && requestData.option_names.length > 25 ) {
				const msg = aaaOptionOptimizer.i18n.confirmBulkQuarantine.replace(
					'%d',
					requestData.option_names.length
				);
				// eslint-disable-next-line no-alert
				if ( ! window.confirm( msg ) ) {
					return;
				}
			}

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

	// Migration functionality.
	jQuery( '#aaa-start-migration' ).on( 'click', function ( e ) {
		e.preventDefault();
		const button = jQuery( this );
		const progressContainer = jQuery( '#aaa-migration-progress' );
		const progressBar = jQuery( '#aaa-migration-progress-bar' );
		const statusText = jQuery( '#aaa-migration-status' );
		const total = aaaOptionOptimizer.migration.total;

		button.prop( 'disabled', true );
		progressContainer.show();
		statusText.text( aaaOptionOptimizer.i18n.migrating );

		/**
		 * Performs a single migration chunk via AJAX.
		 */
		function migrateChunk() {
			jQuery.ajax( {
				url:
					aaaOptionOptimizer.root +
					'aaa-option-optimizer/v1/migrate',
				method: 'POST',
				beforeSend: ( xhr ) =>
					xhr.setRequestHeader(
						'X-WP-Nonce',
						aaaOptionOptimizer.nonce
					),
				success( response ) {
					if ( ! response.success ) {
						statusText.text(
							response.message ||
								aaaOptionOptimizer.i18n.migrationError
						);
						button.prop( 'disabled', false );
						return;
					}

					const migrated = total - response.remaining;
					const percent = Math.round( ( migrated / total ) * 100 );

					progressBar.css( 'width', percent + '%' );
					statusText.text(
						aaaOptionOptimizer.i18n.migratedOf
							.replace( '%1$d', migrated )
							.replace( '%2$d', total )
					);

					if ( response.remaining > 0 ) {
						// Continue with next chunk.
						migrateChunk();
					} else {
						// Migration complete.
						statusText.text(
							aaaOptionOptimizer.i18n.migrationComplete
						);
						setTimeout( function () {
							window.location.reload();
						}, 1000 );
					}
				},
				error() {
					statusText.text( aaaOptionOptimizer.i18n.migrationError );
					button.prop( 'disabled', false );
				},
			} );
		}

		migrateChunk();
	} );

	// Import form handler.
	jQuery( '#aaa_import_form' ).on( 'submit', function ( e ) {
		e.preventDefault();

		const fileInput = document.getElementById( 'aaa_import_file' );
		const resultBox = jQuery( '#aaa_import_result' );
		const overwrite = jQuery( '#aaa_import_overwrite' ).is( ':checked' );

		resultBox.empty();

		if ( ! fileInput.files || ! fileInput.files[ 0 ] ) {
			resultBox.html(
				`<div class="notice notice-error"><p>${ aaaOptionOptimizer.i18n.importSelectFile }</p></div>`
			);
			return;
		}

		const reader = new FileReader();
		reader.onload = function ( ev ) {
			let payload;
			try {
				payload = JSON.parse( ev.target.result );
			} catch ( err ) {
				resultBox.html(
					`<div class="notice notice-error"><p>${ aaaOptionOptimizer.i18n.importInvalidJson }</p></div>`
				);
				return;
			}

			jQuery.ajax( {
				url: `${ aaaOptionOptimizer.root }aaa-option-optimizer/v1/import`,
				method: 'POST',
				beforeSend: ( xhr ) =>
					xhr.setRequestHeader(
						'X-WP-Nonce',
						aaaOptionOptimizer.nonce
					),
				contentType: 'application/json',
				data: JSON.stringify( { payload, overwrite } ),
				success: ( response ) => {
					const msg = aaaOptionOptimizer.i18n.importResult
						.replace( '%1$d', response.imported )
						.replace( '%2$d', response.skipped );
					let html = `<div class="notice notice-success"><p>${ msg }</p></div>`;
					if ( response.errors && response.errors.length ) {
						const rows = response.errors
							.map(
								( err ) =>
									`<li><code>${ err.option_name }</code>: ${ err.reason }</li>`
							)
							.join( '' );
						html += `<ul style="margin-left:18px;list-style:disc;">${ rows }</ul>`;
					}
					resultBox.html( html );
				},
				error: ( response ) => {
					const reason =
						( response.responseJSON && response.responseJSON.message ) ||
						'Import failed.';
					resultBox.html(
						`<div class="notice notice-error"><p>${ reason }</p></div>`
					);
				},
			} );
		};
		reader.readAsText( fileInput.files[ 0 ] );
	} );
} );
