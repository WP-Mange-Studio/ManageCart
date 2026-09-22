/**
 * Registers the `manage-cart/cart-trigger` block in the block editor.
 *
 * This is intentionally plain JavaScript against WordPress's own core
 * globals (`wp.blocks`, `wp.element`, `wp.blockEditor`, `wp.components`,
 * `wp.i18n`) — the same globals every core block already loads — rather
 * than a bundled/build-step script, matching the rest of this plugin's
 * "no external dependency" approach (see frontend.css's own header
 * comment). Nothing here talks to the cart, the Side Cart panel, or any
 * network endpoint: it only draws a static, editor-only placeholder so
 * an admin can see and select the block while building a template.
 *
 * The block is fully dynamic (server-rendered): `save()` below returns
 * `null`, so WordPress never stores this placeholder — or any other
 * markup — in post content. Every real button on the storefront comes
 * from PHP's Menu_Cart_Trigger::render_block(), which defers to the
 * exact same render() method the `[manage_cart_trigger]` shortcode
 * uses (see includes/class-menu-cart-trigger.php). That is also why
 * this file never needs to duplicate the button's markup, icon path,
 * or live cart-count logic — none of that exists on the editor side at
 * all.
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var Placeholder = components.Placeholder;

	blocks.registerBlockType( 'manage-cart/cart-trigger', {
		edit: function () {
			var blockProps = useBlockProps( {
				className: 'manage-cart-block-editor-preview',
			} );

			return el(
				'div',
				blockProps,
				el(
					Placeholder,
					{
						icon: 'cart',
						label: __( 'ManageCart Cart Trigger', 'manage-cart' ),
						instructions: __(
							'Opens the ManageCart Side Cart drawer. Use this instead of WooCommerce Mini-Cart when you want the ManageCart Side Cart drawer.',
							'manage-cart'
						),
					}
				)
			);
		},
		// Fully dynamic/server-rendered block — see Menu_Cart_Trigger::render_block()
		// in includes/class-menu-cart-trigger.php, registered as this block's
		// render_callback in Menu_Cart_Trigger::register_block(). Nothing is
		// ever saved into post content.
		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
);
