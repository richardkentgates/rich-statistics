const { test, expect } = require( '@playwright/test' );

test.describe( 'Premium views (site connected, premium)', () => {

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

	test( 'offline banner shows when network is offline', async ( { page } ) => {
		await page.goto( '/' );
		await page.context().setOffline( true );
		await expect( page.locator( '#rsa-banner-offline' ) ).toBeVisible();
		await page.context().setOffline( false );
		await expect( page.locator( '#rsa-banner-offline' ) ).toBeHidden();
	} );

	test( 'AI chat view renders with mocked data', async ( { page } ) => {
		await page.route( '**/wp-json/rsa/v1/ai/tool', async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					ok: true,
					data: { overview: { visitors: 50, sessions: 60, pageviews: 100 } },
				} ),
			} );
		} );

		await page.goto( '/' );
		await page.click( '.rsa-nav-link[data-view="ai-chat"]' );
		await expect( page.locator( '#rsa-view-ai-chat' ) ).not.toBeHidden();
		await expect( page.locator( '#rsa-view-ai-chat' ) ).toContainText( 'AI Analytics Assistant' );
	} );

	test( 'WooCommerce view renders with mocked data', async ( { page } ) => {
		await page.route( '**/wp-json/rsa/v1/woocommerce*', async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					ok: true,
					data: {
						orders: 12,
						revenue: 1234.56,
						avgOrderValue: 102.88,
						products: [],
						funnel: [],
					},
				} ),
			} );
		} );

		await page.goto( '/' );
		await page.click( '.rsa-nav-link[data-view="woocommerce"]' );
		await expect( page.locator( '#rsa-view-woocommerce' ) ).not.toBeHidden();
		await expect( page.locator( '#rsa-view-title' ) ).toHaveText( 'WooCommerce' );
	} );

	test( 'Export view renders', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '.rsa-nav-link[data-view="export"]' );
		await expect( page.locator( '#rsa-view-export' ) ).not.toBeHidden();
		await expect( page.locator( '#rsa-view-title' ) ).toHaveText( 'Export' );
	} );

	test( 'Heatmap view renders with mocked data', async ( { page } ) => {
		await page.route( '**/wp-json/rsa/v1/heatmap*', async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					ok: true,
					data: {
						points: [ { x_pct: 10, y_pct: 20, weight: 5 } ],
						elements: [],
					},
				} ),
			} );
		} );

		await page.goto( '/' );
		await page.click( '.rsa-nav-link[data-view="heatmap"]' );
		await expect( page.locator( '#rsa-view-heatmap' ) ).not.toBeHidden();
		await expect( page.locator( '#rsa-view-title' ) ).toHaveText( 'Heatmap' );
	} );

	test( 'User Flow view renders with mocked data', async ( { page } ) => {
		await page.route( '**/wp-json/rsa/v1/user-flow/journey*', async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					ok: true,
					data: {
						rows: [ { from_page: '/home', to_page: '/about', count: 10 } ],
					},
				} ),
			} );
		} );

		await page.goto( '/' );
		await page.click( '.rsa-nav-link[data-view="user-flow"]' );
		await expect( page.locator( '#rsa-view-user-flow' ) ).not.toBeHidden();
		await expect( page.locator( '#rsa-view-title' ) ).toHaveText( 'User Flow' );
	} );
} );
