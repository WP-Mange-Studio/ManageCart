<?php
/**
 * Single Product Page Auto-open — server-side detection + safe handoff.
 *
 * @package ManageCart
 */

namespace ManageCart;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Single_Product_Auto_Open
 *
 * On archive/loop pages, add-to-cart happens over AJAX — or, when JS is
 * off, via a full redirect that appends WooCommerce's own
 * `added-to-cart` query argument — so the existing Phase 3C auto-open
 * logic in assets/js/frontend-cart.js already has something to detect
 * it by (see hasAddedToCartParam() and the `added_to_cart` /
 * `wc-blocks_added_to_cart` listeners there). The single product page's
 * own add-to-cart form is different: with WooCommerce's default
 * settings ("redirect to the cart page" left off), its form POSTs back
 * to itself and the *same* request's response is what renders back to
 * the customer — there is no redirect, so no query argument is ever
 * added, and no in-page JS event fires either, since the script that
 * would fire one is only just loading for the first time on this exact
 * response. That is why auto-open would otherwise never happen there.
 *
 * This class detects that case entirely server-side, in the same
 * request the add-to-cart happened in, by hooking WooCommerce's own
 * `woocommerce_add_to_cart` action, which only ever fires once an item
 * has actually, successfully been added to the cart — never on a
 * failed validation, an invalid/incomplete variation selection, or an
 * out-of-stock attempt, since `WC_Cart::add_to_cart()` simply is not
 * reached (or itself returns false without adding) in those cases.
 *
 * Confirming that a given successful add specifically came from the
 * single product page's own primary form (and not, say, a
 * related-product loop link rendered on that same page, or one of the
 * AJAX/blocks flows Phase 3C already handles) originally relied on
 * `is_product()` / `get_queried_object_id()` inside the
 * `woocommerce_add_to_cart` callback. That turned out to be unreliable:
 * WooCommerce processes the single-product form's POST from
 * `WC_Form_Handler::add_to_cart_action()`, hooked on `wp_loaded` — long
 * before the main query has run and WordPress's own conditional tags
 * (`is_product()` and friends) can be trusted — so the add was never
 * actually flagged there and auto-open silently never fired on any
 * single product page.
 *
 * The fix drops the query-conditional check entirely in favor of a
 * small, namespaced marker rendered directly into the single product
 * page's own add-to-cart form: a hidden input plus a WordPress nonce,
 * both scoped to the exact product being displayed, output via
 * `render_marker()` on WooCommerce's own `woocommerce_before_add_to_cart_button`
 * hook — which fires while that product is genuinely the one being
 * rendered, since this code executes as part of rendering its own
 * template. `woocommerce_add_to_cart` then only has to confirm that
 * marker and nonce came back on the POST and match the product
 * WooCommerce reports as added; it no longer needs to ask WordPress
 * what page this "is".
 *
 * The marker is rendered only when it could ever matter: ManageCart's
 * frontend is actually loaded (Frontend::should_load()), the "Auto-open
 * cart after add to cart" Side Cart setting is on, and the product
 * being displayed is a simple or variable product (the only two types
 * whose native single-product template fires
 * `woocommerce_before_add_to_cart_button` with one primary add-to-cart
 * form for the exact product being viewed — see render_marker() below
 * for why grouped/external products are out of scope).
 *
 * The "signal" this hands off to the frontend script is nothing more
 * than a plain boolean added to the existing `ManageCartFrontend` JS
 * config object already localized for this one page load (see
 * Assets::register_frontend_assets()) — no cookie, no browser storage,
 * no PHP session, no custom REST/AJAX endpoint. The marker/nonce pair
 * themselves are likewise just ordinary hidden form fields read once
 * from `$_POST` on this same request and never persisted anywhere.
 * Nothing here is written anywhere a later, separate request could
 * read it, since — with WooCommerce's default settings — there isn't a
 * later request; this response *is* the destination page.
 *
 * Single Product Page Auto-open, Part 2 (assets/js/frontend-cart.js)
 * already consumes this signal: it reuses the same handleAddedToCart()
 * every other add-to-cart flow uses to fetch the real Store API cart,
 * refresh the drawer, and open it only when not already open.
 */
