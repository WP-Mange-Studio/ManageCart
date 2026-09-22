<?php
/**
 * "Manage Cart → Side Cart" wp-admin page (Phase 2B, header/live preview
 * updated in Phase 2C, folded into the shared Manage Cart screen in
 * Phase 2E).
 *
 * @package ManageCart
 */

namespace ManageCart;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Side_Cart_Admin
 *
 * Registers the "Side Cart" submenu page under the top-level "Manage Cart"
 * menu so its wp-admin sidebar row stays visible and unchanged. As of
 * Phase 2E, the Side Cart panel itself is rendered by Admin::render_page()
 * as part of the single shared Manage Cart screen (see
 * render_panel_content() below); visiting this submenu's own URL directly
 * redirects to that shared screen with the Side Cart tab selected. Access
 * is restricted to users with the `manage_woocommerce` capability,
 * matching the global Manage Cart settings page.
 *
 * This page only reads and writes the `manage_cart_side_cart_settings`
 * option. It does not load, render, or enqueue any frontend cart markup,
 * scripts, or Store API requests; the preview card on this screen is
 * illustrative admin UI only — its layout, colors, quantities, and
 * variation text are static mock data, while its two sample line items'
 * name and thumbnail are, where available, a real published WooCommerce
 * product (see get_preview_products()), falling back to translated
 * sample entries when the store has fewer than two eligible products.
 * A local script now updates all of this live before Save.
 */
class Side_Cart_Admin {

	/**
	 * Capability required to view or change Side Cart settings.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * admin-post.php action name used for the Reset Side Cart Settings form.
	 *
	 * @var string
	 */
	const RESET_ACTION = 'manage_cart_reset_side_cart_settings';

	/**
	 * Nonce action used for the Reset Side Cart Settings form.
	 *
	 * @var string
	 */
	const RESET_NONCE_ACTION = 'manage_cart_reset_side_cart_settings_action';

	/**
	 * Nonce field name used for the Reset Side Cart Settings form.
	 *
	 * @var string
	 */
	const RESET_NONCE_NAME = 'manage_cart_reset_side_cart_settings_nonce';

	/**
	 * Stable HTML id given to the Side Cart → General settings form. Used
	 * by CSS/JS via the shared `manage-cart-savable-form` class; the form
	 * renders its own Save Changes submit button directly inside itself
	 * (Phase 3E-5).
	 *
	 * @var string
	 */
	const SETTINGS_FORM_ID = 'manage-cart-side-cart-settings-form';

	/**
	 * admin-post.php action name used to save Side Cart → General
	 * (Phase 3F-6). This is a dedicated handler, not the WordPress Settings
	 * API/options.php — see handle_side_cart_save().
	 *
	 * @var string
	 */
	const SAVE_ACTION = 'manage_cart_save_side_cart_settings';

	/**
	 * Nonce action used for the Side Cart → General save form.
	 *
	 * @var string
	 */
	const SAVE_NONCE_ACTION = 'manage_cart_save_side_cart_settings_action';

	/**
	 * Nonce field name used for the Side Cart → General save form.
	 *
	 * @var string
	 */
	const SAVE_NONCE_NAME = 'manage_cart_save_side_cart_settings_nonce';

	/**
	 * Maximum number of real WooCommerce products shown in the Live
	 * Preview's sample cart items. Matches the number of static preview
	 * line items rendered by render_preview() (two: "Sample Product A"
	 * and "Sample Product B" slots) — this is admin illustration only,
	 * never the real Side Cart, which always reflects the actual cart
	 * contents via Cart_Renderer.
	 *
	 * @var int
	 */
	const PREVIEW_PRODUCTS_MAX = 2;

	/**
	 * WooCommerce image size used for the Live Preview's product
	 * thumbnails, and for the local placeholder used in its place.
	 *
	 * @var string
	 */
	const PREVIEW_IMAGE_SIZE = 'woocommerce_thumbnail';

	/**
	 * Transient key prefix used to show the one-time "Side Cart settings
	 * saved." notice (Phase 3F-6).
	 *
	 * The full key is this prefix suffixed with the current user's ID (see
	 * get_side_cart_saved_transient_key()), so the notice is scoped to the
	 * user who performed the save and cannot be triggered for, or leaked
	 * to, any other logged-in user. Mirrors
	 * FLOATING_CART_SAVED_TRANSIENT_PREFIX below.
	 *
	 * @var string
	 */
	const SIDE_CART_SAVED_TRANSIENT_PREFIX = 'manage_cart_side_cart_saved_';

	/**
	 * Name of the hidden field, present in both the Side Cart → General and
	 * Side Cart → Appearance forms, that tells handle_side_cart_save() which
	 * of the two forms was submitted (Phase 3G-2A) — 'general' or
	 * 'appearance'. This lets both forms safely share one save handler and
	 * one option without either one resetting the other's fields to their
	 * defaults (see handle_side_cart_save()).
	 *
	 * @var string
	 */
	const SAVE_SCOPE_FIELD = 'manage_cart_side_cart_save_scope';

	/**
	 * admin-post.php action name used for the Reset Floating Cart Settings
	 * form.
	 *
	 * @var string
	 */
	const FLOATING_CART_RESET_ACTION = 'manage_cart_reset_floating_cart_settings';

	/**
	 * Nonce action used for the Reset Floating Cart Settings form.
	 *
	 * @var string
	 */
	const FLOATING_CART_RESET_NONCE_ACTION = 'manage_cart_reset_floating_cart_settings_action';

	/**
	 * Nonce field name used for the Reset Floating Cart Settings form.
	 *
	 * @var string
	 */
	const FLOATING_CART_RESET_NONCE_NAME = 'manage_cart_reset_floating_cart_settings_nonce';

	/**
	 * Stable HTML id given to the Floating Cart → General settings form
	 * (Phase 3E-1). Used by CSS/JS via the shared `manage-cart-savable-form`
	 * class; the form renders its own Save Changes submit button directly
	 * inside itself (Phase 3E-5).
	 *
	 * @var string
	 */
	const FLOATING_CART_SETTINGS_FORM_ID = 'manage-cart-floating-cart-settings-form';

	/**
	 * admin-post.php action name used to save Floating Cart → General
	 * (Phase 3E-6). This is a dedicated handler, not the WordPress Settings
	 * API/options.php — see handle_floating_cart_save().
	 *
	 * @var string
	 */
	const FLOATING_CART_SAVE_ACTION = 'manage_cart_save_floating_cart_settings';

	/**
	 * Nonce action used for the Floating Cart → General save form.
	 *
	 * @var string
	 */
	const FLOATING_CART_SAVE_NONCE_ACTION = 'manage_cart_save_floating_cart_settings_action';

	/**
	 * Nonce field name used for the Floating Cart → General save form.
	 *
	 * @var string
	 */
	const FLOATING_CART_SAVE_NONCE_NAME = 'manage_cart_save_floating_cart_settings_nonce';

	/**
	 * Transient key prefix used to show the one-time "Floating Cart
	 * settings saved." notice (Phase 3F-3).
	 *
	 * The full key is this prefix suffixed with the current user's ID
	 * (see get_floating_cart_saved_transient_key()), so the notice is
	 * scoped to the user who performed the save and cannot be triggered
	 * for, or leaked to, any other logged-in user. This replaces the
	 * previous `manage_cart_floating_cart_saved` URL query-arg flag,
	 * which stayed true for as long as that value remained in the URL
	 * (including if a user copied/reloaded/bookmarked the link) and had
	 * no server-side concept of having already been shown.
	 *
	 * @var string
	 */
	const FLOATING_CART_SAVED_TRANSIENT_PREFIX = 'manage_cart_floating_cart_saved_';

