const { test, expect } = require( '@playwright/test' );
const { dismissPassphraseOverlay } = require( './helpers' );

test.describe( 'CSV export', () => {

	test.beforeEach( async ( { page } ) => {
		await page.route( '**/wp-json/rsa/v1/info', async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					ok: true,
					data: {
						version: '2.4.26',
						isPremium: true,
						upgradeUrl: '',
						channel: 'stable',
					},
				} ),
			} );
		} );

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

	test( 'export view renders for premium site', async ( { page } ) => {
		await page.goto( '/' );
		await dismissPassphraseOverlay( page );
		await page.click( '.rsa-nav-link[data-view="export"]' );
		await expect( page.locator( '#rsa-view-export' ) ).not.toBeHidden();
		await expect( page.locator( '#rsa-view-title' ) ).toHaveText( 'Export' );
	} );
} );
