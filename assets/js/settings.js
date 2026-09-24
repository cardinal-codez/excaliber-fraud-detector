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

	// Seed patterns button (settings page)
	const seedBtn = document.getElementById( 'aifd-load-seeds' );
	if ( seedBtn ) {
		seedBtn.addEventListener( 'click', function () {
			const status = document.getElementById( 'aifd-seed-status' );

			seedBtn.disabled   = true;
			status.textContent = '⏳ ' + aifd_settings.i18n.loadingSeeds;

			post( { action: 'aifd_load_seeds' } )
				.then( ( data ) => {
					status.textContent = ( data.success ? '✅ ' : '❌ ' ) + data.data.message;
					seedBtn.disabled   = false;
				} )
				.catch( () => {
					status.textContent = '❌ ' + aifd_settings.i18n.requestFailed;
					seedBtn.disabled   = false;
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

			post( {
				action: 'aifd_migrate_patterns',
				from: migrateBtn.dataset.from,
				to: migrateBtn.dataset.to,
			} )
				.then( ( data ) => {
					if ( data.success ) {
						status.textContent = '✅ ' + data.data.message;
						setTimeout( () => location.reload(), 2000 );
					} else {
						status.textContent  = '❌ ' + data.data.message;
						migrateBtn.disabled = false;
					}
				} )
				.catch( () => {
					status.textContent  = '❌ ' + aifd_settings.i18n.requestFailed;
					migrateBtn.disabled = false;
				} );
		} );
	}

	const freshBtn = document.getElementById( 'aifd-start-fresh' );
	if ( freshBtn ) {
		freshBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( aifd_settings.i18n.confirmFresh ) ) return;

			post( { action: 'aifd_dismiss_migration' } ).then( () => location.reload() );
		} );
	}
} )();
