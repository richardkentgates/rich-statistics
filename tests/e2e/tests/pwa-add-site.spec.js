const { test, expect } = require( '@playwright/test' );

test.describe( 'Add Site overlay', () => {

	test( 'opens on "Add Your Site" button click', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );
		await expect( page.locator( '#rsa-add-site' ) ).toBeVisible();
	} );

	test( 'shows step 1 by default', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );
		await expect( page.locator( '#rsa-add-step-1' ) ).toBeVisible();
		await expect( page.locator( '#rsa-add-step-2' ) ).toBeHidden();
	} );

	test( 'has Site URL input', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );
		const urlInput = page.locator( '#rsa-add-site-url' );
		await expect( urlInput ).toBeVisible();
		await expect( urlInput ).toHaveAttribute( 'type', 'url' );
		await expect( urlInput ).toHaveAttribute( 'placeholder', 'https://yoursite.com' );
	} );

	test( 'has App Code input', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );
		const otpInput = page.locator( '#rsa-add-otp' );
		await expect( otpInput ).toBeVisible();
		await expect( otpInput ).toHaveAttribute( 'inputmode', 'numeric' );
		await expect( otpInput ).toHaveAttribute( 'maxlength', '7' );
	} );

	test( 'has Verify Code button', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );
		await expect( page.locator( '#rsa-add-verify-btn' ) ).toBeVisible();
		await expect( page.locator( '#rsa-add-verify-btn' ) ).toHaveText( 'Verify Code' );
	} );

	test( 'has Cancel button', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );
		await expect( page.locator( '#rsa-add-cancel-btn' ) ).toBeVisible();
		await expect( page.locator( '#rsa-add-cancel-btn' ) ).toHaveText( 'Cancel' );
	} );

	test( 'can be cancelled', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );
		await expect( page.locator( '#rsa-add-site' ) ).toBeVisible();
		await page.click( '#rsa-add-cancel-btn' );
		await expect( page.locator( '#rsa-add-site' ) ).toBeHidden();
		await expect( page.locator( '#rsa-login' ) ).toBeVisible();
	} );

	test( 'shows desktop install links on welcome screen', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '.rsa-install-card' ) ).toBeVisible();
		await expect( page.locator( 'text=Install the desktop app' ) ).toBeVisible();
		await expect( page.locator( 'text=Linux (APT)' ) ).toBeVisible();
		await expect( page.locator( 'text=Recommended' ) ).toBeVisible();
		await expect( page.locator( 'text=Download installer (.exe)' ) ).toBeVisible();
	} );

	test( 'OTP input accepts 6-digit code', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );
		const otpInput = page.locator( '#rsa-add-otp' );
		await otpInput.fill( '123456' );
		await expect( otpInput ).toHaveValue( '123456' );
	} );

	test( 'Site URL input accepts valid URL', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );
		const urlInput = page.locator( '#rsa-add-site-url' );
		await urlInput.fill( 'https://example.com' );
		await expect( urlInput ).toHaveValue( 'https://example.com' );
	} );
} );

test.describe( 'OTP verification flow', () => {

	test( 'verify button calls API with correct payload', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );
		await page.locator( '#rsa-add-site-url' ).fill( 'https://example.com' );
		await page.locator( '#rsa-add-otp' ).fill( '123456' );

		const apiPromise = page.waitForRequest( ( req ) =>
			req.url().includes( '/wp-json/rsa/v1/verify-otp' ) &&
			req.method() === 'POST'
		);

		await page.click( '#rsa-add-verify-btn' );
		const request = await apiPromise;
		const postData = JSON.parse( request.postData() );
		expect( postData ).toEqual( { otp: '123456' } );
	} );

	test( 'shows error for invalid URL', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );
		await page.locator( '#rsa-add-site-url' ).fill( 'not-a-url' );
		await page.locator( '#rsa-add-otp' ).fill( '123456' );
		await page.click( '#rsa-add-verify-btn' );
		await expect( page.locator( '#rsa-add-otp-error' ) ).toContainText( 'valid URL' );
	} );

	test( 'shows error for short OTP', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );
		await page.locator( '#rsa-add-site-url' ).fill( 'https://example.com' );
		await page.locator( '#rsa-add-otp' ).fill( '123' );
		await page.click( '#rsa-add-verify-btn' );
		await expect( page.locator( '#rsa-add-otp-error' ) ).toContainText( '6-digit' );
	} );

	test( 'shows error for empty OTP', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );
		await page.locator( '#rsa-add-site-url' ).fill( 'https://example.com' );
		await page.click( '#rsa-add-verify-btn' );
		await expect( page.locator( '#rsa-add-otp-error' ) ).toContainText( '6-digit' );
	} );
} );

