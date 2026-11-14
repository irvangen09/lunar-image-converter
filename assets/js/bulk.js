( function () {
	'use strict';

	// These values must match the status and scope values in the PHP code; a test compares them.
	const STATUS = {
		converted: 'converted',
		skipped: 'skipped',
		failed: 'failed',
		processing: 'processing',
	};

	const SCOPE = {
		new: 'new',
		retry: 'retry',
	};

	const MAX_LOG_LINES = 200;

	const data = window.lunarImageConverterBulk;
	const start = document.getElementById( 'lic-bulk-start' );

	if ( ! data || ! start ) {
		return;
	}

	const retry = document.getElementById( 'lic-bulk-retry' );
	const stop = document.getElementById( 'lic-bulk-stop' );
	const backup = document.getElementById( 'lic-bulk-backup' );
	const countsText = document.getElementById( 'lic-bulk-counts' );
	const progress = document.getElementById( 'lic-bulk-progress' );
	const summary = document.getElementById( 'lic-bulk-summary' );
	const log = document.getElementById( 'lic-bulk-log' );

	let counts = null;
	let running = false;
	let stopRequested = false;

	function format( template, ...values ) {
		return template.replace( /%(\d+)\$s/g, function ( match, index ) {
			return String( values[ index - 1 ] );
		} );
	}

	function formatBytes( bytes ) {
		if ( bytes >= 1048576 ) {
			return ( bytes / 1048576 ).toFixed( 1 ) + ' MB';
		}

		if ( bytes >= 1024 ) {
			return Math.round( bytes / 1024 ) + ' KB';
		}

		return bytes + ' B';
	}

	async function post( action, extra ) {
		const body = new URLSearchParams(
			Object.assign( { action, nonce: data.nonce }, extra )
		);
		let json;

		try {
			const response = await fetch( data.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} );

			json = await response.json();
		} catch {
			throw new Error( data.text.requestFailed );
		}

		if ( ! json || ! json.success ) {
			const message = json && json.data && json.data.message;
			throw new Error( message || data.text.requestFailed );
		}

		return json.data;
	}

	function retryCount() {
		return counts
			? counts[ STATUS.skipped ] +
					counts[ STATUS.failed ] +
					counts[ STATUS.processing ]
			: 0;
	}

	function updateButtons() {
		const allowed = backup.checked && ! running && counts !== null;

		start.disabled = ! allowed || counts.pending === 0;
		retry.disabled = ! allowed || retryCount() === 0;
		backup.disabled = running;
		stop.hidden = ! running;
	}

	function showCounts() {
		if ( ! counts ) {
			return;
		}

		const keys = [
			'pending',
			STATUS.converted,
			STATUS.skipped,
			STATUS.failed,
			STATUS.processing,
		];

		countsText.textContent = keys
			.filter( function ( key ) {
				return (
					key === 'pending' ||
					key === STATUS.converted ||
					counts[ key ] > 0
				);
			} )
			.map( function ( key ) {
				return data.labels[ key ] + ': ' + counts[ key ];
			} )
			.join( ' · ' );
	}

	async function refreshCounts() {
		try {
			counts = await post( data.actions.count, {} );
			showCounts();
		} catch ( error ) {
			countsText.textContent = error.message;
		}

		updateButtons();
	}

	function addLog( result ) {
		const label = data.labels[ result.status ] || result.status;
		const reason = data.reasons[ result.reason ] || '';
		const item = document.createElement( 'li' );

		item.textContent = reason
			? format(
					data.text.logLineReason,
					result.id,
					result.name,
					label,
					reason
				)
			: format( data.text.logLine, result.id, result.name, label );

		log.appendChild( item );

		while ( log.children.length > MAX_LOG_LINES ) {
			log.removeChild( log.firstChild );
		}

		item.scrollIntoView( { block: 'nearest' } );
	}

	async function run( scope ) {
		const total = SCOPE.retry === scope ? retryCount() : counts.pending;
		const tally = { converted: 0, skipped: 0, failed: 0, saved: 0 };
		let handled = 0;
		let after = 0;

		running = true;
		stopRequested = false;
		log.textContent = '';
		progress.max = Math.max( total, 1 );
		progress.value = 0;
		progress.hidden = false;
		summary.textContent = format( data.text.running, 0, total );
		updateButtons();

		try {
			while ( ! stopRequested ) {
				const result = await post( data.actions.process, {
					scope,
					after,
				} );

				if ( result.done ) {
					break;
				}

				after = result.next;
				handled += 1;

				if ( STATUS.converted === result.status ) {
					tally.converted += 1;
					tally.saved += result.saved;
				} else if ( STATUS.failed === result.status ) {
					tally.failed += 1;
				} else {
					tally.skipped += 1;
				}

				addLog( result );
				progress.value = Math.min( handled, progress.max );
				summary.textContent = format(
					data.text.running,
					handled,
					Math.max( total, handled )
				);
			}

			summary.textContent = format(
				stopRequested ? data.text.stopped : data.text.finished,
				tally.converted,
				tally.skipped,
				tally.failed,
				formatBytes( tally.saved )
			);
		} catch ( error ) {
			summary.textContent = error.message;
		}

		running = false;
		progress.hidden = true;
		await refreshCounts();
	}

	backup.addEventListener( 'change', updateButtons );

	start.addEventListener( 'click', function () {
		if ( counts && ! running ) {
			run( SCOPE.new );
		}
	} );

	retry.addEventListener( 'click', function () {
		if ( counts && ! running ) {
			run( SCOPE.retry );
		}
	} );

	stop.addEventListener( 'click', function () {
		stopRequested = true;
		stop.disabled = true;
	} );

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( running ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	backup.checked = false;
	refreshCounts();
} )();
