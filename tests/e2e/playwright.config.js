// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );
const path = require( 'path' );

/**
 * Playwright configuration for Rich Statistics PWA E2E tests.
 *
 * Tests run against the PWA served from docs/app/ using a static file server.
 * API calls are intercepted and mocked — no real WordPress backend required.
 */
module.exports = defineConfig( {
	testDir: './tests',
	outputDir: './test-results',
	fullyParallel: true,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 2 : 0,
	workers: 1,
	reporter: process.env.CI ? [ [ 'html', { outputFolder: 'html-report' } ], [ 'list' ] ] : 'list',
	use: {
		baseURL: 'http://localhost:3000',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
				serviceWorkers: 'block',
			},
		},
	],
	webServer: {
		command: 'npx http-server ../../docs/app -p 3000 -s --cors',
		url: 'http://localhost:3000',
		reuseExistingServer: ! process.env.CI,
		timeout: 10000,
	},
} );
