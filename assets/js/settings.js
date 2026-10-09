/* global aifd_settings */
( function () {
	'use strict';

	function post( params ) {
		params.nonce = aifd_settings.nonce;
		return fetch( aifd_settings.ajaxurl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams( params ).toString(),
		} ).then( ( r ) => r.json() );
	}

	// Fill the %1$s-style placeholders of a translated string.
	function format( template, values ) {
		return values.reduce(
			( text, value, i ) => text.replace( '%' + ( i + 1 ) + '$s', value ),
			template
		);
	}

	// Load the seed patterns a batch at a time, so no single request has to
	// outlast the server's time limit. Resolves with the totals, or rejects
	// with the server's explanation.
	function loadSeeds( status ) {
		const totals = { loaded: 0, failed: 0, total: 0 };

		function next( offset ) {
			return post( { action: 'aifd_load_seeds', offset: offset } ).then( ( data ) => {
				const batch = data.data || {};

				if ( ! data.success ) {
					// Earlier batches may have gone through; only a first-batch failure is fatal.
					if ( totals.loaded === 0 ) {
						throw new Error( batch.message || aifd_settings.i18n.requestFailed );
					}
					totals.failed += batch.failed || 0;
					return totals;
				}

				totals.loaded += batch.loaded || 0;
				totals.failed += batch.failed || 0;
				totals.total   = batch.total || totals.total;

				if ( batch.done ) {
					return totals;
				}

				status.textContent = '⏳ ' + format( aifd_settings.i18n.seedProgress, [ batch.next_offset, totals.total ] );
				return next( batch.next_offset );
			} );
		}

		return next( 0 );
	}

	function seedSummary( totals ) {
		return format( aifd_settings.i18n.seedDone, [ totals.loaded, totals.total, totals.failed ] );
	}

	// Seed patterns button (settings page)
	const seedBtn = document.getElementById( 'aifd-load-seeds' );
	if ( seedBtn ) {
		seedBtn.addEventListener( 'click', function () {
			const status = document.getElementById( 'aifd-seed-status' );

			seedBtn.disabled   = true;
			status.textContent = '⏳ ' + aifd_settings.i18n.loadingSeeds;

			loadSeeds( status )
				.then( ( totals ) => {
					status.textContent = '✅ ' + seedSummary( totals );
				} )
				.catch( ( error ) => {
					status.textContent = '❌ ' + ( error.message || aifd_settings.i18n.requestFailed );
				} )
				.then( () => {
					seedBtn.disabled = false;
				} );
		} );
	}

	// Provider switch notice buttons
	const migrateBtn = document.getElementById( 'aifd-migrate-patterns' );
	if ( migrateBtn ) {
		migrateBtn.addEventListener( 'click', function () {
			const status = document.getElementById( 'aifd-migration-status' );

			migrateBtn.disabled = true;
			status.textContent  = '⏳ ' + aifd_settings.i18n.reembedding;

			// Seeds first, in batches, then the merchant's own confirmed patterns.
			loadSeeds( status )
				.then( () => post( {
					action: 'aifd_migrate_patterns',
					from: migrateBtn.dataset.from,
					to: migrateBtn.dataset.to,
					skip_seeds: 1,
				} ) )
				.then( ( data ) => {
					if ( data.success ) {
						status.textContent = '✅ ' + data.data.message;
						setTimeout( () => location.reload(), 2000 );
					} else {
						status.textContent  = '❌ ' + data.data.message;
						migrateBtn.disabled = false;
					}
				} )
				.catch( ( error ) => {
					status.textContent  = '❌ ' + ( error.message || aifd_settings.i18n.requestFailed );
					migrateBtn.disabled = false;
				} );
		} );
	}

	const freshBtn = document.getElementById( 'aifd-start-fresh' );
	if ( freshBtn ) {
		freshBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( aifd_settings.i18n.confirmFresh ) ) return;

			const status = document.getElementById( 'aifd-migration-status' );

			freshBtn.disabled  = true;
			status.textContent = '⏳ ' + aifd_settings.i18n.loadingSeeds;

			post( { action: 'aifd_dismiss_migration', skip_seeds: 1 } )
				.then( () => loadSeeds( status ) )
				.then( ( totals ) => {
					status.textContent = '✅ ' + seedSummary( totals );
					setTimeout( () => location.reload(), 2000 );
				} )
				.catch( ( error ) => {
					// The switch is already dismissed; say why the seeds did not load, then refresh.
					status.textContent = '❌ ' + ( error.message || aifd_settings.i18n.requestFailed );
					setTimeout( () => location.reload(), 4000 );
				} );
		} );
	}
} )();
