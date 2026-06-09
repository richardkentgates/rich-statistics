const { test, expect } = require( '@playwright/test' );
const { dismissPassphraseOverlay } = require( './helpers' );

test.describe( 'Connection status', () => {

	test( 'offline banner hidden by default', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '#rsa-banner-offline' ) ).toBeHidden();
	} );

	test( 'site-down banner hidden by default', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '#rsa-banner-site-down' ) ).toBeHidden();
	} );

	test( 'loading overlay hidden by default', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '#rsa-loading' ) ).toBeHidden();
	} );
} );

test.describe( 'View containers', () => {

	test( 'all view containers exist', async ( { page } ) => {
		await page.goto( '/' );
		const views = [
			'overview',
			'pages',
			'audience',
			'referrers',
			'campaigns',
			'behavior',
			'user-flow',
			'clicks',
			'heatmap',
			'export',
			'woocommerce',
			'install',
			'ai-settings',
			'ai-chat',
		];

		for ( const view of views ) {
			await expect( page.locator( `#rsa-view-${view}` ) ).toBeAttached();
		}
	} );

	test( 'only overview active by default', async ( { page } ) => {
		await page.goto( '/' );
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

		await page.route( '**/wp-json/rsa/v1/info', async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					ok: true,
					data: {
						version: '2.4.20',
						isPremium: false,
						upgradeUrl: 'https://example.com/upgrade',
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

		await page.goto( '/' );
		await dismissPassphraseOverlay( page );
		await expect( page.locator( '#rsa-view-overview' ) ).toHaveClass( /rsa-active/ );
	} );
} );

test.describe( 'Responsive behavior', () => {

	test( 'menu toggle visible on mobile viewport', async ( { page } ) => {
		await page.setViewportSize( { width: 375, height: 667 } );
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

		await page.route( '**/wp-json/rsa/v1/info', async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					ok: true,
					data: {
						version: '2.4.20',
						isPremium: false,
						upgradeUrl: 'https://example.com/upgrade',
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

		await page.goto( '/' );
		await dismissPassphraseOverlay( page );
		await expect( page.locator( '#rsa-menu-toggle' ) ).toBeVisible();
	} );
} );
