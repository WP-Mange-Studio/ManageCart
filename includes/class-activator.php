<?php
/**
 * Plugin activation handler.
 *
 * @package ManageCart
 */

namespace ManageCart;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Activator
 *
 * Handles tasks that run when Manage Cart is activated. Phase 1 only stores
 * the current plugin version; no settings or cart configuration are created.
 */
class Activator {

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		update_option( 'manage_cart_version', MANAGE_CART_VERSION );
	}
}