class Single_Product_Auto_Open {

	/**
	 * Name of the hidden marker field rendered into the single product
	 * page's own add-to-cart form. Namespaced to avoid colliding with
	 * WooCommerce's own fields or another plugin's.
	 *
	 * @var string
	 */
	const MARKER_FIELD = 'manage_cart_single_product_auto_open';

	/**
	 * Name of the nonce field rendered alongside the marker above.
	 *
	 * @var string
	 */
	const NONCE_FIELD = 'manage_cart_single_product_auto_open_nonce';

	/**
	 * Nonce action prefix. The exact product ID is appended, so the
	 * nonce is only ever valid for the specific product it was rendered
	 * for.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'manage_cart_single_product_auto_open_';

	/**
	 * Whether a qualifying single-product-page add-to-cart was detected on
	 * this request. Set at most once per request, from
	 * maybe_flag_success() below, and never reset — a page load only ever
	 * needs to know "yes, at least once."
	 *
	 * @var bool
	 */
	protected static $detected = false;

	/**
	 * Registers the marker-rendering and detection hooks.
	 *
	 * Wired up from Plugin::run() on `plugins_loaded`, well before
	 * WooCommerce's own `wp_loaded`-hooked form handling runs, so the
	 * detection listener is always already in place by the time an
	 * add-to-cart submission is processed.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_marker' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'maybe_flag_success' ), 10, 6 );
	}

	/**
	 * Renders the hidden marker + nonce into the single product page's
	 * own primary add-to-cart form, immediately before its add-to-cart
	 * button.
	 *
	 * `woocommerce_before_add_to_cart_button` fires from inside
	 * `templates/single-product/add-to-cart/simple.php` and `variable.php`
	 * (and `external.php`, which this deliberately excludes below) while
	 * that template is rendering the global `$product` it was called
	 * for — so, unlike checking `is_product()` later inside the
	 * `woocommerce_add_to_cart` callback, there is no ambiguity here
	 * about which product this form belongs to.
	 *
	 * Rendered only when all of the following hold, so the marker never
	 * exists where it couldn't matter:
	 *
	 * - ManageCart's frontend cart is actually loaded on this request
	 *   (Frontend::should_load() — global "Enable" setting, Floating
	 *   Cart enabled, WooCommerce active, not admin/AJAX/REST).
	 * - The "Auto-open cart after add to cart" Side Cart setting is on.
	 * - The product being displayed is a simple or variable product.
	 *   Grouped products submit their *child* product IDs, never this
	 *   one's, and have no single primary add-to-cart button this hook
	 *   sits next to; external/affiliate products never actually reach
	 *   `WC_Cart::add_to_cart()` at all (the button just links offsite),
	 *   so a marker there could never be consumed by a successful add.
	 *   Both are intentionally left out.
	 *
	 * @return void
	 */
	public function render_marker() {
		if ( ! Frontend::should_load() ) {
			return;
		}

		$side_cart_settings = Side_Cart_Settings::get_settings();

		if ( empty( $side_cart_settings['auto_open_on_add'] ) ) {
			return;
		}

		global $product;

		if ( ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		if ( ! $product->is_type( 'simple' ) && ! $product->is_type( 'variable' ) ) {
			return;
		}

		$product_id = $product->get_id();

		if ( ! $product_id ) {
			return;
		}

		wp_nonce_field( self::NONCE_ACTION . $product_id, self::NONCE_FIELD );

		printf(
			'<input type="hidden" name="%1$s" value="%2$d" />',
			esc_attr( self::MARKER_FIELD ),
			(int) $product_id
		);
	}

	/**
	 * Fires on every successful WooCommerce add-to-cart, from any source
	 * (single product page, archive/loop AJAX, blocks, REST, another
	 * plugin/integration). Flags this request as a qualifying
	 * single-product-page add only when it clearly came from that page's
	 * own primary add-to-cart form — see
	 * is_single_product_page_submission() below for the exact checks.
	 *
	 * @param string $cart_item_key  Cart item key.
	 * @param int    $product_id     Product ID (the variable parent's ID for a variation).
	 * @param int    $quantity       Quantity added.
	 * @param int    $variation_id   Variation ID, or 0 for a simple product.
	 * @param array  $variation      Chosen variation attributes.
	 * @param array  $cart_item_data Extra cart item data.
	 * @return void
	 */
	public function maybe_flag_success( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		unset( $cart_item_key, $quantity, $variation_id, $variation, $cart_item_data );

		if ( self::$detected ) {
			// Already flagged earlier in this same request — nothing more
			// to check.
			return;
		}

		if ( self::is_single_product_page_submission( $product_id ) ) {
			self::$detected = true;
		}
	}

	/**
	 * Determines whether the current request is a standard (non-AJAX)
	 * WooCommerce single-product page add-to-cart form submission for the
	 * exact product being viewed:
	 *
	 * - Not wp-admin, not a REST request, and not any kind of AJAX
	 *   request — classic `wp_doing_ajax()` or WooCommerce's own
	 *   `wc-ajax` query var — since those flows are already handled by
	 *   the existing Phase 3C in-page listeners and must stay untouched.
	 * - A real HTTP POST, matching the single product template's own
	 *   `add-to-cart/simple.php` / `add-to-cart/variable.php` form
	 *   (`method="post"`) — not a GET request, which is how an
	 *   AJAX-disabled *archive/loop* "Add to cart" link submits instead,
	 *   even if one happens to be rendered on this same page (e.g. a
	 *   related or upsell product).
	 * - render_marker()'s namespaced hidden marker field is present in
	 *   the POST body and its value matches the product WooCommerce
	 *   reports as added.
	 * - render_marker()'s nonce, scoped to that exact product ID,
	 *   verifies. Together with the check above, this confirms the
	 *   submission really is that product's own add-to-cart form having
	 *   been rendered and posted back — not a related product's link,
	 *   another plugin's form, or a replayed/forged value — without
	 *   ever having to ask WordPress's conditional tags what page this
	 *   request "is" (unreliable this early — see the class doc comment
	 *   above).
	 * - The submitted `add-to-cart` field matches that same product ID,
	 *   confirming this really is that form's own submission.
	 *
	 * Grouped and external/affiliate products are intentionally out of
	 * scope: render_marker() never renders a marker for them, so this
	 * simply never finds one to match.
	 *
	 * @param int $product_id Product ID WooCommerce reports as added.
	 * @return bool
	 */
	protected static function is_single_product_page_submission( $product_id ) {
		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( ! empty( $_REQUEST['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		if ( empty( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			return false;
		}

		if ( empty( $_POST[ self::MARKER_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}

		$marker_product_id = (int) wp_unslash( $_POST[ self::MARKER_FIELD ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		if ( ! $marker_product_id || $marker_product_id !== (int) $product_id ) {
			return false;
		}

		if ( empty( $_POST[ self::NONCE_FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION . $marker_product_id ) ) {
			return false;
		}

		if ( empty( $_POST['add-to-cart'] ) || (int) $_POST['add-to-cart'] !== (int) $product_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}

		return true;
	}

	/**
	 * Whether a qualifying single-product-page add-to-cart was detected on
	 * this request.
	 *
	 * Read by Assets::register_frontend_assets(), which only actually
	 * hands the signal to the frontend script when this is true *and*
	 * the "Auto-open cart after add to cart" Side Cart setting is
	 * itself on — see that method for the full condition, which is what
	 * guarantees no signal is ever created while that setting is off.
	 * The frontend script (Part 2, assets/js/frontend-cart.js) consumes
	 * that signal immediately: it reuses handleAddedToCart() to fetch
	 * the real Store API cart, refresh the drawer, and open it only
	 * when not already open.
	 *
	 * @return bool
	 */
	public static function was_detected() {
		return self::$detected;
	}
}