	/**
	 * Name of the hidden field, present in both the Floating Cart →
	 * General and Floating Cart → Appearance forms, that tells
	 * handle_floating_cart_save() which of the two forms was submitted
	 * (Phase 3G-3A) — 'general' or 'appearance'. This lets both forms
	 * safely share one save handler and one option without either one
	 * resetting the other's fields to their defaults (see
	 * handle_floating_cart_save()). Mirrors SAVE_SCOPE_FIELD above.
	 *
	 * @var string
	 */
	const FLOATING_CART_SAVE_SCOPE_FIELD = 'manage_cart_floating_cart_save_scope';

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( '\ManageCart\Side_Cart_Settings', 'register' ) );
		add_action( 'admin_init', array( '\ManageCart\Floating_Cart_Settings', 'register' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( $this, 'handle_reset' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_side_cart_save' ) );
		add_action( 'admin_post_' . self::FLOATING_CART_RESET_ACTION, array( $this, 'handle_floating_cart_reset' ) );
		add_action( 'admin_post_' . self::FLOATING_CART_SAVE_ACTION, array( $this, 'handle_floating_cart_save' ) );
	}

	/**
	 * Registers the "Cart Display" submenu page under the top-level Manage
	 * Cart menu. Its duplicate sidebar row is removed by Admin::arrange_submenus()
	 * (the primary "Cart Display" row is the top-level page's own), but the
	 * page itself stays fully registered and capability-checked, and its
	 * own page load redirects to the shared Manage Cart screen with the
	 * Cart Display tab selected (see redirect_to_shared_screen()). The
	 * submenu slug (MANAGE_CART_SIDE_CART_SLUG) and the `tab=side-cart`
	 * URL parameter it redirects to are unchanged.
	 *
	 * @return void
	 */
	public function register_menu() {
		$hook_suffix = add_submenu_page(
			MANAGE_CART_SETTINGS_SLUG,
			__( 'Cart Display', 'manage-cart' ),
			__( 'Cart Display', 'manage-cart' ),
			self::CAPABILITY,
			MANAGE_CART_SIDE_CART_SLUG,
			array( $this, 'render_page' )
		);

		if ( $hook_suffix ) {
			add_action( 'load-' . $hook_suffix, array( $this, 'redirect_to_shared_screen' ) );
		}
	}

	/**
	 * Redirects direct visits to the standalone Side Cart submenu URL to
	 * the shared Manage Cart screen, with the Side Cart tab selected. Runs
	 * on the page-specific `load-{$hook_suffix}` action, before any output
	 * is sent. The destination screen performs its own capability check.
	 *
	 * @return void
	 */
	public function redirect_to_shared_screen() {
		$redirect_url = add_query_arg(
			array(
				'page' => MANAGE_CART_SETTINGS_SLUG,
				'tab'  => 'side-cart',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Submenu page callback. In normal operation this is never reached,
	 * because redirect_to_shared_screen() (hooked to this page's
	 * `load-{$hook_suffix}` action) redirects before rendering starts.
	 * Kept as a safe fallback that performs the same redirect.
	 *
	 * @return void
	 */
	public function render_page() {
		$this->redirect_to_shared_screen();
	}

	/**
	 * Renders the inner content of the Side Cart panel (Phase 3D): title,
	 * subtitle, notices, the "Side Cart" / "Floating Cart" section
	 * navigation, and both sections' content. Used by Admin::render_page()
	 * as part of the single shared Manage Cart screen.
	 *
	 * As of Phase 3D, this panel is split into two sections — "Side Cart"
	 * and "Floating Cart" — each with its own "General" and "Style" tabs,
	 * switched entirely client-side (see assets/js/side-cart-section-tabs.js).
	 * All existing cart panel settings (layout, position, behavior, heading,
	 * and item display toggles) now live under Side Cart → General, with
	 * the same option, field names, and sanitization as before, so every
	 * previously saved value and all existing frontend behavior are
	 * preserved unchanged. Side Cart → Style and both Floating Cart tabs
	 * are placeholders only; no new settings or styling controls are
	 * introduced yet. (Phase 3E-2 later moves the floating trigger drag
	 * toggle out of Side Cart → General and into Floating Cart → General,
	 * where it belongs — see Floating_Cart_Settings.)
	 *
	 * Phase 3E-3: the Side Cart save notice is shown via
	 * Admin::render_option_notices(), scoped to Side_Cart_Settings only, so
	 * a global Settings save is never echoed here (see that method's
	 * docblock). Phase 3E-6: the Floating Cart save notice no longer goes
	 * through the Settings API/render_option_notices() at all — it is
	 * rendered directly inside render_floating_cart_general_tab(). Phase
	 * 3F-3: that rendering is now based on a one-time, current-user
	 * transient set by handle_floating_cart_save() (see
	 * get_floating_cart_saved_transient_key()), not a URL query-arg flag.
	 *
	 * Phase 3F-2: the Side Cart save notice call moves from here into
	 * render_side_cart_general_tab() itself, nested inside that tab's own
	 * conditionally-hidden panel — exactly how the Floating Cart save
	 * notice already works — so it only ever appears in Side Cart →
	 * General, never in Side Cart → Style or the Floating Cart section
	 * (both of which previously showed it too, since this method used to
	 * print it once above both sections regardless of which one was
	 * active).
	 *
	 * Phase 3F-6: the Side Cart save notice no longer goes through the
	 * Settings API/render_option_notices() at all — like the Floating Cart
	 * notice, it is now rendered directly inside
	 * render_side_cart_general_tab(), based on a one-time, current-user
	 * transient set by handle_side_cart_save(). This is what stops the
	 * "Side Cart settings saved." notice from leaking into the global
	 * Settings tab.
	 *
	 * @return void
	 */
	public function render_panel_content() {
		$active_section = $this->get_active_section();
		$active_subtab  = $this->get_active_subtab();

		$side_cart_subtab     = ( 'side-cart' === $active_section ) ? $active_subtab : 'general';
		$floating_cart_subtab = ( 'floating-cart' === $active_section ) ? $active_subtab : 'general';
		?>
		<h1 class="manage-cart-side-cart-title">
			<?php esc_html_e( 'Cart Display', 'manage-cart' ); ?>
		</h1>
		<p class="manage-cart-side-cart-subtitle">
			<?php esc_html_e( 'Configure how your cart display looks and behaves. These settings are saved separately from the global ManageCart settings.', 'manage-cart' ); ?>
		</p>

		<?php if ( isset( $_GET['manage_cart_side_cart_reset'] ) && '1' === $_GET['manage_cart_side_cart_reset'] ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Side Cart settings have been reset to their defaults.', 'manage-cart' ); ?></p>
			</div>
		<?php endif; ?>

		<?php
		// The Side Cart save notice is rendered inside
		// render_side_cart_general_tab() only (Side Cart → General), and
		// the Floating Cart save notice inside
		// render_floating_cart_general_tab() only (Floating Cart →
		// General), so neither ever appears in the other section, the
		// other subtab, or on the Settings tab.
		?>

		<?php $this->render_section_nav( $active_section ); ?>

		<div
			id="manage-cart-section-panel-side-cart"
			class="manage-cart-section-panel"
			role="tabpanel"
			aria-labelledby="manage-cart-section-tab-side-cart"
			<?php echo ( 'side-cart' === $active_section ) ? '' : 'hidden'; ?>
		>
			<?php $this->render_style_nav( 'side-cart', __( 'Side Cart tabs', 'manage-cart' ), $side_cart_subtab ); ?>

			<?php
			/**
			 * Phase 3G-2B: General and Appearance now share one live
			 * preview column so it stays visible (and stays live) no
			 * matter which of the two Side Cart subtabs is open. Only
			 * one of the two subtab panels below is ever visible at a
			 * time (the other carries `hidden`), but both remain in the
			 * DOM, so the fields inside either one can keep updating the
			 * single shared preview via assets/js/side-cart-preview.js.
			 */
			?>
			<div class="manage-cart-side-cart-columns">
				<div class="manage-cart-side-cart-col manage-cart-side-cart-col-controls">
					<?php $this->render_side_cart_general_tab( $side_cart_subtab ); ?>
					<?php $this->render_side_cart_appearance_tab( $side_cart_subtab ); ?>
				</div>
				<div class="manage-cart-side-cart-col manage-cart-side-cart-col-preview">
					<?php $this->render_preview( Side_Cart_Settings::get_settings() ); ?>
				</div>
			</div>
		</div>

		<div
			id="manage-cart-section-panel-floating-cart"
			class="manage-cart-section-panel"
			role="tabpanel"
			aria-labelledby="manage-cart-section-tab-floating-cart"
			<?php echo ( 'floating-cart' === $active_section ) ? '' : 'hidden'; ?>
		>
			<?php $this->render_style_nav( 'floating-cart', __( 'Floating Cart tabs', 'manage-cart' ), $floating_cart_subtab ); ?>

			<?php
			/**
			 * Phase 3G-3B: General and Appearance now share one live
			 * preview column, mirroring the Side Cart section above, so it
			 * stays visible (and stays live) no matter which of the two
			 * Floating Cart subtabs is open. Only one of the two subtab
			 * panels below is ever visible at a time (the other carries
			 * `hidden`), but both remain in the DOM, so the Appearance
			 * tab's fields can keep updating the shared preview via
			 * assets/js/floating-cart-preview.js.
			 */
			?>
			<div class="manage-cart-floating-cart-columns">
				<div class="manage-cart-floating-cart-col manage-cart-floating-cart-col-controls">
					<?php $this->render_floating_cart_general_tab( $floating_cart_subtab ); ?>
					<?php $this->render_floating_cart_appearance_tab( $floating_cart_subtab ); ?>
				</div>
				<div class="manage-cart-floating-cart-col manage-cart-floating-cart-col-preview">
					<?php $this->render_floating_cart_preview( Floating_Cart_Settings::get_settings() ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Determines which top-level section ("Side Cart" or "Floating Cart")
	 * should start open, based on the `section` query var. Phase 3E-6:
	 * this lets the dedicated Floating Cart save handler
	 * (handle_floating_cart_save()) redirect back with `section=floating-cart`
	 * so that tab stays open after saving, instead of the page always
	 * reopening on "Side Cart". This only affects which panel is rendered
	 * visible server-side; it performs no action and requires no nonce.
	 *
	 * @return string Either 'side-cart' or 'floating-cart'.
	 */
	protected function get_active_section() {
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'side-cart'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return ( 'floating-cart' === $section ) ? 'floating-cart' : 'side-cart';
	}

	/**
	 * Determines which sub-tab ("General" or "Style") should start open
	 * within the active section, based on the `subtab` query var. Phase
	 * 3E-6: lets handle_floating_cart_save() redirect back with
	 * `subtab=general` so Floating Cart → General specifically stays open
	 * after saving. This only affects which panel is rendered visible
	 * server-side; it performs no action and requires no nonce.
	 *
	 * @return string Either 'general' or 'style'.
	 */
	protected function get_active_subtab() {
		$subtab = isset( $_GET['subtab'] ) ? sanitize_key( wp_unslash( $_GET['subtab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return ( 'style' === $subtab ) ? 'style' : 'general';
	}

	/**
	 * Renders the top-level "Side Cart" / "Floating Cart" section
	 * navigation. Purely a client-side tablist: activating a tab shows its
	 * matching `.manage-cart-section-panel` and hides the other one, with
	 * no page reload and no query-arg navigation (see
	 * assets/js/side-cart-section-tabs.js).
	 *
	 * @param string $active_section Either 'side-cart' or 'floating-cart';
	 *                                which section starts active (Phase
	 *                                3E-6, see get_active_section()).
	 * @return void
	 */
	protected function render_section_nav( $active_section = 'side-cart' ) {
		$side_cart_active     = ( 'side-cart' === $active_section );
		$floating_cart_active = ( 'floating-cart' === $active_section );
		?>
		<div
			class="manage-cart-section-nav"
			id="manage-cart-section-nav"
			role="tablist"
			aria-label="<?php esc_attr_e( 'Cart type', 'manage-cart' ); ?>"
		>
			<button
				type="button"
				id="manage-cart-section-tab-side-cart"
				class="manage-cart-section-tab<?php echo $side_cart_active ? ' is-active' : ''; ?>"
				role="tab"
				data-manage-cart-section="side-cart"
				aria-selected="<?php echo $side_cart_active ? 'true' : 'false'; ?>"
				aria-controls="manage-cart-section-panel-side-cart"
				tabindex="<?php echo $side_cart_active ? '0' : '-1'; ?>"
			>
				<?php esc_html_e( 'Side Cart', 'manage-cart' ); ?>
			</button>
			<button
				type="button"
				id="manage-cart-section-tab-floating-cart"
				class="manage-cart-section-tab<?php echo $floating_cart_active ? ' is-active' : ''; ?>"
				role="tab"
				data-manage-cart-section="floating-cart"
				aria-selected="<?php echo $floating_cart_active ? 'true' : 'false'; ?>"
				aria-controls="manage-cart-section-panel-floating-cart"
				tabindex="<?php echo $floating_cart_active ? '0' : '-1'; ?>"
			>
				<?php esc_html_e( 'Floating Cart', 'manage-cart' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Renders the "General" / "Appearance" tab navigation shared by both
	 * sections. Scoped to whichever `.manage-cart-section-panel` it is
	 * printed inside; assets/js/side-cart-section-tabs.js wires each
	 * instance up independently, so the Side Cart and Floating Cart
	 * sections each keep their own General/Appearance selection.
	 *
	 * Phase 3G-1 renames this tab's visible label from "Style" to
	 * "Appearance" (the `style` tab key/id/data-attribute values, and
	 * the `subtab=style` URL parameter, are unchanged).
	 *
	 * @param string $section_key    Either 'side-cart' or 'floating-cart'.
	 *                                Used only to build unique element ids;
	 *                                no settings or behavior differ by
	 *                                section.
	 * @param string $aria_label     Accessible label for this tablist.
	 * @param string $active_subtab  Either 'general' or 'style'; which tab
	 *                                starts active within this section
	 *                                (Phase 3E-6, see get_active_subtab()).
	 * @return void
	 */
	protected function render_style_nav( $section_key, $aria_label, $active_subtab = 'general' ) {
		$general_active = ( 'general' === $active_subtab );
		$style_active   = ( 'style' === $active_subtab );
		?>
		<div class="manage-cart-style-nav" role="tablist" aria-label="<?php echo esc_attr( $aria_label ); ?>">
			<button
				type="button"
				id="manage-cart-style-tab-<?php echo esc_attr( $section_key ); ?>-general"
				class="manage-cart-style-tab<?php echo $general_active ? ' is-active' : ''; ?>"
				role="tab"
				data-manage-cart-style-tab="general"
				aria-selected="<?php echo $general_active ? 'true' : 'false'; ?>"
				aria-controls="manage-cart-style-panel-<?php echo esc_attr( $section_key ); ?>-general"
				tabindex="<?php echo $general_active ? '0' : '-1'; ?>"
			>
				<?php esc_html_e( 'General', 'manage-cart' ); ?>
			</button>
			<button
				type="button"
				id="manage-cart-style-tab-<?php echo esc_attr( $section_key ); ?>-style"
				class="manage-cart-style-tab<?php echo $style_active ? ' is-active' : ''; ?>"
				role="tab"
				data-manage-cart-style-tab="style"
				aria-selected="<?php echo $style_active ? 'true' : 'false'; ?>"
				aria-controls="manage-cart-style-panel-<?php echo esc_attr( $section_key ); ?>-style"
				tabindex="<?php echo $style_active ? '0' : '-1'; ?>"
			>
				<?php esc_html_e( 'Appearance', 'manage-cart' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Renders the Side Cart → General tab: the existing settings form
	 * (unchanged option, field names, and sanitization). Every field
	 * keeps its original id/name, so previously saved values, the live
	 * preview script, and all frontend behavior are unaffected by this
	 * move.
	 *
	 * Phase 3G-2B: the live preview card itself is no longer rendered
	 * inside this tab. It moved one level up, to render_panel_content(),
	 * as a single column shared by both the General and Appearance tabs
	 * (see Side_Cart_Settings::APPEARANCE_FIELDS), so the preview stays
	 * visible and keeps updating live no matter which of the two subtabs
	 * a merchant currently has open.
	 *
	 * Phase 3F-6: the form posts to admin-post.php with its own dedicated
	 * `action` (self::SAVE_ACTION) and nonce, handled by
	 * handle_side_cart_save() above — not to options.php/the Settings API.
	 * The "Side Cart settings saved." notice is rendered here, inside this
	 * tab only, based on a one-time, current-user transient set by
	 * handle_side_cart_save() (see get_side_cart_saved_transient_key()), so
	 * it never appears anywhere else — including the Floating Cart section
	 * or the global Settings tab. The transient is deleted immediately
	 * after being read here, so it can only ever be displayed once, on the
	 * single page load immediately following the save.
	 *
	 * Phase 3G-2A: this option's saved transient is now also set when the
	 * Appearance form (below) is saved, so this method only reads and
	 * clears it while this General tab is the one actually being shown
	 * (`'general' === $subtab`) — otherwise a save made from the Appearance
	 * tab would have its notice silently consumed here, in a hidden panel,
	 * before render_side_cart_appearance_tab() ever got a chance to show
	 * it. The hidden self::SAVE_SCOPE_FIELD input below marks this form as
	 * the 'general' submission for handle_side_cart_save().
	 *
	 * @param string $subtab Either 'general' or 'style'; whether this panel
	 *                        starts visible (Phase 3E-6).
	 * @return void
	 */
	protected function render_side_cart_general_tab( $subtab = 'general' ) {
		$saved_transient_key = self::get_side_cart_saved_transient_key();
		$show_saved_notice   = ( 'general' === $subtab ) && (bool) get_transient( $saved_transient_key );

		if ( $show_saved_notice ) {
			delete_transient( $saved_transient_key );
		}
		?>
		<div
			id="manage-cart-style-panel-side-cart-general"
			class="manage-cart-style-panel"
			role="tabpanel"
			aria-labelledby="manage-cart-style-tab-side-cart-general"
			data-manage-cart-style-panel="general"
			<?php echo ( 'general' === $subtab ) ? '' : 'hidden'; ?>
		>
			<?php if ( $show_saved_notice ) : ?>
				<div class="notice notice-success is-dismissible" data-manage-cart-notice="side-cart-saved">
					<p><?php esc_html_e( 'Side Cart settings saved.', 'manage-cart' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="manage-cart-side-cart-card">
				<form
					id="<?php echo esc_attr( self::SETTINGS_FORM_ID ); ?>"
					class="manage-cart-savable-form"
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
					<input type="hidden" name="<?php echo esc_attr( self::SAVE_SCOPE_FIELD ); ?>" value="general" />
					<?php
					wp_nonce_field( self::SAVE_NONCE_ACTION, self::SAVE_NONCE_NAME );
					do_settings_sections( Side_Cart_Settings::PAGE_SLUG );
					?>
					<div class="manage-cart-card-actions">
						<?php submit_button( __( 'Save Changes', 'manage-cart' ), 'primary', 'submit' ); ?>
					</div>
				</form>

				<form
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					class="manage-cart-reset-form"
				>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::RESET_ACTION ); ?>" />
					<?php
					wp_nonce_field( self::RESET_NONCE_ACTION, self::RESET_NONCE_NAME );
					submit_button(
						__( 'Reset Side Cart Settings', 'manage-cart' ),
						'secondary',
						'manage_cart_reset_side_cart_settings_submit',
						false
					);
					?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the Side Cart → Appearance tab (Phase 3G-2A): panel/header
	 * background, header/body text, and checkout button color fields, plus
	 * a panel border radius field — all stored in the same
	 * `manage_cart_side_cart_settings` option as Side Cart → General (see
	 * Side_Cart_Settings::APPEARANCE_FIELDS). This replaces the "Appearance
	 * settings coming next." placeholder previously shown here; the
	 * Floating Cart section's Appearance tab is unaffected and still shows
	 * that placeholder (see render_style_placeholder_tab()).
	 *
	 * This form posts to the same admin-post action and nonce as Side Cart
	 * → General (self::SAVE_ACTION, handled by handle_side_cart_save()),
	 * with its own hidden self::SAVE_SCOPE_FIELD input set to 'appearance'
	 * so the handler knows to treat only these fields as authoritative and
	 * leave every General field exactly as currently saved. Its "Side Cart
	 * settings saved." notice is rendered here, based on the same one-time,
	 * current-user transient used by the General tab, but only read and
	 * cleared while this Appearance tab is the one actually being shown
	 * (`'style' === $subtab`) — see render_side_cart_general_tab() for why.
	 *
	 * Phase 3G-2B: these values are now also read by Cart_Renderer::render_panel()
	 * and applied to the real frontend drawer, popup, and mobile bottom
	 * sheet via CSS custom properties (see assets/css/frontend.css), and
	 * this tab's fields drive the shared live preview column rendered
	 * next to it (see render_panel_content() and render_preview()) via
	 * assets/js/side-cart-preview.js. Sanitization, storage, and the
	 * fields/markup rendered by this method itself are unchanged.
	 *
	 * @param string $subtab Either 'general' or 'style'; whether this panel
	 *                        starts visible (Phase 3E-6).
	 * @return void
	 */
	protected function render_side_cart_appearance_tab( $subtab = 'general' ) {
		$saved_transient_key = self::get_side_cart_saved_transient_key();
		$show_saved_notice   = ( 'style' === $subtab ) && (bool) get_transient( $saved_transient_key );

		if ( $show_saved_notice ) {
			delete_transient( $saved_transient_key );
		}
		?>
		<div
			id="manage-cart-style-panel-side-cart-style"
			class="manage-cart-style-panel"
			role="tabpanel"
			aria-labelledby="manage-cart-style-tab-side-cart-style"
			data-manage-cart-style-panel="style"
			<?php echo ( 'style' === $subtab ) ? '' : 'hidden'; ?>
		>
			<?php if ( $show_saved_notice ) : ?>
				<div class="notice notice-success is-dismissible" data-manage-cart-notice="side-cart-saved">
					<p><?php esc_html_e( 'Side Cart settings saved.', 'manage-cart' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="manage-cart-side-cart-card manage-cart-appearance-card">
				<form
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					class="manage-cart-savable-form"
				>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
					<input type="hidden" name="<?php echo esc_attr( self::SAVE_SCOPE_FIELD ); ?>" value="appearance" />
					<?php
					wp_nonce_field( self::SAVE_NONCE_ACTION, self::SAVE_NONCE_NAME );
					do_settings_sections( Side_Cart_Settings::APPEARANCE_PAGE_SLUG );
					?>
					<div class="manage-cart-card-actions">
						<?php submit_button( __( 'Save Changes', 'manage-cart' ), 'primary', 'submit' ); ?>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the Floating Cart → General tab (Phase 3E-1): the "Enable
	 * Floating Cart", "Position", "Horizontal offset", and "Vertical
	 * offset" fields, saved separately from every other Manage Cart
	 * option via `manage_cart_floating_cart_settings` (see
	 * Floating_Cart_Settings). The frontend floating trigger button reads
	 * these same settings (see Cart_Renderer::render_trigger() and
	 * Frontend::should_load()) to decide whether it renders at all and,
	 * if so, its screen corner and pixel offsets.
	 *
	 * Phase 3E-6: the form posts to admin-post.php with its own dedicated
	 * `action` (self::FLOATING_CART_SAVE_ACTION) and nonce, handled by
	 * handle_floating_cart_save() below — not to options.php/the Settings
	 * API. Phase 3F-3: the "Floating Cart settings saved." notice is
	 * rendered here, inside this tab only, based on a one-time,
	 * current-user transient set by handle_floating_cart_save() (see
	 * get_floating_cart_saved_transient_key()), so it never appears
	 * anywhere else. The transient is deleted immediately after being
	 * read here, so it can only ever be displayed once, on the single
	 * page load immediately following the save — never again from a
	 * later reload, a bookmarked/copied URL, or a return visit to this
	 * tab.
	 *
	 * Phase 3F-4: the notice carries a `data-manage-cart-notice`
	 * attribute so assets/js/app-header-tabs.js and
	 * assets/js/side-cart-section-tabs.js can find and remove it from the
	 * DOM entirely — not merely hide it — the moment the merchant
	 * switches to Settings, to the Side Cart section, or to either
	 * section's Style tab, so it can only ever be seen while Floating
	 * Cart → General is the visibly active tab.
	 *
	 * Phase 3G-3A: this option's saved transient is now also set when the
	 * Appearance form (below) is saved, so this method only reads and
	 * clears it while this General tab is the one actually being shown
	 * (`'general' === $subtab`) — otherwise a save made from the
	 * Appearance tab would have its notice silently consumed here, in a
	 * hidden panel, before render_floating_cart_appearance_tab() ever got
	 * a chance to show it. The hidden self::FLOATING_CART_SAVE_SCOPE_FIELD
	 * input below marks this form as the 'general' submission for
	 * handle_floating_cart_save(). Mirrors render_side_cart_general_tab().
	 *
	 * @param string $subtab Either 'general' or 'style'; whether this panel
	 *                        starts visible (Phase 3E-6).
	 * @return void
	 */
	protected function render_floating_cart_general_tab( $subtab = 'general' ) {
		$saved_transient_key = self::get_floating_cart_saved_transient_key();
		$show_saved_notice   = ( 'general' === $subtab ) && (bool) get_transient( $saved_transient_key );

		if ( $show_saved_notice ) {
			delete_transient( $saved_transient_key );
		}
		?>
		<div
			id="manage-cart-style-panel-floating-cart-general"
			class="manage-cart-style-panel"
			role="tabpanel"
			aria-labelledby="manage-cart-style-tab-floating-cart-general"
			data-manage-cart-style-panel="general"
			<?php echo ( 'general' === $subtab ) ? '' : 'hidden'; ?>
		>
			<?php if ( $show_saved_notice ) : ?>
				<div class="notice notice-success is-dismissible" data-manage-cart-notice="floating-cart-saved">
					<p><?php esc_html_e( 'Floating Cart settings saved.', 'manage-cart' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="manage-cart-side-cart-card manage-cart-floating-cart-card">
				<form
					id="<?php echo esc_attr( self::FLOATING_CART_SETTINGS_FORM_ID ); ?>"
					class="manage-cart-savable-form"
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::FLOATING_CART_SAVE_ACTION ); ?>" />
					<input type="hidden" name="<?php echo esc_attr( self::FLOATING_CART_SAVE_SCOPE_FIELD ); ?>" value="general" />
					<?php
					wp_nonce_field( self::FLOATING_CART_SAVE_NONCE_ACTION, self::FLOATING_CART_SAVE_NONCE_NAME );
					do_settings_sections( Floating_Cart_Settings::PAGE_SLUG );
					?>
					<div class="manage-cart-card-actions">
						<?php submit_button( __( 'Save Changes', 'manage-cart' ), 'primary', 'submit' ); ?>
					</div>
				</form>

				<form
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					class="manage-cart-reset-form"
				>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::FLOATING_CART_RESET_ACTION ); ?>" />
					<?php
					wp_nonce_field( self::FLOATING_CART_RESET_NONCE_ACTION, self::FLOATING_CART_RESET_NONCE_NAME );
					submit_button(
						__( 'Reset Floating Cart Settings', 'manage-cart' ),
						'secondary',
						'manage_cart_reset_floating_cart_settings_submit',
						false
					);
					?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the Floating Cart → Appearance tab (Phase 3G-3A): button
	 * background color, cart icon color, count badge background/text
	 * colors, button size, and button border radius fields — all stored
	 * in the same `manage_cart_floating_cart_settings` option as Floating
	 * Cart → General (see Floating_Cart_Settings::APPEARANCE_FIELDS).
	 * This replaces the "Appearance settings coming next." placeholder
	 * previously shown here, mirroring how Side Cart → Appearance
	 * replaced its own placeholder in Phase 3G-2A.
	 *
	 * This form posts to the same admin-post action and nonce as Floating
	 * Cart → General (self::FLOATING_CART_SAVE_ACTION, handled by
	 * handle_floating_cart_save()), with its own hidden
	 * self::FLOATING_CART_SAVE_SCOPE_FIELD input set to 'appearance' so
	 * the handler knows to treat only these fields as authoritative and
	 * leave every General field exactly as currently saved. Its "Floating
	 * Cart settings saved." notice is rendered here, based on the same
	 * one-time, current-user transient used by the General tab, but only
	 * read and cleared while this Appearance tab is the one actually
	 * being shown (`'style' === $subtab`) — see
	 * render_floating_cart_general_tab() for why.
	 *
	 * These values are not yet read anywhere on the frontend; the
	 * floating trigger button keeps rendering with its existing fixed
	 * styling until a later phase applies them.
	 *
	 * @param string $subtab Either 'general' or 'style'; whether this panel
	 *                        starts visible (Phase 3E-6).
	 * @return void
	 */
	protected function render_floating_cart_appearance_tab( $subtab = 'general' ) {
		$saved_transient_key = self::get_floating_cart_saved_transient_key();
		$show_saved_notice   = ( 'style' === $subtab ) && (bool) get_transient( $saved_transient_key );

		if ( $show_saved_notice ) {
			delete_transient( $saved_transient_key );
		}
		?>
		<div
			id="manage-cart-style-panel-floating-cart-style"
			class="manage-cart-style-panel"
			role="tabpanel"
			aria-labelledby="manage-cart-style-tab-floating-cart-style"
			data-manage-cart-style-panel="style"
			<?php echo ( 'style' === $subtab ) ? '' : 'hidden'; ?>
		>
			<?php if ( $show_saved_notice ) : ?>
				<div class="notice notice-success is-dismissible" data-manage-cart-notice="floating-cart-saved">
					<p><?php esc_html_e( 'Floating Cart settings saved.', 'manage-cart' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="manage-cart-side-cart-card manage-cart-floating-cart-card manage-cart-appearance-card">
				<form
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					class="manage-cart-savable-form"
				>
					<input type="hidden" name="action" value="<?php echo esc_attr( self::FLOATING_CART_SAVE_ACTION ); ?>" />
					<input type="hidden" name="<?php echo esc_attr( self::FLOATING_CART_SAVE_SCOPE_FIELD ); ?>" value="appearance" />
					<?php
					wp_nonce_field( self::FLOATING_CART_SAVE_NONCE_ACTION, self::FLOATING_CART_SAVE_NONCE_NAME );
					do_settings_sections( Floating_Cart_Settings::APPEARANCE_PAGE_SLUG );
					?>
					<div class="manage-cart-card-actions">
						<?php submit_button( __( 'Save Changes', 'manage-cart' ), 'primary', 'submit' ); ?>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Prepares up to PREVIEW_PRODUCTS_MAX real WooCommerce products for
	 * the two static sample line items in the Live Preview card.
	 *
	 * This is admin-preview illustration only:
	 *
	 * - It never reads the cart, session, an order, or any customer
	 *   data — only public catalog data (name, image, formatted price)
	 *   already visible on the storefront.
	 * - It writes nothing and never affects the real, customer-facing
	 *   Side Cart, which is rendered entirely by Cart_Renderer from the
	 *   actual cart contents.
	 * - It uses WooCommerce's own CRUD API only — `wc_get_products()`
	 *   plus `WC_Product` accessors. No `$wpdb` call, no raw SQL, no
	 *   direct post/meta table access.
	 * - It is only ever called from render_preview(), which itself only
	 *   runs on this plugin's own admin screen behind the
	 *   `manage_woocommerce` capability check already applied in
	 *   render_panel_content().
	 *
	 * Fewer than PREVIEW_PRODUCTS_MAX eligible products (including zero,
	 * or WooCommerce being inactive) is normal on a fresh install; any
	 * remaining slot is filled with a translated fallback sample so the
	 * preview is never left short. Each entry always carries a usable
	 * `image_url` — a real product thumbnail when available, otherwise
	 * WooCommerce's own local placeholder image (never a remote URL).
	 *
	 * Public (not just used internally by render_preview()) so
	 * Assets::enqueue_side_cart_preview_script() can localize the same
	 * prepared data into the Live Preview script's config object.
	 *
	 * @return array List of exactly PREVIEW_PRODUCTS_MAX entries, each
	 *               with `name`, `price_html`, `price`, `image_url`,
	 *               `image_alt`, and `is_sample`.
	 */
	public static function get_preview_products() {
		$prepared = array();

		if ( function_exists( 'wc_get_products' ) ) {
			$products = wc_get_products(
				array(
					'status'     => 'publish',
					'visibility' => 'visible',
					'limit'      => self::PREVIEW_PRODUCTS_MAX,
					'orderby'    => 'date',
					'order'      => 'DESC',
					'return'     => 'objects',
				)
			);

			if ( is_array( $products ) ) {
				foreach ( $products as $product ) {
					if ( ! $product instanceof \WC_Product ) {
						continue;
					}

					// Belt-and-braces: wc_get_products() already filters
					// on status and catalog visibility, but a filter on
					// that query could widen it, and a hidden or
					// unpublished product must never appear here.
					if ( 'publish' !== $product->get_status() || ! $product->is_visible() ) {
						continue;
					}

					$prepared[] = self::prepare_preview_product( $product );

					if ( count( $prepared ) >= self::PREVIEW_PRODUCTS_MAX ) {
						break;
					}
				}
			}
		}

		$missing = self::PREVIEW_PRODUCTS_MAX - count( $prepared );

		if ( $missing > 0 ) {
			$fallbacks = self::get_fallback_preview_products();
			$prepared  = array_merge( $prepared, array_slice( $fallbacks, 0, $missing ) );
		}

		return array_values( array_slice( $prepared, 0, self::PREVIEW_PRODUCTS_MAX ) );
	}

	/**
	 * Shapes one real WooCommerce product into a Live Preview entry.
	 *
	 * Uses public WooCommerce/WordPress APIs only: `get_name()`,
	 * `get_price_html()`, `get_image_id()`, `wp_get_attachment_image_url()`,
	 * the attachment alt-text API, and `wc_placeholder_img_src()` for
	 * WooCommerce's own local placeholder when the product has no image.
	 * Only data already public on the storefront is included — no private
	 * meta, no cost/stock internals, no customer or order data.
	 *
	 * @param \WC_Product $product The product to shape.
	 * @return array
	 */
	protected static function prepare_preview_product( $product ) {
		$name       = (string) $product->get_name();
		$price_html = (string) $product->get_price_html();

		$image_id  = $product->get_image_id();
		$image_url = '';
		$image_alt = '';

		if ( $image_id ) {
			$resolved = wp_get_attachment_image_url( (int) $image_id, self::PREVIEW_IMAGE_SIZE );

			if ( is_string( $resolved ) && '' !== $resolved ) {
				$image_url = $resolved;
				$image_alt = self::get_attachment_alt( (int) $image_id );
			}
		}

		if ( '' === $image_url && function_exists( 'wc_placeholder_img_src' ) ) {
			$image_url = (string) wc_placeholder_img_src( self::PREVIEW_IMAGE_SIZE );
		}

		if ( '' === $image_alt ) {
			$image_alt = $name;
		}

		return array(
			'name'       => sanitize_text_field( $name ),
			'price_html' => wp_kses_post( $price_html ),
			'price'      => wp_strip_all_tags( $price_html ),
			'image_url'  => esc_url_raw( $image_url ),
			'image_alt'  => sanitize_text_field( $image_alt ),
			'is_sample'  => false,
		);
	}

	/**
	 * Reads an attachment's alt text through WordPress's own API where it
	 * exists (`wp_get_attachment_image_alt()` is not present on every
	 * supported version), falling back to the documented attachment meta
	 * key. Either way this is a WordPress API call, not a table query.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return string
	 */
	protected static function get_attachment_alt( $attachment_id ) {
		if ( function_exists( 'wp_get_attachment_image_alt' ) ) {
			$alt = wp_get_attachment_image_alt( $attachment_id );

			if ( is_string( $alt ) ) {
				return $alt;
			}
		}

		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return is_string( $alt ) ? $alt : '';
	}

	/**
	 * Translated fallback sample cards, used only to fill Live Preview
	 * slots a real product could not fill (a store with fewer than two
	 * eligible products, or WooCommerce unavailable). This preserves the
	 * previous "Sample Product A" / "Sample Product B" illustration as a
	 * graceful fallback rather than removing it outright.
	 *
	 * The image is WooCommerce's own local placeholder — never a remote
	 * URL, an external CDN asset, or an uploaded image.
	 *
	 * @return array
	 */
	protected static function get_fallback_preview_products() {
		$placeholder = function_exists( 'wc_placeholder_img_src' )
			? (string) wc_placeholder_img_src( self::PREVIEW_IMAGE_SIZE )
			: '';

		$samples = array(
			array(
				'name'  => __( 'Sample Product A', 'manage-cart' ),
				'price' => __( '$19.00', 'manage-cart' ),
			),
			array(
				'name'  => __( 'Sample Product B', 'manage-cart' ),
				'price' => __( '$24.50', 'manage-cart' ),
			),
		);

		$prepared = array();

		foreach ( $samples as $sample ) {
			$prepared[] = array(
				'name'       => sanitize_text_field( $sample['name'] ),
				'price_html' => esc_html( $sample['price'] ),
				'price'      => sanitize_text_field( $sample['price'] ),
				'image_url'  => esc_url_raw( $placeholder ),
				'image_alt'  => sanitize_text_field(
					__( 'Sample product placeholder image', 'manage-cart' )
				),
				'is_sample'  => true,
			);
		}

		return $prepared;
	}

	/**
	 * Renders a visual preview card that reflects the currently saved Side
	 * Cart settings on page load. This markup is admin UI only, built from
	 * static mock data (Phase-current: plus up to two real WooCommerce
	 * products prepared by get_preview_products(), see above): it never
	 * loads real WooCommerce cart data, never uses the Store API, and
	 * never enqueues any frontend cart assets.
	 *
	 * As of Phase 2C, a small local script (loaded only on the shared
	 * Manage Cart screen, see Assets::register_admin_assets()) listens for
	 * changes to the fields below it and updates this same markup
	 * instantly, before the form is saved. The ids on the elements below
	 * are the hooks that script reads and writes; the initial values
	 * rendered here are also the fallback shown if JavaScript is
	 * unavailable. Because the Side Cart panel stays in the DOM (only
	 * `hidden`) when the Settings tab is active, this live state survives
	 * switching tabs and back. Phase 3G-2B: the preview card is now
	 * rendered once, shared by both the General and Appearance subtabs
	 * (see render_panel_content()), so it stays visible and keeps
	 * updating live regardless of which of the two is currently open;
	 * assets/js/side-cart-preview.js now also listens to the Appearance
	 * tab's color and border radius fields and writes them onto the
	 * preview panel as the same `--manage-cart-*` CSS custom properties
	 * Cart_Renderer::render_panel() writes on the real frontend panel, so
	 * the mock preview mirrors the real Side Cart's appearance settings
	 * without duplicating any of its underlying styling logic.
	 *
	 * Cart layout (Drawer vs Popup): the stage wrapping the panel also
	 * gets a `manage-cart-preview-stage--{layout}` modifier class here,
	 * alongside the panel's own `manage-cart-preview-panel--{layout}`
	 * class, so assets/css/side-cart-admin.css can style the two layouts
	 * distinctly — Drawer hugging one edge of the stage at full height,
	 * Popup centered over its own dark backdrop with internal scrolling
	 * — mirroring the real, customer-facing distinction between
	 * `.manage-cart-panel--drawer` and `.manage-cart-panel--popup` in
	 * assets/css/frontend.css. assets/js/side-cart-preview.js applies the
	 * same two classes to both elements on every later update, so
	 * switching "Cart layout" swaps between them instantly, before Save.
	 *
	 * Phase 3I-1: a small "Preview state" switch (Cart items / Empty
	 * cart) now sits above the stage, letting the admin flip this same
	 * preview between its normal mock cart-items content and a clean
	 * empty-cart view — assets/js/preview-state-toggle.js. It is purely
	 * a local, unsaved display toggle (nothing is written to any option,
	 * and the real storefront cart is never touched); it works by adding
	 * or removing a single `manage-cart-preview-card--empty-state`
	 * modifier class on the outer `#manage-cart-preview-card` element
	 * (never on `#manage-cart-preview-panel` or `#manage-cart-preview-
	 * stage`, both of which have their own `className` fully rewritten
	 * on every field-driven update pass above and by
	 * assets/js/side-cart-preview.js — anchoring the modifier there
	 * instead means a settings-field edit can never silently drop back
	 * out of the Empty cart preview state). assets/css/side-cart-
	 * admin.css uses that modifier, scoped by the card's id for
	 * specificity, to hide the items list, footer/checkout, and item-
	 * count badge below and reveal `#manage-cart-preview-empty` in their
	 * place — and, the same way, to hide any Pro Custom Cart Note /
	 * Cart Recommendations preview element without ever touching that
	 * element's own `hidden` attribute or any state read by
	 * cart-note-preview.js / cart-recommendations-preview.js. Because
	 * only a purely visual CSS override is ever applied, switching back
	 * to "Cart items" always restores exactly what was there before,
	 * with nothing extra to reconcile, and repeated switching can never
	 * duplicate anything either script rendered.
	 *
	 * @param array $settings Current Side Cart settings, as returned by
	 *                        Side_Cart_Settings::get_settings().
	 * @return void
	 */
	protected function render_preview( array $settings ) {
		$layout_choices      = Side_Cart_Settings::get_layout_choices();
		$drawer_side_choices = Side_Cart_Settings::get_drawer_side_choices();

		$layout_label      = isset( $layout_choices[ $settings['layout'] ] ) ? $layout_choices[ $settings['layout'] ] : $settings['layout'];
		$drawer_side_label = isset( $drawer_side_choices[ $settings['drawer_side'] ] ) ? $drawer_side_choices[ $settings['drawer_side'] ] : $settings['drawer_side'];

		$preview_classes   = array( 'manage-cart-preview-panel' );
		$preview_classes[] = 'manage-cart-preview-panel--' . sanitize_html_class( $settings['layout'] );
		if ( 'drawer' === $settings['layout'] ) {
			$preview_classes[] = 'manage-cart-preview-panel--' . sanitize_html_class( $settings['drawer_side'] );
		}

		// The stage (the dark backdrop area the panel sits inside) also
		// gets a layout modifier class, so Drawer and Popup can look
		// genuinely different — Drawer hugs one edge and stretches full
		// height, Popup is centered over its own dark backdrop — without
		// either layout affecting the other's CSS.
		$stage_classes   = array( 'manage-cart-preview-stage' );
		$stage_classes[] = 'manage-cart-preview-stage--' . sanitize_html_class( $settings['layout'] );

		$preview_appearance_style = Cart_Renderer::get_appearance_style_string( $settings );

		// Up to two real, published, catalog-visible WooCommerce products
		// (falling back to translated sample entries when the store has
		// none) for the two static line items below. Admin preview only —
		// see get_preview_products() for the guarantees this makes.
		$preview_products = self::get_preview_products();
		$preview_product_1 = isset( $preview_products[0] ) ? $preview_products[0] : array();
		$preview_product_2 = isset( $preview_products[1] ) ? $preview_products[1] : array();
		?>
		<div class="manage-cart-preview-card" id="manage-cart-preview-card" aria-hidden="true">
			<div class="manage-cart-preview-card-label">
				<span><?php esc_html_e( 'Live preview', 'manage-cart' ); ?></span>
				<span class="manage-cart-preview-card-badge manage-cart-preview-card-badge--unsaved" id="manage-cart-preview-unsaved-badge" hidden>
					<?php esc_html_e( 'Unsaved', 'manage-cart' ); ?>
				</span>
			</div>
			<p class="manage-cart-preview-note">
				<?php esc_html_e( 'Updates instantly as you edit the settings on the left. Nothing here is saved until you click Save Changes.', 'manage-cart' ); ?>
			</p>

			<?php
			/**
			 * Preview-only "Preview state" switch (Phase 3I-1). Flips the
			 * mock panel below between its normal "Cart items" content and
			 * a clean "Empty cart" view, purely client-side — see
			 * assets/js/preview-state-toggle.js. Nothing here is saved,
			 * and it never affects the real storefront cart. Defaults to
			 * "Cart items" ("is-active"/aria-pressed="true" below), which
			 * is also the state shown if JavaScript is unavailable, since
			 * the buttons are otherwise inert and the panel already
			 * starts in this state.
			 */
			?>
			<div class="manage-cart-preview-state-switch" id="manage-cart-preview-state-switch">
				<span class="manage-cart-preview-state-switch-label"><?php esc_html_e( 'Preview state', 'manage-cart' ); ?></span>
				<div class="manage-cart-preview-state-switch-options" role="group" aria-label="<?php esc_attr_e( 'Preview state', 'manage-cart' ); ?>">
					<button
						type="button"
						class="manage-cart-preview-state-btn is-active"
						id="manage-cart-preview-state-btn-items"
						data-preview-state="items"
						aria-pressed="true"
					>
						<?php esc_html_e( 'Cart items', 'manage-cart' ); ?>
					</button>
					<button
						type="button"
						class="manage-cart-preview-state-btn"
						id="manage-cart-preview-state-btn-empty"
						data-preview-state="empty"
						aria-pressed="false"
					>
						<?php esc_html_e( 'Empty cart', 'manage-cart' ); ?>
					</button>
				</div>
			</div>

			<div class="<?php echo esc_attr( implode( ' ', $stage_classes ) ); ?>" id="manage-cart-preview-stage">
				<div
					class="<?php echo esc_attr( implode( ' ', $preview_classes ) ); ?>"
					id="manage-cart-preview-panel"
					data-layout="<?php echo esc_attr( $settings['layout'] ); ?>"
					data-drawer-side="<?php echo esc_attr( $settings['drawer_side'] ); ?>"
					style="--manage-cart-preview-width: <?php echo esc_attr( (int) $settings['desktop_width'] ); ?>px; <?php echo esc_attr( $preview_appearance_style ); ?>"
				>
					<div class="manage-cart-preview-heading">
						<span class="manage-cart-preview-heading-left">
							<span class="manage-cart-preview-icon" id="manage-cart-preview-icon" aria-hidden="true" <?php echo $settings['show_cart_icon'] ? '' : 'hidden'; ?>>&#128722;</span>
							<span class="manage-cart-preview-heading-text" id="manage-cart-preview-heading-text">
								<?php echo esc_html( $settings['heading'] ); ?>
							</span>
						</span>
						<span class="manage-cart-preview-count" id="manage-cart-preview-count" <?php echo $settings['show_item_count'] ? '' : 'hidden'; ?>>3</span>
					</div>

					<div class="manage-cart-preview-items" id="manage-cart-preview-items">
						<div class="manage-cart-preview-item">
							<span
								class="manage-cart-preview-thumb manage-cart-preview-item-image"
								<?php echo ! empty( $preview_product_1['image_url'] ) ? 'style="background-image:url(' . esc_url( $preview_product_1['image_url'] ) . ');"' : ''; ?>
								<?php echo ! empty( $preview_product_1['image_alt'] ) ? 'title="' . esc_attr( $preview_product_1['image_alt'] ) . '"' : ''; ?>
								<?php echo $settings['show_product_image'] ? '' : 'hidden'; ?>
							></span>
							<span class="manage-cart-preview-item-text">
								<span class="manage-cart-preview-item-title"><?php echo esc_html( isset( $preview_product_1['name'] ) ? $preview_product_1['name'] : __( 'Sample Product A', 'manage-cart' ) ); ?></span>
								<span class="manage-cart-preview-item-meta"><?php esc_html_e( 'Qty 1', 'manage-cart' ); ?></span>
								<?php if ( ! empty( $preview_product_1['price_html'] ) ) : ?>
									<span class="manage-cart-preview-item-price"><?php echo wp_kses_post( $preview_product_1['price_html'] ); ?></span>
								<?php endif; ?>
								<span class="manage-cart-preview-item-variation" <?php echo $settings['show_variation_attributes'] ? '' : 'hidden'; ?>>
									<?php esc_html_e( 'Color: Purple · Size: M', 'manage-cart' ); ?>
								</span>
							</span>
							<span class="manage-cart-preview-item-controls">
								<span class="manage-cart-preview-item-qty" <?php echo $settings['show_quantity_controls'] ? '' : 'hidden'; ?>>
									<button type="button" class="manage-cart-preview-qty-btn" tabindex="-1" disabled="disabled">&minus;</button>
									<span class="manage-cart-preview-qty-value">1</span>
									<button type="button" class="manage-cart-preview-qty-btn" tabindex="-1" disabled="disabled">&#43;</button>
								</span>
								<button type="button" class="manage-cart-preview-item-remove" tabindex="-1" disabled="disabled" <?php echo $settings['show_remove_button'] ? '' : 'hidden'; ?>>
									<span aria-hidden="true">&times;</span>
								</button>
							</span>
						</div>
						<div class="manage-cart-preview-item">
							<span
								class="manage-cart-preview-thumb manage-cart-preview-item-image"
								<?php echo ! empty( $preview_product_2['image_url'] ) ? 'style="background-image:url(' . esc_url( $preview_product_2['image_url'] ) . ');"' : ''; ?>
								<?php echo ! empty( $preview_product_2['image_alt'] ) ? 'title="' . esc_attr( $preview_product_2['image_alt'] ) . '"' : ''; ?>
								<?php echo $settings['show_product_image'] ? '' : 'hidden'; ?>
							></span>
							<span class="manage-cart-preview-item-text">
								<span class="manage-cart-preview-item-title"><?php echo esc_html( isset( $preview_product_2['name'] ) ? $preview_product_2['name'] : __( 'Sample Product B', 'manage-cart' ) ); ?></span>
								<span class="manage-cart-preview-item-meta"><?php esc_html_e( 'Qty 2', 'manage-cart' ); ?></span>
								<?php if ( ! empty( $preview_product_2['price_html'] ) ) : ?>
									<span class="manage-cart-preview-item-price"><?php echo wp_kses_post( $preview_product_2['price_html'] ); ?></span>
								<?php endif; ?>
								<span class="manage-cart-preview-item-variation" <?php echo $settings['show_variation_attributes'] ? '' : 'hidden'; ?>>
									<?php esc_html_e( 'Color: Black · Size: L', 'manage-cart' ); ?>
								</span>
							</span>
							<span class="manage-cart-preview-item-controls">
								<span class="manage-cart-preview-item-qty" <?php echo $settings['show_quantity_controls'] ? '' : 'hidden'; ?>>
									<button type="button" class="manage-cart-preview-qty-btn" tabindex="-1" disabled="disabled">&minus;</button>
									<span class="manage-cart-preview-qty-value">2</span>
									<button type="button" class="manage-cart-preview-qty-btn" tabindex="-1" disabled="disabled">&#43;</button>
								</span>
								<button type="button" class="manage-cart-preview-item-remove" tabindex="-1" disabled="disabled" <?php echo $settings['show_remove_button'] ? '' : 'hidden'; ?>>
									<span aria-hidden="true">&times;</span>
								</button>
							</span>
						</div>
					</div>

					<?php
					/**
					 * Empty-cart preview view (Phase 3I-1). Hidden by default
					 * (shown only while the "Preview state" switch above is set
					 * to "Empty cart" — see assets/css/side-cart-admin.css and
					 * assets/js/preview-state-toggle.js); a clean, static
					 * mirror of ManageCart's real default empty-cart state (see
					 * Cart_Renderer::render_empty_state()), not the merchant's
					 * Custom Empty Cart Content settings — that wiring is a
					 * later part of this feature. Purely illustrative admin
					 * markup: the button below is disabled and not a real link,
					 * exactly like the mock Checkout button above.
					 */
					?>
					<div class="manage-cart-preview-empty" id="manage-cart-preview-empty" aria-hidden="true">
						<span class="manage-cart-preview-empty-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="36" height="36">
								<path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="<?php echo esc_attr( Cart_Renderer::BASKET_ICON_PATH_D ); ?>"/>
							</svg>
						</span>
						<p class="manage-cart-preview-empty-title"><?php esc_html_e( 'Your cart is empty', 'manage-cart' ); ?></p>
						<p class="manage-cart-preview-empty-text"><?php esc_html_e( 'Looks like you haven\'t added anything yet.', 'manage-cart' ); ?></p>
						<button type="button" class="manage-cart-preview-empty-button" tabindex="-1" disabled="disabled">
							<?php esc_html_e( 'Continue Shopping', 'manage-cart' ); ?>
						</button>
					</div>

					<div class="manage-cart-preview-footer" id="manage-cart-preview-footer">
						<button type="button" class="manage-cart-preview-checkout" tabindex="-1" disabled="disabled">
							<?php esc_html_e( 'Checkout', 'manage-cart' ); ?>
						</button>
					</div>
				</div>
			</div>

			<ul class="manage-cart-preview-summary" id="manage-cart-preview-summary">
				<li id="manage-cart-preview-summary-layout">
					<?php
					printf(
						/* translators: %s: selected cart layout label (Drawer or Popup). */
						esc_html__( 'Layout: %s', 'manage-cart' ),
						esc_html( $layout_label )
					);
					?>
				</li>
				<li id="manage-cart-preview-summary-position" <?php echo ( 'drawer' === $settings['layout'] ) ? '' : 'hidden'; ?>>
					<?php
					printf(
						/* translators: %s: selected drawer position label (Right or Left). */
						esc_html__( 'Position: %s', 'manage-cart' ),
						esc_html( $drawer_side_label )
					);
					?>
				</li>
				<li id="manage-cart-preview-summary-width">
					<?php
					printf(
						/* translators: %d: desktop cart panel width in pixels. */
						esc_html__( 'Desktop width: %d px', 'manage-cart' ),
						(int) $settings['desktop_width']
					);
					?>
				</li>
				<li><?php esc_html_e( 'Mobile: Bottom sheet', 'manage-cart' ); ?></li>
				<li id="manage-cart-preview-summary-autoopen">
					<?php
					echo $settings['auto_open_on_add']
						? esc_html__( 'Auto-open on add to cart: On', 'manage-cart' )
						: esc_html__( 'Auto-open on add to cart: Off', 'manage-cart' );
					?>
				</li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Renders a visual preview card that reflects the currently saved
	 * Floating Cart → Appearance settings on page load (Phase 3G-3B),
	 * mirroring render_preview() above for the Side Cart section. This
	 * markup is admin UI only, built from static mock data (a fixed "3"
	 * item count): it never loads WooCommerce cart data and never
	 * enqueues any frontend cart assets.
	 *
	 * A small local script (assets/js/floating-cart-preview.js, loaded
	 * only on the shared Manage Cart screen) listens for changes to the
	 * Floating Cart → Appearance color, button size, and border radius
	 * fields and updates this same markup instantly, before the form is
	 * saved, by writing the same `--manage-cart-trigger-*` CSS custom
	 * properties Cart_Renderer::get_trigger_appearance_style_string()
	 * writes on the real frontend button — so the mock preview mirrors the
	 * real floating trigger button's appearance without duplicating any of
	 * its underlying styling logic. The initial values rendered here are
	 * also the fallback shown if JavaScript is unavailable.
	 *
	 * This card is shared by both the General and Appearance subtabs (see
	 * render_panel_content()), so it stays visible and keeps updating live
	 * regardless of which of the two is currently open — mirroring the
	 * Side Cart preview card's own Phase 3G-2B behavior.
	 *
	 * Phase 3G-4: the preview stage now renders as a small mock browser
	 * viewport — a title bar with three window dots and an address-bar
	 * pill, above a gridded "page" area — and the mock trigger button is
	 * placed inside that page area at its saved Position (one of the
	 * four screen corners), offset from the corner using the saved
	 * Horizontal/Vertical offset values, the same way
	 * Cart_Renderer::render_trigger() positions the real frontend
	 * button. Because the mock page area is much smaller than a real
	 * screen, the offsets are visually capped in CSS (see
	 * .manage-cart-floating-cart-preview-trigger--* in
	 * assets/css/side-cart-admin.css) so the button always stays inside
	 * the visible viewport instead of being pushed off it; the
	 * underlying custom property still carries the real saved pixel
	 * value.
	 *
	 * Position, the two offsets, and "Allow customers to move floating
	 * cart button" are now also read live by
	 * assets/js/floating-cart-preview.js (alongside the Appearance
	 * fields it already updated instantly): changing Position or either
	 * offset field snaps the preview button to the new corner/offset
	 * immediately, and the mock button itself becomes mouse/touch
	 * draggable within this viewport whenever "Allow customers to move
	 * floating cart button" is Yes, purely as a local, unsaved preview
	 * interaction (no position is written to any option, transient, or
	 * browser storage — see that script's own docblock). This is why the
	 * button below no longer carries `disabled="disabled"`: a disabled
	 * button never receives pointer/mouse events in any browser, which
	 * previously made it impossible to drag regardless of any script
	 * logic. `tabindex="-1"` is kept (and the whole card stays
	 * `aria-hidden="true"`), so this remains excluded from the keyboard
	 * tab order and assistive tech exactly as before; only its ability
	 * to receive pointer events changes. No settings, sanitization,
	 * defaults, saved values, or frontend cart markup/behavior are
	 * touched.
	 *
	 * Phase 3H-10: the button now also carries a server-rendered `hidden`
	 * attribute whenever the saved "Enabled" setting is off, and the count
	 * badge span carries its own `hidden` attribute whenever the saved
	 * "Show item count badge" setting is off — so a fresh page load (before
	 * assets/js/floating-cart-preview.js has run, or with JavaScript
	 * disabled) already reflects both saved values, the same way Position/
	 * offsets and the Appearance fields already did. That script keeps both
	 * in sync instantly afterward as the General tab's "Enable Floating
	 * Cart" and "Show item count badge" checkboxes are toggled, before Save
	 * Changes. Nothing about the two settings' own storage, sanitization,
	 * or frontend rendering (Cart_Renderer::render_trigger()) is touched.
	 *
	 * Phase 3H-11: a small explanatory status line is now rendered below
	 * the mock browser stage, reflecting the saved "Hide floating cart
	 * when cart is empty" setting — the mock preview cart always has
	 * (illustrative) items in it, so that setting never actually hides
	 * the mock button itself; the status line is the only preview
	 * behavior for it. Two translated `<span>`s are rendered, one for
	 * each state, and only one is ever unhidden (server-side here, and
	 * live by assets/js/floating-cart-preview.js when the checkbox
	 * changes) — this avoids needing to duplicate translated strings in
	 * JavaScript. This introduces no new saved setting: it purely
	 * explains the existing `hide_when_empty` value, which continues to
	 * be read only by Cart_Renderer::render_trigger() for the real,
	 * customer-facing button.
	 *
	 * @param array $settings Current Floating Cart settings, as returned
	 *                         by Floating_Cart_Settings::get_settings().
	 * @return void
	 */
	protected function render_floating_cart_preview( array $settings ) {
		$position = in_array( $settings['position'], Floating_Cart_Settings::POSITION_CHOICES, true )
			? $settings['position']
			: 'bottom-right';

		$enabled    = ! empty( $settings['enabled'] );
		$show_badge = ! empty( $settings['show_item_count_badge'] );
		$hide_when_empty = ! empty( $settings['hide_when_empty'] );

		$offset_style = sprintf(
			'--manage-cart-trigger-h-offset: %1$dpx; --manage-cart-trigger-v-offset: %2$dpx;',
			(int) $settings['horizontal_offset'],
			(int) $settings['vertical_offset']
		);

		$preview_style = $offset_style . ' ' . Cart_Renderer::get_trigger_appearance_style_string( $settings );
		?>
		<div class="manage-cart-preview-card" id="manage-cart-floating-cart-preview-card" aria-hidden="true">
			<div class="manage-cart-preview-card-label">
				<span><?php esc_html_e( 'Live preview', 'manage-cart' ); ?></span>
				<span class="manage-cart-preview-card-badge manage-cart-preview-card-badge--unsaved" id="manage-cart-floating-cart-preview-unsaved-badge" hidden>
					<?php esc_html_e( 'Unsaved', 'manage-cart' ); ?>
				</span>
			</div>
			<p class="manage-cart-preview-note">
				<?php esc_html_e( 'Updates instantly as you edit the Appearance settings on the left. Nothing here is saved until you click Save Changes.', 'manage-cart' ); ?>
			</p>

			<div class="manage-cart-preview-stage manage-cart-floating-cart-preview-stage">
				<div class="manage-cart-floating-cart-preview-browser">
					<div class="manage-cart-floating-cart-preview-browser-bar">
						<span class="manage-cart-floating-cart-preview-browser-dot"></span>
						<span class="manage-cart-floating-cart-preview-browser-dot"></span>
						<span class="manage-cart-floating-cart-preview-browser-dot"></span>
						<span class="manage-cart-floating-cart-preview-browser-address"></span>
					</div>
					<div class="manage-cart-floating-cart-preview-viewport">
						<button
							type="button"
							class="manage-cart-floating-cart-preview-trigger manage-cart-floating-cart-preview-trigger--<?php echo esc_attr( $position ); ?>"
							id="manage-cart-floating-cart-preview-trigger"
							tabindex="-1"
							style="<?php echo esc_attr( $preview_style ); ?>"
							<?php echo $enabled ? '' : 'hidden'; ?>
						>
							<span class="manage-cart-floating-cart-preview-icon" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="24" height="24" focusable="false">
									<path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="<?php echo esc_attr( Cart_Renderer::BASKET_ICON_PATH_D ); ?>"/>
								</svg>
							</span>
							<span class="manage-cart-floating-cart-preview-count" id="manage-cart-floating-cart-preview-count" aria-hidden="true" <?php echo $show_badge ? '' : 'hidden'; ?>>3</span>
						</button>
					</div>
				</div>
			</div>

			<p class="manage-cart-preview-note manage-cart-floating-cart-preview-empty-status" id="manage-cart-floating-cart-preview-empty-status">
				<span id="manage-cart-floating-cart-preview-empty-status-hides" <?php echo $hide_when_empty ? '' : 'hidden'; ?>>
					<?php esc_html_e( 'The floating cart button hides only when the cart is empty.', 'manage-cart' ); ?>
				</span>
				<span id="manage-cart-floating-cart-preview-empty-status-shows" <?php echo $hide_when_empty ? 'hidden' : ''; ?>>
					<?php esc_html_e( 'The floating cart button remains visible when the cart is empty.', 'manage-cart' ); ?>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Handles the Reset Side Cart Settings admin-post action.
	 *
	 * Restores the default Side Cart settings and redirects back to the
	 * shared Manage Cart screen, with the Side Cart tab selected, and a
	 * success flag used to display a success notice. Does not touch the
	 * Phase 2A `manage_cart_settings` option.
	 *
	 * @return void
	 */
	public function handle_reset() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'manage-cart' ) );
		}

		check_admin_referer( self::RESET_NONCE_ACTION, self::RESET_NONCE_NAME );

		Side_Cart_Settings::reset_to_defaults();

		$redirect_url = add_query_arg(
			array(
				'page'                        => MANAGE_CART_SETTINGS_SLUG,
				'tab'                         => 'side-cart',
				'manage_cart_side_cart_reset' => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handles the Reset Floating Cart Settings admin-post action.
	 *
	 * Restores the default Floating Cart settings and redirects back to
	 * the shared Manage Cart screen, with the Side Cart tab selected, and
	 * a success flag used to display a success notice. Does not touch the
	 * `manage_cart_settings` or `manage_cart_side_cart_settings` options.
	 *
	 * @return void
	 */
	public function handle_floating_cart_reset() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'manage-cart' ) );
		}

		check_admin_referer( self::FLOATING_CART_RESET_NONCE_ACTION, self::FLOATING_CART_RESET_NONCE_NAME );

		Floating_Cart_Settings::reset_to_defaults();

		$redirect_url = add_query_arg(
			array(
				'page'                        => MANAGE_CART_SETTINGS_SLUG,
				'tab'                         => 'side-cart',
				'manage_cart_side_cart_reset' => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handles the Side Cart → General save admin-post action (Phase 3F-6).
	 *
	 * This is a dedicated handler for this one option, entirely separate
	 * from the WordPress Settings API/options.php: it verifies the nonce
	 * and the `manage_woocommerce` capability itself, sanitizes the
	 * submission with the existing Side_Cart_Settings::sanitize(), and
	 * updates only the `manage_cart_side_cart_settings` option — never
	 * `manage_cart_settings` or `manage_cart_floating_cart_settings`. It
	 * sets a one-time, current-user transient (see
	 * get_side_cart_saved_transient_key()) instead of relying on the
	 * Settings API's own success notice (which previously leaked into the
	 * global Settings tab), then redirects back to Side Cart → General
	 * specifically, so that tab stays open and shows its own "Side Cart
	 * settings saved." notice (rendered in, and deleted by,
	 * render_side_cart_general_tab()).
	 *
	 * Existing General fields, sanitization, reset behavior, and frontend
	 * behavior are unchanged.
	 *
	 * Phase 3G-2A: this same handler now also saves the Side Cart →
	 * Appearance form (colors and border radius), which posts to this same
	 * action/nonce and shares this same option. Both forms include a
	 * hidden self::SAVE_SCOPE_FIELD input identifying which one was
	 * submitted ('general' or 'appearance'). Because each form only
	 * contains its own fields, the submitted values for the *other* form's
	 * fields are pulled from the currently-saved settings before
	 * sanitizing, so saving one tab can never reset the other tab's fields
	 * to their defaults. The redirect's `subtab` also follows the scope, so
	 * whichever tab was actually saved is the one shown afterward, with its
	 * own "settings saved" notice (see render_side_cart_general_tab() and
	 * render_side_cart_appearance_tab()).
	 *
	 * @return void
	 */
	public function handle_side_cart_save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'manage-cart' ) );
		}

		check_admin_referer( self::SAVE_NONCE_ACTION, self::SAVE_NONCE_NAME );

		$raw_input = array();
		if ( isset( $_POST[ Side_Cart_Settings::OPTION_NAME ] ) && is_array( $_POST[ Side_Cart_Settings::OPTION_NAME ] ) ) {
			$raw_input = wp_unslash( $_POST[ Side_Cart_Settings::OPTION_NAME ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized immediately below by Side_Cart_Settings::sanitize().
		}

		$scope = isset( $_POST[ self::SAVE_SCOPE_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::SAVE_SCOPE_FIELD ] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified above via check_admin_referer().
		if ( ! in_array( $scope, array( 'general', 'appearance' ), true ) ) {
			$scope = 'general';
		}

		// Preserve the other form's fields: pull them from the currently
		// saved settings for any key this submission didn't include, so
		// only the fields belonging to the form actually submitted are
		// ever changed by this save.
		$current_settings = Side_Cart_Settings::get_settings();
		$other_fields      = ( 'appearance' === $scope ) ? Side_Cart_Settings::GENERAL_FIELDS : Side_Cart_Settings::APPEARANCE_FIELDS;
		foreach ( $other_fields as $field_key ) {
			if ( ! array_key_exists( $field_key, $raw_input ) ) {
				$raw_input[ $field_key ] = $current_settings[ $field_key ];
			}
		}

		$sanitized = Side_Cart_Settings::sanitize( $raw_input );

		update_option( Side_Cart_Settings::OPTION_NAME, $sanitized );

		set_transient( self::get_side_cart_saved_transient_key(), 1, MINUTE_IN_SECONDS );

		$redirect_url = add_query_arg(
			array(
				'page'    => MANAGE_CART_SETTINGS_SLUG,
				'tab'     => 'side-cart',
				'section' => 'side-cart',
				'subtab'  => ( 'appearance' === $scope ) ? 'style' : 'general',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Builds the per-user transient key used to show the one-time "Side
	 * Cart settings saved." notice (Phase 3F-6).
	 *
	 * Scoping the key to the current user's ID means the notice can only
	 * ever be shown to the user who actually performed the save, and one
	 * user's save can never surface a notice for another logged-in user.
	 * Mirrors get_floating_cart_saved_transient_key() below.
	 *
	 * @return string
	 */
	protected static function get_side_cart_saved_transient_key() {
		return self::SIDE_CART_SAVED_TRANSIENT_PREFIX . get_current_user_id();
	}

	/**
	 * Handles the Floating Cart → General save admin-post action
	 * (Phase 3E-6).
	 *
	 * This is a dedicated handler for this one option, entirely separate
	 * from the WordPress Settings API/options.php: it verifies the nonce
	 * and the `manage_woocommerce` capability itself, sanitizes the
	 * submission with the existing Floating_Cart_Settings::sanitize(), and
	 * updates only the `manage_cart_floating_cart_settings` option — never
	 * `manage_cart_settings` or `manage_cart_side_cart_settings`. Phase
	 * 3F-3: it sets a one-time, current-user transient (see
	 * get_floating_cart_saved_transient_key()) instead of appending a
	 * `manage_cart_floating_cart_saved` URL flag, then redirects back to
	 * Floating Cart → General specifically (not just the Side Cart tab in
	 * general), so that tab stays open and shows its own "Floating Cart
	 * settings saved." notice (rendered in, and deleted by,
	 * render_floating_cart_general_tab()).
	 *
	 * Phase 3G-3A: this same handler now also saves the Floating Cart →
	 * Appearance form (colors, button size, and border radius), which
	 * posts to this same action/nonce and shares this same option. Both
	 * forms include a hidden self::FLOATING_CART_SAVE_SCOPE_FIELD input
	 * identifying which one was submitted ('general' or 'appearance').
	 * Because each form only contains its own fields, the submitted
	 * values for the *other* form's fields are pulled from the
	 * currently-saved settings before sanitizing, so saving one tab can
	 * never reset the other tab's fields to their defaults. The
	 * redirect's `subtab` also follows the scope, so whichever tab was
	 * actually saved is the one shown afterward, with its own "settings
	 * saved" notice (see render_floating_cart_general_tab() and
	 * render_floating_cart_appearance_tab()). Mirrors
	 * handle_side_cart_save().
	 *
	 * @return void
	 */
	public function handle_floating_cart_save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'manage-cart' ) );
		}

		check_admin_referer( self::FLOATING_CART_SAVE_NONCE_ACTION, self::FLOATING_CART_SAVE_NONCE_NAME );

		$raw_input = array();
		if ( isset( $_POST[ Floating_Cart_Settings::OPTION_NAME ] ) && is_array( $_POST[ Floating_Cart_Settings::OPTION_NAME ] ) ) {
			$raw_input = wp_unslash( $_POST[ Floating_Cart_Settings::OPTION_NAME ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized immediately below by Floating_Cart_Settings::sanitize().
		}

		$scope = isset( $_POST[ self::FLOATING_CART_SAVE_SCOPE_FIELD ] ) ? sanitize_key( wp_unslash( $_POST[ self::FLOATING_CART_SAVE_SCOPE_FIELD ] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified above via check_admin_referer().
		if ( ! in_array( $scope, array( 'general', 'appearance' ), true ) ) {
			$scope = 'general';
		}

		// Preserve the other form's fields: pull them from the currently
		// saved settings for any key this submission didn't include, so
		// only the fields belonging to the form actually submitted are
		// ever changed by this save.
		$current_settings = Floating_Cart_Settings::get_settings();
		$other_fields      = ( 'appearance' === $scope ) ? Floating_Cart_Settings::GENERAL_FIELDS : Floating_Cart_Settings::APPEARANCE_FIELDS;
		foreach ( $other_fields as $field_key ) {
			if ( ! array_key_exists( $field_key, $raw_input ) ) {
				$raw_input[ $field_key ] = $current_settings[ $field_key ];
			}
		}

		$sanitized = Floating_Cart_Settings::sanitize( $raw_input );

		update_option( Floating_Cart_Settings::OPTION_NAME, $sanitized );

		set_transient( self::get_floating_cart_saved_transient_key(), 1, MINUTE_IN_SECONDS );

		$redirect_url = add_query_arg(
			array(
				'page'    => MANAGE_CART_SETTINGS_SLUG,
				'tab'     => 'side-cart',
				'section' => 'floating-cart',
				'subtab'  => ( 'appearance' === $scope ) ? 'style' : 'general',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Builds the per-user transient key used to show the one-time
	 * "Floating Cart settings saved." notice (Phase 3F-3).
	 *
	 * Scoping the key to the current user's ID means the notice can only
	 * ever be shown to the user who actually performed the save, and one
	 * user's save can never surface a notice for another logged-in user.
	 *
	 * @return string
	 */
	protected static function get_floating_cart_saved_transient_key() {
		return self::FLOATING_CART_SAVED_TRANSIENT_PREFIX . get_current_user_id();
	}
}
