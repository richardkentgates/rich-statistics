const { test, expect } = require( '@playwright/test' );

test.describe( 'Full user journey', () => {

	test( 'add site, connect, navigate, disconnect', async ( { page } ) => {
		await page.route( '**/wp-json/rsa/v1/verify-otp', async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					ok: true,
					data: {
						verified: true,
						site_url: 'https://example.com',
						username: 'admin',
					},
				} ),
			} );
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

		await page.route( '**/wp-json/rsa/v1/pages*', async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( { ok: true, data: { pages: [] } } ),
			} );
		} );

		// 1. Welcome screen.
		await page.goto( '/' );
		await expect( page.locator( '#rsa-login' ) ).toBeVisible();
		await expect( page.locator( '#rsa-get-started-btn' ) ).toBeVisible();

		// 2. Open add-site overlay.
		await page.click( '#rsa-get-started-btn' );
		await expect( page.locator( '#rsa-add-site' ) ).toBeVisible();
		await expect( page.locator( '#rsa-add-step-1' ) ).toBeVisible();

		// 3. Fill site URL and OTP.
		await page.locator( '#rsa-add-site-url' ).fill( 'https://example.com' );
		await page.locator( '#rsa-add-otp' ).fill( '123456' );

		// 4. Verify OTP.
		await page.click( '#rsa-add-verify-btn' );
		await expect( page.locator( '#rsa-add-step-2' ) ).toBeVisible();

		// 5. Fill application password and connect.
		await page.locator( '#rsa-add-app-pass' ).fill( 'testpass' );
		await page.click( '#rsa-add-confirm-btn' );

		// 6. App shell visible after connection.
		await expect( page.locator( '#rsa-app' ) ).toBeVisible();
		await expect( page.locator( '#rsa-login' ) ).toBeHidden();
		await expect( page.locator( '.rsa-nav' ) ).toBeVisible();

		// 7. Overview active by default.
		await expect( page.locator( '.rsa-nav-link[data-view="overview"]' ) ).toHaveClass( /rsa-active/ );

		// 8. Navigate to Pages.
		await page.click( '.rsa-nav-link[data-view="pages"]' );
		await expect( page.locator( '#rsa-view-pages' ) ).not.toBeHidden();
		await expect( page.locator( '.rsa-nav-link[data-view="pages"]' ) ).toHaveClass( /rsa-active/ );

		// 9. Disconnect.
		await page.click( '#rsa-signout' );
		await expect( page.locator( '#rsa-login' ) ).toBeVisible();
		await expect( page.locator( '#rsa-app' ) ).toBeHidden();
	} );
} );
