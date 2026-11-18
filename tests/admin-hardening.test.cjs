/* Regression tests for the real admin event handlers, without a browser. */
const assert = require( 'node:assert/strict' );
const { readFileSync } = require( 'node:fs' );
const { join } = require( 'node:path' );
const { runInNewContext } = require( 'node:vm' );
const { test } = require( 'node:test' );

function setup( confirmDelete ) {
	const handlers = new Map();
	const requests = [];
	const rowIds = [];
	const alerts = [];
	const window = { confirm: () => confirmDelete, location: { href: 'http://example.test/admin' } };
	const row = { remove: () => row, draw: () => row };
	const table = {
		table: () => ( { node: () => ( { id: 'test_table' } ) } ),
		row: ( id ) => { rowIds.push( id ); return row; },
		ajax: { reload() {} },
	};
	const selection = [];
	const tableElement = {
		DataTable: () => table,
		find: () => selection,
	};
	function jQuery( selector ) {
		if ( typeof selector === 'object' && selector !== null && selector.attr ) {
			return selector;
		}
		if ( selector === '#test_table' ) {
			return tableElement;
		}
		const stub = {
			length: 0,
			hide: () => stub,
			ready: ( callback ) => callback(),
			on: ( event, filter, callback ) => {
				handlers.set( selector + ':' + event + ':' + filter, callback || filter );
				return stub;
			},
		};
		return stub;
	}
	jQuery.ajax = ( request ) => requests.push( request );
	runInNewContext( readFileSync( join( __dirname, '../js/admin-script.js' ), 'utf8' ), {
		jQuery,
		document: {},
		window,
		aaaOptionOptimizer: { root: '/', nonce: 'test', i18n: { confirmDelete: 'Delete?', selectedOptions: 'selected' } },
		alert: ( message ) => alerts.push( message ),
	} );
	const actionHandler = handlers.get( 'table tbody:click:.add-autoload, .remove-autoload, .delete-option, .create-option-false' );
	const bulkHandler = handlers.get( '.aaa-option-optimizer-tabs:click:.aaaoo-apply-bulk-action' );
	function button( name, action ) {
		return {
			attr: () => name,
			data: ( key ) => { assert.equal( key, 'table' ); return '#test_table'; },
			closest: () => tableElement,
			hasClass: ( candidate ) => candidate === action,
			siblings: () => ( { val: () => action } ),
		};
	}
	return { actionHandler, bulkHandler, button, selection, requests, rowIds, alerts, window, handlers };
}

test( 'single actions preserve numeric and JSON-like identifiers', () => {
	const ui = setup( true );
	for ( const name of [ '123', 'true', '["foo"]', 'a  b', 'a<b>c', 'literal%20name' ] ) {
		ui.actionHandler.call( ui.button( name, 'delete-option' ), { preventDefault() {} } );
		const request = ui.requests.at( -1 );
		assert.equal( request.data.option_name, name );
		assert.equal( typeof request.data.option_name, 'string' );
		request.success( { success: true } );
		assert.ok( ui.rowIds.at( -1 ).startsWith( '#test_table-option_' ) );
	}
} );

test( 'cancelling single deletion sends no request', () => {
	const ui = setup( false );
	ui.actionHandler.call( ui.button( 'plugin_option', 'delete-option' ), { preventDefault() {} } );
	assert.equal( ui.requests.length, 0 );
} );

test( 'cancelling bulk deletion sends no request', () => {
	const ui = setup( false );
	ui.selection.push( { getAttribute: () => '123' } );
	ui.bulkHandler.call( ui.button( '', 'delete' ), { preventDefault() {} } );
	assert.equal( ui.requests.length, 0 );
} );

test( 'confirmed bulk deletion preserves identifiers', () => {
	const ui = setup( true );
	const names = [ '123', '["foo"]', 'a<b>c' ];
	ui.selection.push( ...names.map( ( name ) => ( { getAttribute: () => name } ) ) );
	ui.bulkHandler.call( ui.button( '', 'delete' ), { preventDefault() {} } );
	assert.deepEqual( Array.from( ui.requests[ 0 ].data.option_names ), names );
} );


test( 'failed reset displays the server error without redirecting to success', () => {
	const ui = setup( true );
	const resetHandler = Array.from( ui.handlers ).find( ( [ key ] ) => key.startsWith( '#aaa-option-reset-data:click:' ) )[ 1 ];
	resetHandler( { preventDefault() {} } );
	ui.requests[ 0 ].error( { responseJSON: { message: 'Tracking could not be reset.' } } );
	assert.deepEqual( ui.alerts, [ 'Tracking could not be reset.' ] );
	assert.equal( ui.window.location.href, 'http://example.test/admin' );
} );
