const { test, expect } = require( '@playwright/test' );
const { dismissPassphraseOverlay } = require( './helpers' );

test.describe( 'Version mismatch banners', () => {

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

	test( 'envMismatch banner shown when plugin app_url hostname differs', async ( { page } ) => {
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
						app_url: 'https://different.example.com/',
					},
				} ),
			} );
		} );

		await page.goto( '/' );
		await dismissPassphraseOverlay( page );
		await expect( page.locator( '#rsa-compat-banner' ) ).toBeVisible();
		await expect( page.locator( '#rsa-compat-banner' ) ).toContainText( 'different' );
	} );

	test( 'pluginTooNew banner shown when app version is older than plugin min', async ( { page } ) => {
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
						min_app_version: '99.0.0',
					},
				} ),
			} );
		} );

		await page.goto( '/v/2.4.27/stable/' );
		await dismissPassphraseOverlay( page );
		await expect( page.locator( '#rsa-compat-banner' ) ).toBeVisible();
		await expect( page.locator( '#rsa-compat-banner' ) ).toContainText( 'newer than your app' );
	} );

	test( 'appTooNew banner shown when app version is newer than plugin max', async ( { page } ) => {
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
						max_app_version: '1.0.0',
					},
				} ),
			} );
		} );

		await page.goto( '/v/2.4.27/stable/' );
		await dismissPassphraseOverlay( page );
		await expect( page.locator( '#rsa-compat-banner' ) ).toBeVisible();
		await expect( page.locator( '#rsa-compat-banner' ) ).toContainText( 'newer than this site' );
	} );
} );
