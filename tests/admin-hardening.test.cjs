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

function setupReports( optionName = 'sample_option' ) {
	const handlers = new Map();
	const requests = [];
	const timers = new Map();
	let timerId = 0;
	function element( initial = '' ) {
		const values = new Map();
		let value = initial;
		const el = {
			length: 1,
			attr: ( key, next ) => next === undefined ? values.get( key ) : ( values.set( key, next ), el ),
			data: ( key, next ) => next === undefined ? values.get( key ) : ( values.set( key, next ), el ),
			removeData: ( key ) => { values.delete( key ); return el; },
			removeAttr: () => el,
			val: ( next ) => next === undefined ? value : ( value = next, el ),
			prop: ( key, next ) => next === undefined ? values.get( key ) : ( values.set( key, next ), el ),
			text: () => el,
			html: () => el,
			empty: () => el,
		};
		return el;
	}
	const input = element( 'old-plugin' );
	const submit = element();
	const list = element();
	const popover = element();
	popover.attr( 'data-option', optionName );
	input.closest = submit.closest = () => popover;
	popover.find = ( selector ) => ( {
		'.aaa-report-input': input,
		'.aaa-report-submit': submit,
		'.aaa-report-list': list,
	} )[ selector ] || element();
	const trigger = element();
	trigger.attr( 'popovertarget', 'report' );
	function jQuery( selector ) {
		if ( selector && typeof selector === 'object' && selector.attr ) return selector;
		const stub = {
			length: 0,
			hide: () => stub,
			ready: ( fn ) => fn(),
			on: ( event, filter, fn ) => { handlers.set( `${ event }:${ filter }`, fn || filter ); return stub; },
		};
		return stub;
	}
	jQuery.ajax = ( args ) => {
		const request = { args };
		requests.push( request );
		const deferred = {
			done( fn ) { request.done = fn; return deferred; },
			fail( fn ) { request.fail = fn; return deferred; },
		};
		return deferred;
	};
	runInNewContext( readFileSync( join( __dirname, '../js/admin-script.js' ), 'utf8' ), {
		jQuery,
		document: {
			getElementById: () => popover,
			createElement: () => ( { set innerHTML( value ) { this.value = value; } } ),
		},
		window: { location: { hostname: 'example.test' }, requestAnimationFrame() {} },
		aaaOptionOptimizer: { root: '/', reportUrl: 'https://reports.example/submit', hasRemoteConsent: true, i18n: {} },
		setTimeout: ( fn ) => { timers.set( ++timerId, fn ); return timerId; },
		clearTimeout: ( id ) => timers.delete( id ),
	} );
	return {
		input, submit, requests,
		open() { handlers.get( 'click:.aaa-report-trigger' ).call( trigger ); },
		edit( value ) {
			input.val( value );
			handlers.get( 'input:.aaa-report-input' ).call( input, { originalEvent: { inputType: 'insertText' } } );
		},
		send() { handlers.get( 'click:.aaa-report-submit' ).call( submit ); },
		verify() {
			for ( const [ id, fn ] of Array.from( timers ) ) { timers.delete( id ); fn(); }
		},
	};
}

test( 'editing a verified slug blocks submission until the new value is verified', () => {
	const ui = setupReports();
	ui.open();
	ui.requests[ 0 ].done( { name: 'Old Plugin' } );
	assert.equal( ui.submit.prop( 'disabled' ), false );
	ui.edit( 'new-plugin' );
	assert.equal( ui.submit.prop( 'disabled' ), true );
	ui.send();
	assert.equal( ui.requests.length, 1 );
	ui.verify();
	ui.requests[ 1 ].done( { name: 'New Plugin' } );
	ui.send();
	assert.equal( JSON.parse( ui.requests[ 2 ].args.data ).slug, 'new-plugin' );
} );

test( 'a response for the previous input cannot re-enable submission', () => {
	const ui = setupReports();
	ui.open();
	ui.edit( 'new-plugin' );
	ui.requests[ 0 ].done( { name: 'Old Plugin' } );
	assert.equal( ui.submit.prop( 'disabled' ), true );
	ui.send();
	assert.equal( ui.requests.length, 1 );
} );

test( 'reports preserve numeric and JSON-like option names as strings', () => {
	for ( const optionName of [ '123', 'true', '["foo"]', 'a<b>c' ] ) {
		const ui = setupReports( optionName );
		ui.open();
		ui.requests[ 0 ].done( { name: 'Old Plugin' } );
		ui.send();
		assert.equal( JSON.parse( ui.requests[ 1 ].args.data ).option_name, optionName );
	}
} );
