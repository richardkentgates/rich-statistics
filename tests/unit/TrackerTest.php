<?php
/**
 * Unit tests for RSA_Tracker — page sanitization and payload parsing logic.
 *
 * Tested via Brain\Monkey for WordPress function stubs so no WP installation
 * is required.
 *
 * @package RichStatistics\Tests
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class TrackerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ----------------------------------------------------------------
	// sanitize_page() — public via reflection
	// ----------------------------------------------------------------

	private function sanitize_page( string $raw ): string {
		$method = new ReflectionMethod( RSA_Tracker::class, 'sanitize_page' );
		$method->setAccessible( true );
		return $method->invoke( null, $raw );
	}

	public function test_plain_path_is_unchanged(): void {
		$this->assertSame( '/blog/hello-world/', $this->sanitize_page( '/blog/hello-world/' ) );
	}

	public function test_short_harmless_query_param_kept(): void {
		$result = $this->sanitize_page( '/search/?q=analytics' );
		$this->assertStringContainsString( 'q=analytics', $result );
	}

	public function test_long_query_value_stripped(): void {
		// >40 chars should be dropped
		$long = str_repeat( 'x', 41 );
		$result = $this->sanitize_page( '/page/?token=' . $long );
		$this->assertStringNotContainsString( 'token=', $result );
	}

	public function test_email_shaped_query_param_stripped(): void {
		$result = $this->sanitize_page( '/confirm/?email=user%40example.com' );
		$this->assertStringNotContainsString( 'email=', $result );
	}

	public function test_multiple_query_params_filtered_correctly(): void {
		$long  = str_repeat( 'a', 45 );
		$input = '/page/?id=42&token=' . $long . '&s=hello';
		$result = $this->sanitize_page( $input );
		$this->assertStringContainsString( 'id=42', $result );
		$this->assertStringContainsString( 's=hello', $result );
		$this->assertStringNotContainsString( 'token=', $result );
	}

	public function test_fragment_removed(): void {
		$result = $this->sanitize_page( '/page/#section' );
		$this->assertStringNotContainsString( '#section', $result );
	}

	public function test_no_scheme_or_host_stored(): void {
		$result = $this->sanitize_page( 'https://example.com/about/?ref=newsletter' );
		$this->assertStringNotContainsString( 'example.com', $result );
	}

	// ----------------------------------------------------------------
	// Bot signal bitmask sanity — ensure RSA_Bot_Detection constants match
	// ----------------------------------------------------------------

	public function test_bot_signal_constants_are_powers_of_two(): void {
		$flags = [
			RSA_Bot_Detection::CS_WEBDRIVER,
			RSA_Bot_Detection::CS_NO_PLUGINS,
			RSA_Bot_Detection::CS_NO_LANGUAGES,
			RSA_Bot_Detection::CS_ZERO_SCREEN,
			RSA_Bot_Detection::CS_NO_TOUCH_API,
			RSA_Bot_Detection::CS_INSTANT_LOAD,
			RSA_Bot_Detection::CS_NO_CANVAS,
			RSA_Bot_Detection::CS_HIDDEN_ON_ARRIVAL,
			RSA_Bot_Detection::CS_NO_HUMAN_EVENT,
			RSA_Bot_Detection::CS_CHROME_MISSING_OBJ,
		];

		// Each flag must be a distinct power of 2 (no overlaps)
		$seen = [];
		foreach ( $flags as $flag ) {
			$this->assertTrue( ( $flag & ( $flag - 1 ) ) === 0, "Not a power of 2: $flag" );
			$this->assertNotContains( $flag, $seen, "Duplicate flag: $flag" );
			$seen[] = $flag;
		}
	}
}
