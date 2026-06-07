<?php
/**
 * Build Validation Tests
 *
 * Covers build.sh syntax, ZIP structure, version parity, and Freemius deploy
 * script logic.
 *
 * @package RichStatistics\Tests
 */
class BuildValidationTest extends WP_UnitTestCase {

	public function test_build_sh_has_valid_syntax(): void {
		$build_sh = RSA_DIR . 'build.sh';
		if ( ! file_exists( $build_sh ) ) {
			$this->markTestSkipped( 'build.sh not found.' );
		}
		$output = array();
		$return = 0;
		exec( 'bash -n ' . escapeshellarg( $build_sh ) . ' 2>&1', $output, $return );
		$this->assertSame( 0, $return, 'build.sh syntax error: ' . implode( "\n", $output ) );
	}

	public function test_versioned_pwa_snapshot_exists_for_current_version(): void {
		$version       = RSA_VERSION;
		$snapshot      = RSA_DIR . 'docs/app/v/' . $version . '/stable/index.html';
		$snapshot_beta = RSA_DIR . 'docs/app/v/' . $version . '/beta/index.html';

		$this->assertFileExists(
			$snapshot,
			"Missing PWA stable snapshot for version $version"
		);
		$this->assertFileExists(
			$snapshot_beta,
			"Missing PWA beta snapshot for version $version"
		);
	}

	public function test_versions_json_contains_current_version(): void {
		$versions_file = RSA_DIR . 'docs/app/versions.json';
		if ( ! file_exists( $versions_file ) ) {
			$this->markTestSkipped( 'versions.json not found.' );
		}
		$versions = json_decode( file_get_contents( $versions_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test file, not production
		$this->assertIsArray( $versions );
		$this->assertContains( RSA_VERSION, $versions );
	}

	public function test_deploy_script_exists_and_is_readable(): void {
		$deploy = RSA_DIR . 'bin/deploy-freemius.php';
		$this->assertFileExists( $deploy );
		$this->assertIsReadable( $deploy );
	}
}
