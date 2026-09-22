<?php
/**
 * Asset registration placeholder.
 *
 * @package ManageCart
 */

namespace ManageCart;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Assets
 *
 * Placeholder for future conditional registration and enqueuing of frontend
 * cart UI assets (side drawer cart, popup cart, mobile bottom-sheet cart,
 * floating cart button), reserved for later phases. As of Phase 2E, both
 * existing admin panels (Settings and Side Cart) render on the single
 * shared top-level "Manage Cart" screen, so this class enqueues all of the
 * existing admin stylesheets and scripts together on that one screen: the
 * shared app-header stylesheet, the Settings panel stylesheet, the Side
 * Cart panel stylesheet, the Side Cart live-preview script, and the
 * app-header tab-switching script. Phase 3A adds the frontend cart
 * trigger/panel stylesheet and script, conditionally enqueued on
 * wp_enqueue_scripts (see register_frontend_assets()). Phase 3B-1 Part 2
 * adds the Store API cart endpoint URLs and nonce that script needs to
 * connect the existing quantity/remove controls to WooCommerce, without
 * any custom PHP AJAX handler.
 *
 * Phase 3G-3B adds the Floating Cart → Appearance live-preview script
 * (see enqueue_floating_cart_preview_script()), enqueued on the same
 * shared screen alongside the Side Cart live-preview script.
 *
 * Free build only: also enqueues assets/css/upgrade-tab.css for the
 * "Upgrade to Pro" tab (see Upgrade_Tab), and only when that tab exists
 * (see Admin::is_upgrade_tab_available()), so the Premium build never
 * loads it.
 */
class Assets {

	/**
	 * Admin page hook suffix for the single shared top-level "Manage Cart"
	 * screen, assigned by WordPress to a top-level menu page registered
	 * with the `MANAGE_CART_SETTINGS_SLUG` slug.
	 *
	 * @var string
	 */
	const SETTINGS_PAGE_HOOK = 'toplevel_page_' . MANAGE_CART_SETTINGS_SLUG;

