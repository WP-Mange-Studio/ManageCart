<?php
/**
 * Core plugin bootstrap class.
 *
 * @package ManageCart
 */

namespace ManageCart;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 *
 * Singleton responsible for wiring up Manage Cart once all requirements
 * (PHP version, WooCommerce) have been confirmed as met.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Assets manager instance.
	 *
	 * @var Assets
	 */
	protected $assets;

	/**
	 * Admin settings page instance. Only instantiated in wp-admin.
	 *
	 * @var Admin|null
	 */
	protected $admin;

	/**
	 * Side Cart admin submenu page instance (Phase 2B). Only instantiated
	 * in wp-admin.
	 *
	 * @var Side_Cart_Admin|null
	 */
	protected $side_cart_admin;

	/**
	 * Frontend cart trigger/panel instance (Phase 3A). Only instantiated
	 * outside wp-admin.
	 *
	 * @var Frontend|null
	 */
	protected $frontend;

	/**
	 * Single product page add-to-cart auto-open detector (Single Product
	 * Page Auto-open, Part 1). Only instantiated outside wp-admin.
	 *
	 * @var Single_Product_Auto_Open|null
	 */
	protected $single_product_auto_open;

	/**
	 * Menu Cart Trigger shortcode ([manage_cart_trigger]). Registered in
	 * both wp-admin and frontend contexts (unlike $frontend and
	 * $single_product_auto_open above) purely so the shortcode tag is
	 * always recognized wherever WordPress processes shortcodes,
	 * including admin-side preview requests some block/page-builder
	 * editors make. Whether it actually renders markup on any given
	 * request is decided entirely by Menu_Cart_Trigger::should_render()
	 * (Frontend::should_load()) at render time, not by where this is
	 * instantiated.
	 *
	 * @var Menu_Cart_Trigger|null
	 */
	protected $menu_cart_trigger;

	/**
	 * Retrieves the single instance of the plugin.
	 *
	 * @return Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor is protected to enforce singleton usage.
	 */
	protected function __construct() {
		$this->assets = new Assets();
	}

	/**
	 * Runs the plugin.
	 *
	 * Initializes assets. As of Phase 2A, the
	 * native wp-admin settings page is also initialized, but only when
	 * running inside wp-admin. Phase 2B adds the "Side Cart" settings
	 * submenu page, initialized the same way. Phase 3A adds the frontend
	 * cart trigger and panel, initialized only outside wp-admin, and only
	 * where its own load conditions are met (see Frontend::should_load()).
	 * Store API integration (AJAX add/remove/quantity) remains reserved for
	 * a later phase.
	 *
	 * Single Product Page Auto-open adds Single_Product_Auto_Open,
	 * initialized alongside Frontend (outside wp-admin only), which hooks
	 * WooCommerce's own `woocommerce_before_add_to_cart_button` action to
	 * render a namespaced marker/nonce into that product's own
	 * add-to-cart form, and `woocommerce_add_to_cart` to detect a
	 * successful single-product-page add-to-cart entirely server-side.
	 *
	 * The Menu Cart Trigger feature registers the public
	 * `[manage_cart_trigger]` shortcode via Menu_Cart_Trigger, on every
	 * request (admin and frontend alike) so the tag itself is always
	 * recognized. It renders a compact button that opens the same Side
	 * Cart panel/overlay Frontend/Cart_Renderer already render — it adds
	 * no drawer, state, route, or handler of its own. See
	 * Menu_Cart_Trigger's class doc comment for details.
	 *
	 * @return void
	 */
	public function run() {
		$this->assets->init();

		$this->menu_cart_trigger = new Menu_Cart_Trigger();
		$this->menu_cart_trigger->init();

		if ( is_admin() ) {
			$this->admin = new Admin();
			$this->admin->init();

			$this->side_cart_admin = new Side_Cart_Admin();
			$this->side_cart_admin->init();
		} else {
			$this->frontend = new Frontend();
			$this->frontend->init();

			$this->single_product_auto_open = new Single_Product_Auto_Open();
			$this->single_product_auto_open->init();
		}
	}
}
