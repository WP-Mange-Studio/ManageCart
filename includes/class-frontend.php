<?php
/**
 * Frontend cart trigger + panel bootstrap (Phase 3A).
 *
 * @package ManageCart
 */

namespace ManageCart;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Frontend
 *
 * Decides whether the floating cart trigger and cart panel should appear on
 * the current request, and outputs their markup once on wp_footer when
 * they should. The rendered panel includes accessible quantity-control and
 * remove-button markup (Phase 3B-1 Part 1) that the frontend script
 * connects to the WooCommerce Store API (Phase 3B-1 Part 2) — no custom
 * PHP AJAX handler is involved. Phase 3C wires up auto-open-after-add-to-cart
 * (see assets/js/frontend-cart.js, which fetches WooCommerce's own Store
 * API cart route directly). Coupons, undo, and
 * the menu cart remain reserved for later phases.
 *
 * Phase 3E-1 adds the "Enable Floating Cart" Floating Cart setting: since
 * the floating trigger button is currently the only way to open the cart
 * panel, turning it off suppresses this entire wp_footer output (trigger,
 * overlay, and panel alike), the same as the existing global "Enable
 * Manage Cart" setting already does.
 */
class Frontend {

	/**
	 * Guards against the wp_footer markup being output more than once per
	 * request (defensive; wp_footer itself only fires once on a normal
	 * front-end request).
	 *
	 * @var bool
	 */
	protected static $rendered = false;

	/**
	 * Registers the wp_footer hook, but only when the frontend cart should
	 * load on this request.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! self::should_load() ) {
			return;
		}

		add_action( 'wp_footer', array( $this, 'render' ) );
	}

	/**
	 * Determines whether the frontend cart trigger/panel (and their assets)
	 * should load on the current request:
	 *
	 * - Not wp-admin.
	 * - Not a REST API request.
	 * - Not an AJAX request.
	 * - WooCommerce is active.
	 * - The global Manage Cart "Enable" setting is on.
	 * - The Floating Cart "Enable Floating Cart" setting is on.
	 *
	 * @return bool
	 */
	public static function should_load() {
		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'WC' ) ) {
			return false;
		}

		$settings = Settings::get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return false;
		}

		$floating_cart_settings = Floating_Cart_Settings::get_settings();

		if ( empty( $floating_cart_settings['enabled'] ) ) {
			return false;
		}

		/**
		 * Filters whether the frontend cart trigger/panel (and their
		 * assets) should load on the current request.
		 *
		 * Reserved for a future ManageCart Pro add-on's display
		 * conditions (e.g. specific pages, devices, or user roles). Runs
		 * after all of the free plugin's own checks above, so a Pro
		 * add-on can only narrow when the cart appears, never force it to
		 * appear when a core requirement (WooCommerce active, global
		 * "Enable" setting, etc.) isn't met.
		 *
		 * @param bool $should_load Whether the frontend cart should load.
		 */
		return (bool) apply_filters( 'manage_cart_should_load', true );
	}

	/**
	 * Outputs the floating cart trigger button plus the overlay and cart
	 * panel, once, on wp_footer.
	 *
	 * @return void
	 */
	public function render() {
		if ( self::$rendered ) {
			return;
		}

		if ( ! self::should_load() ) {
			return;
		}

		self::$rendered = true;
		?>
		<div class="manage-cart-root" id="manage-cart-root">
			<?php
			/**
			 * Fires inside the `#manage-cart-root` wrapper, before the
			 * floating trigger button and cart panel are rendered.
			 *
			 * Reserved for a future ManageCart Pro add-on. Free ManageCart
			 * adds no output here itself.
			 */
			do_action( 'manage_cart_root_start' );

			Cart_Renderer::render_trigger();
			Cart_Renderer::render_panel();

			/**
			 * Fires inside the `#manage-cart-root` wrapper, after the
			 * floating trigger button and cart panel have been rendered.
			 *
			 * Reserved for a future ManageCart Pro add-on. Free ManageCart
			 * adds no output here itself.
			 */
			do_action( 'manage_cart_root_end' );
			?>
		</div>
		<?php
	}
}
