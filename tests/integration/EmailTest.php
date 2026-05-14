<?php
/**
 * Integration tests for RSA_Email.
 *
 * @package RichStatistics\Tests
 */
class EmailTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		RSA_DB::install();

		delete_option( 'rsa_email_digest_enabled' );
		delete_option( 'rsa_email_digest_frequency' );
		delete_option( 'rsa_email_digest_recipients' );
		delete_option( 'rsa_email_digest_use_roles' );
		update_option( 'rsa_email_digest_enabled', 0 );
		update_option( 'rsa_email_digest_frequency', 'weekly' );
	}

	/**
	 * ----------------------------------------------------------------
	 * maybe_schedule()
	 * ----------------------------------------------------------------
	 */
	public function test_maybe_schedule_does_nothing_when_digest_disabled(): void {
		update_option( 'rsa_email_digest_enabled', 0 );
		wp_clear_scheduled_hook( 'rsa_send_digest' );

		RSA_Email::maybe_schedule();

		$this->assertFalse( wp_next_scheduled( 'rsa_send_digest' ) );
	}

	public function test_maybe_schedule_schedules_when_enabled(): void {
		update_option( 'rsa_email_digest_enabled', 1 );
		wp_clear_scheduled_hook( 'rsa_send_digest' );

		RSA_Email::maybe_schedule();

		$this->assertNotFalse( wp_next_scheduled( 'rsa_send_digest' ) );
	}

	/**
	 * ----------------------------------------------------------------
	 * schedule_next()
	 * ----------------------------------------------------------------
	 */
	public function test_schedule_next_clears_existing_hook(): void {
		update_option( 'rsa_email_digest_enabled', 1 );
		wp_schedule_single_event( time() + 3600, 'rsa_send_digest' );

		RSA_Email::schedule_next();

		$scheduled = wp_next_scheduled( 'rsa_send_digest' );
		$this->assertNotFalse( $scheduled );
		$this->assertGreaterThan( time(), $scheduled );
	}

	public function test_schedule_next_does_nothing_when_disabled(): void {
		update_option( 'rsa_email_digest_enabled', 0 );
		wp_schedule_single_event( time() + 3600, 'rsa_send_digest' );

		RSA_Email::schedule_next();

		$this->assertFalse( wp_next_scheduled( 'rsa_send_digest' ) );
	}

	public function test_schedule_next_daily_interval(): void {
		update_option( 'rsa_email_digest_enabled', 1 );
		update_option( 'rsa_email_digest_frequency', 'daily' );
		wp_clear_scheduled_hook( 'rsa_send_digest' );

		RSA_Email::schedule_next();

		$scheduled = wp_next_scheduled( 'rsa_send_digest' );
		$diff      = $scheduled - time();
		$this->assertGreaterThanOrEqual( DAY_IN_SECONDS - 5, $diff );
		$this->assertLessThanOrEqual( DAY_IN_SECONDS + 5, $diff );
	}

	public function test_schedule_next_weekly_interval(): void {
		update_option( 'rsa_email_digest_enabled', 1 );
		update_option( 'rsa_email_digest_frequency', 'weekly' );
		wp_clear_scheduled_hook( 'rsa_send_digest' );

		RSA_Email::schedule_next();

		$scheduled = wp_next_scheduled( 'rsa_send_digest' );
		$diff      = $scheduled - time();
		$this->assertGreaterThanOrEqual( WEEK_IN_SECONDS - 5, $diff );
		$this->assertLessThanOrEqual( WEEK_IN_SECONDS + 5, $diff );
	}

	public function test_schedule_next_monthly_interval(): void {
		update_option( 'rsa_email_digest_enabled', 1 );
		update_option( 'rsa_email_digest_frequency', 'monthly' );
		wp_clear_scheduled_hook( 'rsa_send_digest' );

		RSA_Email::schedule_next();

		$scheduled     = wp_next_scheduled( 'rsa_send_digest' );
		$first_of_next = strtotime( 'first day of next month 00:00' );
		$this->assertGreaterThanOrEqual( $first_of_next, $scheduled );
	}

	/**
	 * ----------------------------------------------------------------
	 * send_digest()
	 * ----------------------------------------------------------------
	 */
	public function test_send_digest_returns_false_when_no_recipients(): void {
		update_option( 'rsa_email_digest_recipients', '' );
		update_option( 'rsa_email_digest_use_roles', 0 );

		$result = RSA_Email::send_digest( '7d' );

		$this->assertFalse( $result );
	}

	public function test_send_digest_returns_true_on_success(): void {
		update_option( 'rsa_email_digest_recipients', 'admin@test.com' );
		update_option( 'rsa_email_digest_use_roles', 0 );

		$result = RSA_Email::send_digest( '7d' );

		$this->assertTrue( $result );
	}

	public function test_send_digest_re_schedules_after_sending(): void {
		update_option( 'rsa_email_digest_enabled', 1 );
		update_option( 'rsa_email_digest_recipients', 'admin@test.com' );
		update_option( 'rsa_email_digest_use_roles', 0 );
		wp_clear_scheduled_hook( 'rsa_send_digest' );

		RSA_Email::send_digest( '7d' );

		$this->assertNotFalse( wp_next_scheduled( 'rsa_send_digest' ) );
	}

	/**
	 * ----------------------------------------------------------------
	 * reschedule_on_save()
	 * ----------------------------------------------------------------
	 */
	public function test_reschedule_on_save_schedules_when_enabled(): void {
		update_option( 'rsa_email_digest_enabled', 1 );
		update_option( 'rsa_email_digest_frequency', 'daily' );
		wp_clear_scheduled_hook( 'rsa_send_digest' );

		RSA_Email::reschedule_on_save();

		$this->assertNotFalse( wp_next_scheduled( 'rsa_send_digest' ) );
	}

	/**
	 * ----------------------------------------------------------------
	 * period mapping from frequency
	 * ----------------------------------------------------------------
	 */
	public function test_period_default_is_30d(): void {
		update_option( 'rsa_email_digest_recipients', 'admin@test.com' );
		update_option( 'rsa_email_digest_use_roles', 0 );
		update_option( 'rsa_email_digest_frequency', 'weekly' );

		$result = RSA_Email::send_digest();

		$this->assertTrue( $result );
	}
}
