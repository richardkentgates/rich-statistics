/**
 * Shared E2E test helpers.
 */

/**
 * Dismiss the passphrase-setup overlay if it appears.
 * Unencrypted legacy data triggers this overlay; we click Skip
 * after mocking window.confirm so the confirm() dialog returns true.
 */
async function dismissPassphraseOverlay( page ) {
	const skipBtn = page.locator( '#rsa-set-pass-skip-btn' );
	if ( await skipBtn.isVisible().catch( () => false ) ) {
		await page.evaluate( () => { window.confirm = () => true; } );
		await skipBtn.click();
		await page.locator( '#rsa-set-pass' ).waitFor( { state: 'hidden' } );
	}
}

module.exports = { dismissPassphraseOverlay };
