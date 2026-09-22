<?php
/**
 * Dependency/version manifest for blocks/cart-trigger/index.js.
 *
 * register_block_type() (see Menu_Cart_Trigger::register_block() in
 * includes/class-menu-cart-trigger.php) automatically looks for a file
 * named exactly like this — `{script-file-basename}.asset.php` next to
 * the script block.json's `editorScript` points at — and, when found,
 * registers that script with these `dependencies` (so `wp.blocks`,
 * `wp.element`, `wp.blockEditor`, `wp.components`, and `wp.i18n` are
 * guaranteed to already be loaded before index.js runs, instead of
 * relying on load order) and this `version` (so a cached copy busts
 * automatically on every plugin update, exactly like every other
 * enqueued script/style in this plugin — see Assets::get_asset_version()
 * in includes/class-assets.php for the same idea applied elsewhere).
 *
 * @package ManageCart
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-element',
		'wp-block-editor',
		'wp-components',
		'wp-i18n',
	),
	'version'      => defined( 'MANAGE_CART_VERSION' ) ? MANAGE_CART_VERSION : false,
);
