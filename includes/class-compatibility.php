<?php
/**
 * WooCommerce feature compatibility declarations.
 *
 * @package ManageCart
 */

namespace ManageCart;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Compatibility
 *
 * Declares Manage Cart's compatibility with specific WooCommerce features,
 * such as High-Performance Order Storage (HPOS) and Cart/Checkout Blocks.
 */
class Compatibility {

	/**
	 * Declares compatibility with WooCommerce features via FeaturesUtil.
	 *
	 * Must run on the `before_woocommerce_init` hook. Guarded by
	 * class_exists() so it never fatals if WooCommerce isn't present.
	 *
	 * @return void
	 */
	public static function declare_compatibility() {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			MANAGE_CART_PLUGIN_FILE,
			true
		);

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			MANAGE_CART_PLUGIN_FILE,
			true
		);
	}
}
