<?php
/**
 * Plugin deactivation handler.
 *
 * @package ManageCart
 */

namespace ManageCart;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Deactivator
 *
 * Handles tasks that run when Manage Cart is deactivated. Deactivation does
 * not delete any stored data; that is reserved for an explicit, opt-in
 * uninstall (see manage_cart_after_uninstall() in manage-cart.php, which
 * runs through Freemius's `after_uninstall` action).
 */
class Deactivator {

	/**
	 * Runs on plugin deactivation.
	 *
	 * Phase 1 intentionally performs no cleanup on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Intentionally left empty. No data is removed on deactivation.
	}
}