test.describe( 'Add Site step 2', () => {

	test( 'step 2 hidden initially', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );
		await expect( page.locator( '#rsa-add-step-2' ) ).toBeHidden();
	} );

	test( 'step 2 has Application Password input', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );

		await page.route( '**/wp-json/rsa/v1/verify-otp', async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					success: true,
					data: {
						verified: true,
						siteUrl: 'https://example.com',
						username: 'admin',
					},
				} ),
			} );
		} );

		await page.locator( '#rsa-add-site-url' ).fill( 'https://example.com' );
		await page.locator( '#rsa-add-otp' ).fill( '123456' );
		await page.click( '#rsa-add-verify-btn' );

		await expect( page.locator( '#rsa-add-step-2' ) ).toBeVisible();
		await expect( page.locator( '#rsa-add-app-pass' ) ).toBeVisible();
		await expect( page.locator( '#rsa-add-app-pass' ) ).toHaveAttribute( 'type', 'password' );
	} );

	test( 'step 2 has Connect button', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );

		await page.route( '**/wp-json/rsa/v1/verify-otp', async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					success: true,
					data: {
						verified: true,
						siteUrl: 'https://example.com',
						username: 'admin',
					},
				} ),
			} );
		} );

		await page.locator( '#rsa-add-site-url' ).fill( 'https://example.com' );
		await page.locator( '#rsa-add-otp' ).fill( '123456' );
		await page.click( '#rsa-add-verify-btn' );

		await expect( page.locator( '#rsa-add-confirm-btn' ) ).toBeVisible();
		await expect( page.locator( '#rsa-add-confirm-btn' ) ).toHaveText( 'Connect' );
	} );

	test( 'step 2 has Back button', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );

		await page.route( '**/wp-json/rsa/v1/verify-otp', async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					success: true,
					data: {
						verified: true,
						siteUrl: 'https://example.com',
						username: 'admin',
					},
				} ),
			} );
		} );

		await page.locator( '#rsa-add-site-url' ).fill( 'https://example.com' );
		await page.locator( '#rsa-add-otp' ).fill( '123456' );
		await page.click( '#rsa-add-verify-btn' );

		await expect( page.locator( '#rsa-add-back-btn' ) ).toBeVisible();
	} );

	test( 'Back button returns to step 1', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-get-started-btn' );

		await page.route( '**/wp-json/rsa/v1/verify-otp', async ( route ) => {
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( {
					success: true,
					data: {
						verified: true,
						siteUrl: 'https://example.com',
						username: 'admin',
					},
				} ),
			} );
		} );

		await page.locator( '#rsa-add-site-url' ).fill( 'https://example.com' );
		await page.locator( '#rsa-add-otp' ).fill( '123456' );
		await page.click( '#rsa-add-verify-btn' );
		await expect( page.locator( '#rsa-add-step-2' ) ).toBeVisible();

		await page.click( '#rsa-add-back-btn' );
		await expect( page.locator( '#rsa-add-step-1' ) ).toBeVisible();
		await expect( page.locator( '#rsa-add-step-2' ) ).toBeHidden();
	} );
} );
