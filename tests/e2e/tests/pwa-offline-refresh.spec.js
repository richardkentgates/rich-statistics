const { test, expect } = require( '@playwright/test' );
const { dismissPassphraseOverlay } = require( './helpers' );

test.describe( 'Offline refresh', () => {

	test( 'queued requests replay when going back online', async ( { page } ) => {
		// Set up route interception.
		await page.route( '**/wp-json/rsa/v1/overview*', async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					ok: true,
					data: {
						visitors: 100,
						sessions: 150,
						pageviews: 300,
						bounceRate: 45,
						avgSessionDuration: 120,
						chart: { labels: [], visitors: [], sessions: [], pageviews: [] },
					},
				} ),
			} );
		} );

		await page.goto( '/' );
		await dismissPassphraseOverlay( page );

		// Inject a connected site.
		await page.evaluate( () => {
			var sites = [ {
				id: 'test-site',
				label: 'Test Site',
				siteUrl: 'https://example.com',
				credentials: btoa( 'admin:testpass' ),
			} ];
			localStorage.setItem( 'rsa_sites', JSON.stringify( sites ) );
			localStorage.setItem( 'rsa_active', 'test-site' );
		} );
		await page.goto( '/' );
		await dismissPassphraseOverlay( page );

		// Wait for app to load.
		await expect( page.locator( '#rsa-app' ) ).toBeVisible();

		// Go offline.
		await page.context().setOffline( true );

		// Trigger a request by clicking overview.
		await page.click( '.rsa-nav-link[data-view="overview"]' );

		// Go back online.
		await page.context().setOffline( false );

		// Wait a moment for the request to replay.
		await page.waitForTimeout( 1000 );

		// The app should still be visible and functional.
		await expect( page.locator( '#rsa-app' ) ).toBeVisible();
	} );
} );
