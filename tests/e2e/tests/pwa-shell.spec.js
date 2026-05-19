const { test, expect } = require( '@playwright/test' );

test.describe( 'PWA shell', () => {

	test( 'welcome screen renders', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '#rsa-login' ) ).toBeVisible();
		await expect( page.locator( '.rsa-login-card h1' ) ).toHaveText( 'Rich Statistics' );
		await expect( page.locator( '#rsa-get-started-btn' ) ).toBeVisible();
	} );

	test( 'welcome screen has logo', async ( { page } ) => {
		await page.goto( '/' );
		const logo = page.locator( '.rsa-login-logo img' );
		await expect( logo ).toBeVisible();
		await expect( logo ).toHaveAttribute( 'alt', 'Rich Statistics' );
	} );

	test( 'welcome screen has descriptive text', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.getByText( 'Analytics dashboard for your WordPress site.' ) ).toBeVisible();
	} );

	test( 'install PWA button hidden by default', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '#rsa-login .rsa-login-install-btn' ) ).toBeHidden();
	} );

	test( 'app shell hidden when no sites connected', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '#rsa-app' ) ).toBeHidden();
	} );

	test( 'add site overlay hidden on initial load', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '#rsa-add-site' ) ).toBeHidden();
	} );

	test( 'page has correct title', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page ).toHaveTitle( 'Rich Statistics' );
	} );

	test( 'page loads without JavaScript errors', async ( { page } ) => {
		const errors = [];
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );
		await page.goto( '/' );
		await expect( page.locator( '#rsa-login' ) ).toBeVisible();
		expect( errors ).toEqual( [] );
	} );

	test( 'Chart.js is loaded', async ( { page } ) => {
		await page.goto( '/' );
		const hasChart = await page.evaluate( () => typeof window.Chart !== 'undefined' );
		expect( hasChart ).toBe( true );
	} );

	test( 'config.js is loaded', async ( { page } ) => {
		await page.goto( '/' );
		const hasConfig = await page.evaluate( () => typeof window.RSA_CONFIG !== 'undefined' );
		expect( hasConfig ).toBe( true );
	} );
} );
