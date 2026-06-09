const { test, expect } = require( '@playwright/test' );
const { dismissPassphraseOverlay } = require( './helpers' );

test.describe( 'Error states (connected site)', () => {

	test.beforeEach( async ( { page } ) => {
		await page.addInitScript( () => {
			localStorage.setItem( 'rsa_sites', JSON.stringify( [
				{
					id: 'test-site-1',
					label: 'Test Site',
					siteUrl: 'https://example.com',
					credentials: 'dGVzdDp0ZXN0',
				},
			] ) );
			localStorage.setItem( 'rsa_active', 'test-site-1' );
		} );
	} );

	test( '403 response shows login screen', async ( { page } ) => {
		await page.route( '**/wp-json/rsa/v1/**', async ( route ) => {
			await route.fulfill( { status: 403, contentType: 'application/json', body: JSON.stringify( { error: 'Forbidden' } ) } );
		} );

		await page.goto( '/' );
		await dismissPassphraseOverlay( page );
		await expect( page.locator( '#rsa-login' ) ).toBeVisible();
		await expect( page.locator( '#rsa-app' ) ).toBeHidden();
	} );

	test( '404 response shows error message in view', async ( { page } ) => {
		await page.route( '**/wp-json/rsa/v1/info', async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					ok: true,
					data: {
						version: '2.4.20',
						isPremium: false,
						channel: 'stable',
					},
				} ),
			} );
		} );

		await page.route( '**/wp-json/rsa/v1/overview*', async ( route ) => {
			await route.fulfill( { status: 404, contentType: 'application/json', body: JSON.stringify( { error: 'Not found' } ) } );
		} );

		await page.goto( '/' );
		await dismissPassphraseOverlay( page );
		await expect( page.locator( '.rsa-empty' ) ).toContainText( 'Could not load data (HTTP 404)' );
	} );

	test( 'network failure shows site-down banner', async ( { page } ) => {
		await page.route( '**/wp-json/rsa/v1/info', async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					ok: true,
					data: {
						version: '2.4.20',
						isPremium: false,
						channel: 'stable',
					},
				} ),
			} );
		} );

		await page.route( '**/wp-json/rsa/v1/overview*', async ( route ) => {
			await route.abort( 'failed' );
		} );

		await page.goto( '/' );
		await dismissPassphraseOverlay( page );
		await expect( page.locator( '#rsa-banner-site-down' ) ).not.toBeHidden();
	} );
} );