	/**
	 * Registers asset-related hooks.
	 *
	 * In Phase 2A, this wires up the conditional admin stylesheet. Phase 2B
	 * adds a second, separately-scoped stylesheet for the Side Cart page.
	 * Phase 3A adds the frontend cart trigger/panel stylesheet and script,
	 * conditionally enqueued on wp_enqueue_scripts (see
	 * register_frontend_assets()).
	 *
	 * @return void
	 */
	public function init() {
		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'register_admin_assets' ) );
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'register_frontend_assets' ) );
	}

	/**
	 * Conditionally enqueues the frontend cart trigger/panel stylesheet and
	 * script. Bails out unless Frontend::should_load() confirms this is a
	 * qualifying front-end request: not wp-admin/REST/AJAX, WooCommerce
	 * active, and the global Manage Cart "Enable" setting on.
	 *
	 * As of Phase 3B-1 Part 2, this also localizes the WooCommerce Store
	 * API cart endpoints and a `wc_store_api` nonce so the frontend script
	 * can call the Store API directly (no custom PHP AJAX handlers, no
	 * jQuery).
	 *
	 * Phase 3B-1 Part 3 adds a handful of read-only values the script
	 * needs to update the open cart panel's counts, subtotal, and empty
	 * state from those same Store API responses: the panel title id (to
	 * move focus there when the cart becomes empty), whether the store's
	 * existing "Cart item prices" display setting shows tax-inclusive
	 * amounts (read from the WooCommerce tax options — no new setting is
	 * introduced), the shop page URL for the empty-cart "Start Shopping"
	 * link, and the matching translatable strings. None of this changes
	 * what is enqueued or when.
	 *
	 * Phase 3C adds the Side Cart "Auto-open cart after add to cart"
	 * setting value, so the script can open the panel on the first
	 * successful add-to-cart and keep it live on later adds.
	 *
	 * The auto-open/live-cart fix removes the plugin's own custom
	 * cart-panel REST route entirely. In its place, this localizes the
	 * real WooCommerce Store API cart URL (`GET /wc/store/v1/cart`) the
	 * script now fetches directly after every successful add-to-cart, the
	 * static View Cart/Checkout URLs the footer links to (these do not
	 * depend on live cart data, so there is no need to fetch them), the
	 * Side Cart display toggles (product image, variation attributes,
	 * quantity controls, remove button, low stock badge) so the script can
	 * mirror the server-rendered panel's rules when it rebuilds rows from
	 * the Store API response, and the translatable label templates
	 * (remove/quantity control aria-labels, the read-only "Qty: %d" text,
	 * the sale "Save %d%%" badge, the low stock text, and the footer's
	 * Subtotal/View Cart/Continue Shopping/Checkout strings) the script needs to build a
	 * whole cart item row or footer entirely from
	 * document.createElement()/.textContent, matching Cart_Renderer's own
	 * server-rendered markup without ever using innerHTML.
	 *
	 * Phase 3E-2 reads `allowTriggerDrag` from Floating_Cart_Settings
	 * instead of Side_Cart_Settings, now that the "Allow customers to move
	 * floating cart button" setting lives there, and adds `hideWhenEmpty`
	 * so the script can keep the floating trigger hidden while the cart
	 * has no items, even as the cart changes live without a page reload.
	 *
	 * Phase 3F-1 adds the real WooCommerce Store API coupon endpoint
	 * (`POST /wc/store/v1/cart/apply-coupon`) the script posts to when the
	 * customer applies a coupon — no custom PHP AJAX handler or REST
	 * route — plus the same `wc_coupons_enabled()` flag
	 * Cart_Renderer::render_coupon_section() uses server-side, so the
	 * script hides/omits the coupon section when it rebuilds the footer
	 * from a live Store API response, and the translatable coupon
	 * strings needed to build that section entirely from
	 * document.createElement()/.textContent.
	 *
	 * Phase 3H-1 adds the matching `POST /wc/store/v1/cart/remove-coupon`
	 * Store API endpoint the script posts to when the customer clicks a
	 * currently-applied coupon's Remove button (see
	 * Cart_Renderer::render_applied_coupons()), plus the translatable
	 * "Coupon removed."/error/Remove-button-label strings needed to
	 * announce the result and rebuild the applied-coupons list entirely
	 * from document.createElement()/.textContent. Same nonce lifecycle,
	 * no custom PHP AJAX handler or REST route, no new admin setting.
	 *
	 * The Cart UX pass adds the `continueShoppingButton` translatable
	 * string for the footer's new "Continue Shopping" button. It reuses
	 * the existing `shopUrl` value already localized below (previously
	 * only read by the empty-cart state's "Start Shopping" link); the
	 * script omits the button entirely when `shopUrl` is empty, matching
	 * Cart_Renderer::render_footer()'s server-side guard.
	 *
	 * Undo Removed Cart Item (Free, Part 1) adds the real WooCommerce
	 * Store API `POST /wc/store/v1/cart/add-item` endpoint the script
	 * posts to when the customer clicks Undo on the notice shown after a
	 * successful item removal (see Cart_Renderer::render_undo_notice()),
	 * plus that notice's element/button ids and translatable strings.
	 * Same nonce lifecycle as every other Store API request here, no
	 * custom PHP AJAX handler or REST route, no new admin setting.
	 *
	 * @return void
	 */
	public function register_frontend_assets() {
		if ( ! Frontend::should_load() ) {
			return;
		}

		wp_enqueue_style(
			'manage-cart-frontend',
			MANAGE_CART_PLUGIN_URL . 'assets/css/frontend.css',
			array(),
			$this->get_asset_version( 'assets/css/frontend.css' )
		);

		wp_enqueue_script(
			'manage-cart-frontend',
			MANAGE_CART_PLUGIN_URL . 'assets/js/frontend-cart.js',
			array(),
			$this->get_asset_version( 'assets/js/frontend-cart.js' ),
			true
		);

		$side_cart_settings     = Side_Cart_Settings::get_settings();
		$floating_cart_settings = Floating_Cart_Settings::get_settings();

		$prices_include_tax = function_exists( 'wc_tax_enabled' )
			&& wc_tax_enabled()
			&& 'incl' === get_option( 'woocommerce_tax_display_cart' );

		$shop_url     = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
		$cart_url     = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : '';
		$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '';

		// Single Product Page Auto-open, Part 3: the customer can land on
		// the native Cart or Checkout page carrying WooCommerce's own
		// `added-to-cart` query argument — e.g. when the store has
		// "redirect to the cart page after successful addition" enabled,
		// or a theme/plugin redirects add-to-cart to Checkout — and the
		// frontend script's existing hasAddedToCartParam() check (see
		// assets/js/frontend-cart.js) would otherwise treat that exactly
		// like any other page-reload add-to-cart and auto-open the Side
		// Cart on top of the page that already *is* the cart/checkout
		// view. This flag lets the script suppress auto-opening there
		// specifically, without touching the panel's normal availability
		// or its own live refresh on those pages.
		$is_cart_or_checkout_page = ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() );

		$frontend_config = array(
				'allowTriggerDrag'         => ! empty( $floating_cart_settings['allow_trigger_drag'] ),
				'hideWhenEmpty'            => ! empty( $floating_cart_settings['hide_when_empty'] ),
				'autoOpenOnAdd'            => ! empty( $side_cart_settings['auto_open_on_add'] ),
				'isCartOrCheckoutPage'     => $is_cart_or_checkout_page,
				'showUndoCountdown'        => ! empty( $side_cart_settings['show_undo_countdown'] ),
				'statusId'                 => Cart_Renderer::STATUS_ID,
				'titleId'                  => Cart_Renderer::TITLE_ID,
				'storeApiNonce'            => wp_create_nonce( 'wc_store_api' ),
				'updateItemUrl'            => esc_url_raw( rest_url( 'wc/store/v1/cart/update-item' ) ),
				'removeItemUrl'            => esc_url_raw( rest_url( 'wc/store/v1/cart/remove-item' ) ),
				'applyCouponUrl'           => esc_url_raw( rest_url( 'wc/store/v1/cart/apply-coupon' ) ),
				'removeCouponUrl'          => esc_url_raw( rest_url( 'wc/store/v1/cart/remove-coupon' ) ),
				'addItemUrl'               => esc_url_raw( rest_url( 'wc/store/v1/cart/add-item' ) ),
				'cartApiUrl'               => esc_url_raw( rest_url( 'wc/store/v1/cart' ) ),
				'undoNoticeId'             => Cart_Renderer::UNDO_NOTICE_ID,
				'undoButtonId'             => Cart_Renderer::UNDO_BUTTON_ID,
				'couponsEnabled'           => function_exists( 'wc_coupons_enabled' ) && wc_coupons_enabled(),
				'pricesIncludeTax'         => $prices_include_tax,
				'shopUrl'                  => $shop_url ? esc_url_raw( $shop_url ) : '',
				'cartUrl'                  => $cart_url ? esc_url_raw( $cart_url ) : '',
				'checkoutUrl'              => $checkout_url ? esc_url_raw( $checkout_url ) : '',
				'showProductImage'         => ! empty( $side_cart_settings['show_product_image'] ),
				'showVariationAttributes'  => ! empty( $side_cart_settings['show_variation_attributes'] ),
				'showQuantityControls'     => ! empty( $side_cart_settings['show_quantity_controls'] ),
				'showRemoveButton'         => ! empty( $side_cart_settings['show_remove_button'] ),
				'showLowStockBadge'        => ! empty( $side_cart_settings['show_low_stock_badge'] ),
				'i18n'              => array(
					'updated'             => __( 'Cart updated.', 'manage-cart' ),
					'removed'             => __( 'Item removed from cart.', 'manage-cart' ),
					'error'               => __( 'Something went wrong. Please try again.', 'manage-cart' ),
					/* translators: %d: number of items currently in the cart (always exactly 1 for this string). */
					'triggerLabelSingle'  => __( 'Open cart, %d item', 'manage-cart' ),
					/* translators: %d: number of items currently in the cart. */
					'triggerLabelPlural'  => __( 'Open cart, %d items', 'manage-cart' ),
					'emptyTitle'          => __( 'Your cart is empty', 'manage-cart' ),
					'emptyText'           => __( 'Looks like you haven\'t added anything yet.', 'manage-cart' ),
					'emptyShopButton'     => __( 'Start Shopping', 'manage-cart' ),
					/* translators: %s: product name. */
					'removeLabel'         => __( 'Remove %s', 'manage-cart' ),
					/* translators: %s: product name. */
					'qtyGroupLabel'       => __( 'Quantity for %s', 'manage-cart' ),
					/* translators: %s: product name. */
					'qtyOfLabel'          => __( 'Quantity of %s', 'manage-cart' ),
					/* translators: %s: product name. */
					'decreaseLabel'       => __( 'Decrease quantity of %s', 'manage-cart' ),
					/* translators: %s: product name. */
					'increaseLabel'       => __( 'Increase quantity of %s', 'manage-cart' ),
					/* translators: %d: quantity of this line item in the cart. */
					'qtyReadOnly'         => __( 'Qty: %d', 'manage-cart' ),
					/* translators: %d: percentage discount off the regular price. */
					'saveLabel'           => __( 'Save %d%%', 'manage-cart' ),
					/* translators: %d: number of items left in stock. */
					'lowStockText'        => __( 'Only %d left', 'manage-cart' ),
					'subtotalLabel'       => __( 'Subtotal', 'manage-cart' ),
					// Phase-current: renamed from "You save" so it reads
					// unambiguously as the product/sale-price savings row,
					// now that a separate "Coupon discount" row also exists.
					'savingsLabel'        => __( 'Product savings', 'manage-cart' ),
					'couponDiscountLabel' => __( 'Coupon discount', 'manage-cart' ),
					'totalLabel'          => __( 'Total', 'manage-cart' ),
					'viewCartButton'      => __( 'View Cart', 'manage-cart' ),
					'continueShoppingButton' => __( 'Continue Shopping', 'manage-cart' ),
					'checkoutButton'      => __( 'Checkout', 'manage-cart' ),
					'couponToggleLabel'   => __( 'Have a coupon?', 'manage-cart' ),
					'couponInputLabel'    => __( 'Coupon code', 'manage-cart' ),
					'couponApplyButton'   => __( 'Apply', 'manage-cart' ),
					'couponEmpty'         => __( 'Please enter a coupon code.', 'manage-cart' ),
					'couponApplied'       => __( 'Coupon applied.', 'manage-cart' ),
					'couponError'         => __( 'Could not apply that coupon. Please try again.', 'manage-cart' ),
					'couponRemoved'       => __( 'Coupon removed.', 'manage-cart' ),
					'couponRemoveError'   => __( 'Could not remove that coupon. Please try again.', 'manage-cart' ),
					'couponRemoveButton'  => __( 'Remove', 'manage-cart' ),
					/* translators: %s: coupon code. */
					'removeCouponLabel'   => __( 'Remove coupon %s', 'manage-cart' ),
					// Undo Removed Cart Item (Free, Part 1).
					'productRemoved'      => __( 'Product removed', 'manage-cart' ),
					'undoButton'          => __( 'Undo', 'manage-cart' ),
					/* translators: %s: product name. */
					'undoAriaLabel'       => __( 'Undo removing %s', 'manage-cart' ),
					'undoRestored'        => __( 'Item restored.', 'manage-cart' ),
					'undoError'           => __( 'Could not restore that item. Please try again.', 'manage-cart' ),
					// Undo notice countdown ("Show Undo countdown" setting).
					/* translators: %d: seconds remaining to undo the removal, shown as compact text on the Undo button (e.g. "Undo (6)"). */
					'undoCountdownFormat' => __( '(%d)', 'manage-cart' ),
					/* translators: %d: seconds the customer has left to press Undo. One-time, polite screen-reader announcement — not repeated every second. */
					'removedWithUndoCountdown' => __( 'Item removed from cart. Press Undo within %d seconds to restore it.', 'manage-cart' ),
				),
		);

		// Single Product Page Auto-open: hand off the detection signal —
		// a plain boolean, nothing else — only when both a qualifying
		// single-product-page add-to-cart was actually detected on this
		// request (see Single_Product_Auto_Open) and the "Auto-open cart
		// after add to cart" setting is itself on. When either is false,
		// this key is left out of the localized config entirely rather
		// than being sent as `false`, so no signal of any kind exists on
		// the page when auto-open is disabled. The frontend script
		// (assets/js/frontend-cart.js) reads and immediately clears this
		// key, reusing the same handleAddedToCart() every other
		// add-to-cart flow uses to refresh the drawer from the real
		// Store API cart and open it only when not already open.
		if ( ! empty( $side_cart_settings['auto_open_on_add'] ) && Single_Product_Auto_Open::was_detected() ) {
			$frontend_config['singleProductAddToCartSuccess'] = true;
		}

		wp_localize_script( 'manage-cart-frontend', 'ManageCartFrontend', $frontend_config );
	}

	/**
	 * Conditionally enqueues the local admin stylesheets and scripts on
	 * the single shared Manage Cart screen. The Side Cart submenu page
	 * redirects away before this hook ever fires for it (see
	 * Side_Cart_Admin::redirect_to_shared_screen()); the direct hook check
	 * below is kept only as a defensive fallback.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function register_admin_assets( $hook_suffix ) {
		if ( self::SETTINGS_PAGE_HOOK === $hook_suffix ) {
			$this->enqueue_shared_screen_assets();
			return;
		}

		if ( $this->is_side_cart_hook( $hook_suffix ) ) {
			// Defensive fallback: should not normally be reached, since the
			// Side Cart submenu page redirects to the shared screen before
			// admin_enqueue_scripts fires for it.
			$this->enqueue_shared_screen_assets();
			return;
		}
	}

	/**
	 * Resolves the enqueue version string for a local asset file: the
	 * file's modification time when the file exists on disk (so browsers
	 * fetch a fresh copy whenever the file changes, even while the plugin
	 * version stays at 0.1.0), falling back to MANAGE_CART_VERSION when the
	 * file cannot be found. This only affects the cache-busting query
	 * string WordPress appends to enqueued asset URLs; it does not change
	 * MANAGE_CART_VERSION itself or anywhere it is displayed.
	 *
	 * @param string $relative_path Asset path relative to the plugin
	 *                               directory, e.g. 'assets/css/admin.css'.
	 * @return string
	 */
	protected function get_asset_version( $relative_path ) {
		$file_path = MANAGE_CART_PLUGIN_DIR . $relative_path;

		if ( file_exists( $file_path ) ) {
			$mtime = filemtime( $file_path );

			if ( false !== $mtime ) {
				return (string) $mtime;
			}
		}

		return MANAGE_CART_VERSION;
	}

	/**
	 * Enqueues every stylesheet and script used by the shared Manage Cart
	 * screen: the app header, both panels' stylesheets, the Side Cart live
	 * preview script, the app header tab-switching script, and (Phase 3D)
	 * the Side Cart panel's own "Side Cart" / "Floating Cart" section and
	 * "General" / "Style" tab-switching script. Each local file's enqueue
	 * version is its own modification time (see get_asset_version()), so
	 * browsers pick up changes to any one file without needing a plugin
	 * version bump.
	 *
	 * @return void
	 */
	protected function enqueue_shared_screen_assets() {
		wp_enqueue_style(
			'manage-cart-app-header',
			MANAGE_CART_PLUGIN_URL . 'assets/css/app-header.css',
			array(),
			$this->get_asset_version( 'assets/css/app-header.css' )
		);
		wp_enqueue_style(
			'manage-cart-admin',
			MANAGE_CART_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			$this->get_asset_version( 'assets/css/admin.css' )
		);
		wp_enqueue_style(
			'manage-cart-side-cart-admin',
			MANAGE_CART_PLUGIN_URL . 'assets/css/side-cart-admin.css',
			array(),
			$this->get_asset_version( 'assets/css/side-cart-admin.css' )
		);
		// Free build only: styles for the "Upgrade to Pro" tab (see
		// Upgrade_Tab). Enqueued only when the tab exists, so the Premium
		// build, which never renders the tab, does not load it.
		if ( Admin::is_upgrade_tab_available() ) {
			wp_enqueue_style(
				'manage-cart-upgrade-tab',
				MANAGE_CART_PLUGIN_URL . 'assets/css/upgrade-tab.css',
				array(),
				$this->get_asset_version( 'assets/css/upgrade-tab.css' )
			);
		}

		wp_enqueue_script(
			'manage-cart-app-header-tabs',
			MANAGE_CART_PLUGIN_URL . 'assets/js/app-header-tabs.js',
			array(),
			$this->get_asset_version( 'assets/js/app-header-tabs.js' ),
			true
		);

		wp_enqueue_script(
			'manage-cart-side-cart-section-tabs',
			MANAGE_CART_PLUGIN_URL . 'assets/js/side-cart-section-tabs.js',
			array(),
			$this->get_asset_version( 'assets/js/side-cart-section-tabs.js' ),
			true
		);

		$this->enqueue_side_cart_preview_script();
		$this->enqueue_floating_cart_preview_script();
		$this->enqueue_preview_state_toggle_script();
	}

	/**
	 * Determines whether the given admin hook suffix belongs to the
	 * "Manage Cart → Side Cart" submenu page. The Side Cart page is a
	 * submenu page, so WordPress does not assign it a single fixed,
	 * predictable hook suffix the way it does for the top-level page (the
	 * exact suffix depends on how/where the submenu is registered). Rather
	 * than hardcoding a possibly-wrong exact suffix, this safely detects it
	 * by checking whether the current hook suffix ends with
	 * `_page_manage-cart-side-cart`, which WordPress always appends for a
	 * submenu page registered with the `manage-cart-side-cart` slug,
	 * regardless of the parent slug prefix.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return bool
	 */
	protected function is_side_cart_hook( $hook_suffix ) {
		$side_cart_hook_suffix = '_page_manage-cart-side-cart';
		$hook_suffix_length    = strlen( $hook_suffix );
		$needle_length         = strlen( $side_cart_hook_suffix );

		return $hook_suffix_length >= $needle_length
			&& substr( $hook_suffix, -$needle_length ) === $side_cart_hook_suffix;
	}

	/**
	 * Enqueues the Side Cart live preview script (Phase 2C) and localizes
	 * the translatable strings and numeric bounds it needs. The script
	 * only reads values already present in the settings form fields on
	 * this page, plus the `products` entries prepared below, and only
	 * writes into the preview markup rendered by
	 * Side_Cart_Admin::render_preview() (up to two real, published
	 * WooCommerce products where available, otherwise translated sample
	 * entries). It never itself requests WooCommerce or Store API data —
	 * `products` is read once from this localized object, already
	 * prepared server-side by Side_Cart_Admin::get_preview_products().
	 *
	 * @return void
	 */
	protected function enqueue_side_cart_preview_script() {
		wp_enqueue_script(
			'manage-cart-side-cart-preview',
			MANAGE_CART_PLUGIN_URL . 'assets/js/side-cart-preview.js',
			array(),
			$this->get_asset_version( 'assets/js/side-cart-preview.js' ),
			true
		);

		$layout_choices      = Side_Cart_Settings::get_layout_choices();
		$drawer_side_choices = Side_Cart_Settings::get_drawer_side_choices();

		wp_localize_script(
			'manage-cart-side-cart-preview',
			'ManageCartSideCartPreview',
			array(
				'layoutDrawerLabel' => isset( $layout_choices['drawer'] ) ? $layout_choices['drawer'] : __( 'Drawer', 'manage-cart' ),
				'layoutPopupLabel'  => isset( $layout_choices['popup'] ) ? $layout_choices['popup'] : __( 'Popup', 'manage-cart' ),
				'positionLeftLabel' => isset( $drawer_side_choices['left'] ) ? $drawer_side_choices['left'] : __( 'Left', 'manage-cart' ),
				'positionRightLabel' => isset( $drawer_side_choices['right'] ) ? $drawer_side_choices['right'] : __( 'Right', 'manage-cart' ),
				/* translators: %s: selected cart layout label (Drawer or Popup). */
				'layoutFormat'      => __( 'Layout: %s', 'manage-cart' ),
				/* translators: %s: selected drawer position label (Right or Left). */
				'positionFormat'    => __( 'Position: %s', 'manage-cart' ),
				/* translators: %d: desktop cart panel width in pixels. */
				'widthFormat'       => __( 'Desktop width: %d px', 'manage-cart' ),
				'autoOpenOnLabel'   => __( 'Auto-open on add to cart: On', 'manage-cart' ),
				'autoOpenOffLabel'  => __( 'Auto-open on add to cart: Off', 'manage-cart' ),
				// Up to two real, published, catalog-visible WooCommerce
				// products (name, image URL/alt, formatted price),
				// prepared server-side by
				// Side_Cart_Admin::get_preview_products() and already
				// used to render the two static preview line items above.
				// Included here too so the config object stays a
				// complete description of what the preview shows.
				// Admin-preview-only: no cart/session/customer data, no
				// AJAX/REST/fetch, and this never touches the real,
				// customer-facing Side Cart.
				'products'          => Side_Cart_Admin::get_preview_products(),
			)
		);
	}

	/**
	 * Enqueues the Side Cart Live Preview's "Preview state" switch
	 * script (Phase 3I-1), which lets the admin flip the shared preview
	 * card between its normal "Cart items" mock content and a clean
	 * "Empty cart" view. Needs no localized strings or settings data —
	 * every string it needs is already rendered server-side by
	 * Side_Cart_Admin::render_preview(), and it only ever toggles a
	 * single CSS class; see assets/js/preview-state-toggle.js for the
	 * full explanation of how it does this without affecting any other
	 * script's own state. Enqueued unconditionally alongside the Side
	 * Cart preview script itself (both only ever load together, on the
	 * same shared screen), so no explicit script dependency is needed
	 * for correctness — each looks up its own elements independently.
	 *
	 * @return void
	 */
	protected function enqueue_preview_state_toggle_script() {
		wp_enqueue_script(
			'manage-cart-preview-state-toggle',
			MANAGE_CART_PLUGIN_URL . 'assets/js/preview-state-toggle.js',
			array(),
			$this->get_asset_version( 'assets/js/preview-state-toggle.js' ),
			true
		);
	}

	/**
	 * Enqueues the Floating Cart → Appearance live preview script
	 * (Phase 3G-3B). The script only reads values already present in the
	 * Appearance settings form fields on this page and only writes into
	 * the static mock preview markup; it never requests WooCommerce or
	 * Store API data. Unlike enqueue_side_cart_preview_script(), it needs
	 * no localized translatable strings — every value it displays is a
	 * CSS custom property, not text.
	 *
	 * @return void
	 */
	protected function enqueue_floating_cart_preview_script() {
		wp_enqueue_script(
			'manage-cart-floating-cart-preview',
			MANAGE_CART_PLUGIN_URL . 'assets/js/floating-cart-preview.js',
			array(),
			$this->get_asset_version( 'assets/js/floating-cart-preview.js' ),
			true
		);
	}
}
