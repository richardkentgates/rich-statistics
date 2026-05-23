const { test, expect } = require( '@playwright/test' );

test.describe( 'Navigation (site connected)', () => {

	test.beforeEach( async ( { page } ) => {
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

	test( 'app shell visible when site connected', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '#rsa-app' ) ).toBeVisible();
		await expect( page.locator( '#rsa-login' ) ).toBeHidden();
	} );

	test( 'sidebar nav renders', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '.rsa-nav' ) ).toBeVisible();
	} );

	test( 'main content area renders', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '.rsa-main' ) ).toBeVisible();
	} );

	test( 'free tier nav links present', async ( { page } ) => {
		await page.goto( '/' );
		const navLinks = page.locator( '.rsa-nav-list .rsa-nav-link' );
		await expect( navLinks ).toHaveCount( 14 );

		const texts = await navLinks.allTextContents();
		expect( texts ).toContain( 'Overview' );
		expect( texts ).toContain( 'Pages' );
		expect( texts ).toContain( 'Audience' );
		expect( texts ).toContain( 'Referrers' );
		expect( texts ).toContain( 'Behavior' );
	} );

	test( 'premium nav links present', async ( { page } ) => {
		await page.goto( '/' );
		const navLinks = page.locator( '.rsa-nav-list .rsa-nav-link' );
		const texts = await navLinks.allTextContents();
		expect( texts ).toContain( 'WooCommerce' );
		expect( texts ).toContain( 'Campaigns' );
		expect( texts ).toContain( 'User Flow' );
		expect( texts ).toContain( 'Clicks' );
		expect( texts ).toContain( 'Heatmap' );
		expect( texts ).toContain( 'Export' );
	} );

	test( 'Overview is active by default', async ( { page } ) => {
		await page.goto( '/' );
		const overviewLink = page.locator( '.rsa-nav-link[data-view="overview"]' );
		await expect( overviewLink ).toHaveClass( /rsa-active/ );
	} );

	test( 'period selector has expected options', async ( { page } ) => {
		await page.goto( '/' );
		const periodSelect = page.locator( '#rsa-period-select' );
		await expect( periodSelect ).toBeVisible();

		const options = periodSelect.locator( 'option' );
		await expect( options ).toHaveCount( 6 );

		const values = await options.evaluateAll( ( opts ) => opts.map( ( o ) => o.value ) );
		expect( values ).toContain( '7d' );
		expect( values ).toContain( '30d' );
		expect( values ).toContain( '90d' );
		expect( values ).toContain( 'thismonth' );
		expect( values ).toContain( 'lastmonth' );
		expect( values ).toContain( 'custom' );
	} );

	test( 'period selector defaults to 30d', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '#rsa-period-select' ) ).toHaveValue( '30d' );
	} );

	test( 'topbar title shows Overview', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '#rsa-view-title' ) ).toHaveText( 'Overview' );
	} );

	test( 'disconnect button present', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '#rsa-signout' ) ).toBeVisible();
		await expect( page.locator( '#rsa-signout' ) ).toHaveText( 'Sign out' );
	} );

	test( 'site switcher present', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '#rsa-site-switcher' ) ).toBeVisible();
		await expect( page.locator( '#rsa-switcher-btn' ) ).toBeVisible();
	} );

	test( 'install button hidden by default', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '#rsa-install-btn' ) ).toBeHidden();
	} );
} );

test.describe( 'View switching', () => {

	test.beforeEach( async ( { page } ) => {
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

		const mockData = {
			ok: true,
			data: {
				visitors: 100,
				sessions: 150,
				pageviews: 300,
				bounceRate: 45,
				avgSessionDuration: 120,
				chart: { labels: [], visitors: [], sessions: [], pageviews: [] },
			},
		};

		await page.route( '**/wp-json/rsa/v1/overview*', async ( route ) => {
			await route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( mockData ) } );
		} );
		await page.route( '**/wp-json/rsa/v1/pages*', async ( route ) => {
			await route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { ok: true, data: { pages: [] } } ) } );
		} );
		await page.route( '**/wp-json/rsa/v1/audience*', async ( route ) => {
			await route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { ok: true, data: { countries: [], browsers: [], devices: [] } } ) } );
		} );
		await page.route( '**/wp-json/rsa/v1/referrers*', async ( route ) => {
			await route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { ok: true, data: { referrers: [] } } ) } );
		} );
		await page.route( '**/wp-json/rsa/v1/behavior*', async ( route ) => {
			await route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { ok: true, data: { entryPages: [], exitPages: [] } } ) } );
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

	test( 'clicking Pages nav link switches view', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '.rsa-nav-link[data-view="pages"]' );
		await expect( page.locator( '#rsa-view-pages' ) ).not.toBeHidden();
		await expect( page.locator( '#rsa-view-overview' ) ).toBeHidden();
	} );

	test( 'clicking Audience nav link switches view', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '.rsa-nav-link[data-view="audience"]' );
		await expect( page.locator( '#rsa-view-audience' ) ).not.toBeHidden();
	} );

	test( 'clicking Referrers nav link switches view', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '.rsa-nav-link[data-view="referrers"]' );
		await expect( page.locator( '#rsa-view-referrers' ) ).not.toBeHidden();
	} );

	test( 'clicking Behavior nav link switches view', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '.rsa-nav-link[data-view="behavior"]' );
		await expect( page.locator( '#rsa-view-behavior' ) ).not.toBeHidden();
	} );

	test( 'active nav link highlighted', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '.rsa-nav-link[data-view="pages"]' );
		await expect( page.locator( '.rsa-nav-link[data-view="pages"]' ) ).toHaveClass( /rsa-active/ );
		await expect( page.locator( '.rsa-nav-link[data-view="overview"]' ) ).not.toHaveClass( /rsa-active/ );
	} );

	test( 'topbar title updates on view change', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '.rsa-nav-link[data-view="pages"]' );
		await expect( page.locator( '#rsa-view-title' ) ).toContainText( 'Pages' );
	} );
} );

test.describe( 'Disconnect flow', () => {

	test.beforeEach( async ( { page } ) => {
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

	test( 'disconnect returns to welcome screen', async ( { page } ) => {
		await page.goto( '/' );
		await expect( page.locator( '#rsa-app' ) ).toBeVisible();
		await page.click( '#rsa-signout' );
		await expect( page.locator( '#rsa-login' ) ).toBeVisible();
		await expect( page.locator( '#rsa-app' ) ).toBeHidden();
	} );

	test( 'disconnect clears active site', async ( { page } ) => {
		await page.goto( '/' );
		await page.click( '#rsa-signout' );
		const activeId = await page.evaluate( () => localStorage.getItem( 'rsa_active' ) );
		expect( activeId === '' || activeId === null ).toBe( true );
	} );
} );
