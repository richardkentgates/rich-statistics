<?php
/**
 * Freemius development stub.
 *
 * Used ONLY when the production Freemius SDK (freemius/start.php) is not present,
 * e.g. during local development, CI testing, or when running without the SDK.
 *
 * This stub provides just enough of the Freemius API surface that the free-tier
 * plugin loads correctly. All premium gates return false, so premium features
 * remain disabled. The real Freemius SDK is downloaded by build.sh before
 * creating the distributable plugin ZIP.
 *
 * @package RichStatistics
 */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'RSA_Freemius_Stub' ) ) :

	class RSA_Freemius_Stub {

		/** @var static|null */
		private static ?self $instance = null;

		/** @var bool Whether the current request is to a premium-activated site. */
		private bool $premium = false;

		public static function instance(): static {
			if ( ! static::$instance ) {
				static::$instance = new static();
			}
			return static::$instance;
		}

		// ----------------------------------------------------------------
		// Premium gating — always false in dev stub
		// ----------------------------------------------------------------

		public function can_use_premium_code(): bool {
			return $this->premium;
		}

		public function is_premium(): bool {
			return $this->premium;
		}

		public function is_paying(): bool {
			return $this->premium;
		}

		public function is_free_plan(): bool {
			return ! $this->premium;
		}

		// ----------------------------------------------------------------
		// Stub methods that the admin class / menus may call
		// ----------------------------------------------------------------

		public function get_plan_name(): string {
			return $this->premium ? 'Premium' : 'Free';
		}

		public function has_paid_plan(): bool {
			return true;
		}

		public function is_registered(): bool {
			return false;
		}

		public function is_anonymous(): bool {
			return true;
		}

		/** Stub for fs_dynamic_init() return value. */
		public function init( array $config ): static {
			return $this;
		}

		// ----------------------------------------------------------------
		// Allow unit tests to simulate premium activation
		// ----------------------------------------------------------------

		public function simulate_premium( bool $on = true ): void {
			$this->premium = $on;
		}
	}

endif;
