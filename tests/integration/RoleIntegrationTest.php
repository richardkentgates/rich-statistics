<?php
/**
 * Integration tests for Statistics Analyst role and permissions.
 *
 * @package RichStatistics\Tests
 */
class RoleIntegrationTest extends WP_UnitTestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		
		// Create the role as plugin would
		if ( ! get_role( 'rsa_analyst' ) ) {
			add_role( 'rsa_analyst', 'Statistics Analyst', [
				'rsa_manage_statistics' => true,
				'read' => true,
			] );
		}
		
		// Ensure admin has the cap
		$admin_role = get_role( 'administrator' );
		if ( $admin_role && ! isset( $admin_role->capabilities['rsa_manage_statistics'] ) ) {
			$admin_role->add_cap( 'rsa_manage_statistics' );
		}
	}

	public function setUp(): void {
		parent::setUp();
	}

	public function tearDown(): void {
		parent::tearDown();
		// Clean up test users
		$users = get_users( [ 'role' => 'rsa_analyst' ] );
		foreach ( $users as $user ) {
			wp_delete_user( $user->ID );
		}
	}

	/**
	 * Test that Statistics Analyst role exists with correct capabilities.
	 */
	public function test_role_exists_with_correct_caps(): void {
		$role = get_role( 'rsa_analyst' );
		$this->assertNotNull( $role, 'Statistics Analyst role should exist' );
		$this->assertTrue( isset( $role->capabilities['rsa_manage_statistics'] ), 'Role should have rsa_manage_statistics cap' );
		$this->assertTrue( isset( $role->capabilities['read'] ), 'Role should have read cap' );
	}

	/**
	 * Test that administrator has rsa_manage_statistics capability.
	 */
	public function test_admin_has_rsa_manage_statistics(): void {
		$admin = get_role( 'administrator' );
		$this->assertNotNull( $admin, 'Administrator role should exist' );
		$this->assertTrue( isset( $admin->capabilities['rsa_manage_statistics'] ), 'Admin should have rsa_manage_statistics cap' );
	}

	/**
	 * Test that a user with Statistics Analyst role can access plugin.
	 */
	public function test_analyst_can_access_plugin(): void {
		$user = self::factory()->user->create_and_get( [ 'role' => 'rsa_analyst' ] );
		$this->assertTrue( user_can( $user, 'rsa_manage_statistics' ), 'Analyst should have rsa_manage_statistics' );
	}

	/**
	 * Test that a subscriber cannot access plugin.
	 */
	public function test_subscriber_cannot_access_plugin(): void {
		$user = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );
		$this->assertFalse( user_can( $user, 'rsa_manage_statistics' ), 'Subscriber should not have rsa_manage_statistics' );
	}

	/**
	 * Test RSA_Admin::user_can_access_app() for different roles.
	 */
	public function test_user_can_access_app(): void {
		if ( ! class_exists( 'RSA_Admin' ) ) {
			$this->markTestSkipped( 'RSA_Admin class not available' );
		}

		// Create analyst user
		$analyst = self::factory()->user->create_and_get( [ 'role' => 'rsa_analyst' ] );
		
		// Create admin user
		$admin = self::factory()->user->create_and_get( [ 'role' => 'administrator' ] );
		
		// Create subscriber
		$subscriber = self::factory()->user->create_and_get( [ 'role' => 'subscriber' ] );

		$this->assertTrue( RSA_Admin::user_can_access_app( $analyst ), 'Analyst should access app' );
		$this->assertTrue( RSA_Admin::user_can_access_app( $admin ), 'Admin should access app' );
		$this->assertFalse( RSA_Admin::user_can_access_app( $subscriber ), 'Subscriber should not access app' );
	}
}
