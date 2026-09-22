<?php
/**
 * Shared in-page "Manage Cart" app header (Phase 2C, converted to instant
 * in-page tab switching in Phase 2E).
 *
 * @package ManageCart
 */

namespace ManageCart;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class App_Header
 *
 * Renders a small, original in-page navigation header shared by the single
 * top-level "Manage Cart" admin screen, which renders both the Side Cart
 * panel and the Settings panel in the same page. The header's tabs
 * (labeled "Cart Display" and "Settings") are accessible buttons (not
 * links): a small local script (assets/js/app-header-tabs.js)
 * shows/hides the matching panel, updates aria-selected/tabindex/active
 * styling, and updates the browser URL via history.replaceState, all
 * without a page reload. This is pure admin UI: it does not load,
 * render, or affect any frontend cart markup or behavior.
 *
 * Phase 3G-1 renames the first tab's visible label from "Side Cart" to
 * "Cart Display" (the tab still targets the same `side-cart` panel/tab
 * key, id, and URL `tab` value — only the button's on-screen text
 * changed). The panel's own inner "Side Cart" / "Floating Cart" section
 * names are unaffected.
 *
 * Phase 3E-5 removes the single shared "Save Changes" button this header
 * used to render (it submitted whichever settings form happened to be
 * visible, via a JS-repointed `form` attribute or, later,
 * `requestSubmit()` — see the removed `assets/js/app-header-tabs.js` save
 * logic). Each settings form (Global Settings, Side Cart → General,
 * Floating Cart → General) now renders its own real Save Changes submit
 * button at the bottom of its own card instead (see Admin and
 * Side_Cart_Admin), so this header no longer needs to know about forms at
 * all — it is tab navigation only.
 *
 * Edition pill: alongside the version pill, this header also shows a
 * compact "Free" or "Pro" pill so an admin can see at a glance which
 * edition is active. This is admin-only UI — App_Header::render() is only
 * ever called from Admin's own admin_menu page callback (see
 * class-admin.php), so it never reaches the storefront. "Pro" is shown
 * only when the Freemius SDK itself reports an active, feature-enabled
 * license or trial; see is_pro_edition_active() below for exactly what
 * that checks. This reads Freemius's own license state directly rather
 * than any constant or activation check of its own. (It says nothing about
 * which individual Pro features a plan includes.)
 *
 * Upgrade discovery (Free build only): this header also renders a
 * third "Upgrade to Pro" tab, alongside "Cart Display" and "Settings",
 * linking to the informational Upgrade tab panel (see Upgrade_Tab and
 * Admin::render_page()). It is purely a navigational tab — no popup, no
 * redirect, no notice — and every one of its own calls to action use
 * manage_cart_fs()->get_upgrade_url() so Freemius remains the source of
 * truth for where an upgrade actually goes.
 */
class App_Header {

	/**
	 * Whether a paid license or trial is active, for the sole purpose of
	 * choosing this header's "Free" vs "Pro" edition pill.
	 *
	 * Reads Freemius's own `can_use_premium_code()` (active license or
	 * trial, any plan) directly, rather than any constant or namespaced
	 * function this codebase defines itself. `manage_cart_fs()` is guarded with
	 * function_exists() purely defensively: if for any reason the SDK
	 * has not been initialized when this runs, this simply falls back
	 * to "Free" rather than fataling.
	 *
	 * @return bool
	 */
	protected static function is_pro_edition_active() {
		if ( ! function_exists( 'manage_cart_fs' ) ) {
			return false;
		}

		return (bool) manage_cart_fs()->can_use_premium_code();
	}

	/**
	 * Renders the app header.
	 *
	 * @param string $active_tab Which tab is active on initial page load:
	 *                            'side-cart', 'settings', or 'upgrade'.
	 * @return void
	 */
	public static function render( $active_tab ) {
		$side_cart_url = add_query_arg(
			array(
				'page' => MANAGE_CART_SETTINGS_SLUG,
				'tab'  => 'side-cart',
			),
			admin_url( 'admin.php' )
		);
		$settings_url  = add_query_arg(
			array(
				'page' => MANAGE_CART_SETTINGS_SLUG,
				'tab'  => 'settings',
			),
			admin_url( 'admin.php' )
		);
		// Free build only: the Upgrade to Pro tab exists only when its class
		// was loaded (see Admin::is_upgrade_tab_available()).
		$has_upgrade_tab = Admin::is_upgrade_tab_available();
		$upgrade_url     = add_query_arg(
			array(
				'page' => MANAGE_CART_SETTINGS_SLUG,
				'tab'  => 'upgrade',
			),
			admin_url( 'admin.php' )
		);

		$is_side_cart_active = ( 'side-cart' === $active_tab );
		$is_settings_active  = ( 'settings' === $active_tab );
		$is_upgrade_active   = ( 'upgrade' === $active_tab );

		$is_pro         = self::is_pro_edition_active();
		$edition_class  = $is_pro ? 'is-pro' : 'is-free';
		$edition_label  = $is_pro ? __( 'Pro', 'manage-cart' ) : __( 'Free', 'manage-cart' );
		?>
		<div class="manage-cart-app-header">
			<div class="manage-cart-app-header-brand">
				<span class="manage-cart-app-header-icon dashicons dashicons-cart" aria-hidden="true"></span>
				<span class="manage-cart-app-header-name"><?php esc_html_e( 'ManageCart', 'manage-cart' ); ?></span>
				<span class="manage-cart-app-header-version">
					<?php
					printf(
						/* translators: %s: current plugin version number, e.g. "1.2.3". */
						esc_html__( 'v%s', 'manage-cart' ),
						esc_html( MANAGE_CART_VERSION )
					);
					?>
				</span>
				<span class="manage-cart-app-header-edition <?php echo esc_attr( $edition_class ); ?>">
					<?php echo esc_html( $edition_label ); ?>
				</span>
			</div>

			<nav class="manage-cart-app-header-tabs" id="manage-cart-app-header-tabs" role="tablist" aria-label="<?php esc_attr_e( 'ManageCart', 'manage-cart' ); ?>">
				<button
					type="button"
					id="manage-cart-tab-side-cart"
					class="manage-cart-app-header-tab<?php echo $is_side_cart_active ? ' is-active' : ''; ?>"
					role="tab"
					data-manage-cart-tab="side-cart"
					aria-selected="<?php echo $is_side_cart_active ? 'true' : 'false'; ?>"
					aria-controls="manage-cart-panel-side-cart"
					tabindex="<?php echo $is_side_cart_active ? '0' : '-1'; ?>"
					data-href="<?php echo esc_url( $side_cart_url ); ?>"
				>
					<?php esc_html_e( 'Cart Display', 'manage-cart' ); ?>
				</button>
				<button
					type="button"
					id="manage-cart-tab-settings"
					class="manage-cart-app-header-tab<?php echo $is_settings_active ? ' is-active' : ''; ?>"
					role="tab"
					data-manage-cart-tab="settings"
					aria-selected="<?php echo $is_settings_active ? 'true' : 'false'; ?>"
					aria-controls="manage-cart-panel-settings"
					tabindex="<?php echo $is_settings_active ? '0' : '-1'; ?>"
					data-href="<?php echo esc_url( $settings_url ); ?>"
				>
					<?php esc_html_e( 'Settings', 'manage-cart' ); ?>
				</button>
				<?php if ( $has_upgrade_tab ) : ?>
				<button
					type="button"
					id="manage-cart-tab-upgrade"
					class="manage-cart-app-header-tab manage-cart-app-header-tab-upgrade<?php echo $is_upgrade_active ? ' is-active' : ''; ?>"
					role="tab"
					data-manage-cart-tab="upgrade"
					aria-selected="<?php echo $is_upgrade_active ? 'true' : 'false'; ?>"
					aria-controls="manage-cart-panel-upgrade"
					tabindex="<?php echo $is_upgrade_active ? '0' : '-1'; ?>"
					data-href="<?php echo esc_url( $upgrade_url ); ?>"
				>
					<?php esc_html_e( 'Upgrade to Pro', 'manage-cart' ); ?>
				</button>
				<?php endif; ?>
			</nav>
		</div>
		<?php
		/**
		 * Fires right after the ManageCart admin app header, on ManageCart's own
		 * admin screen only (this header is only rendered from Admin's page
		 * callback). Nothing in the Free build attaches to it.
		 */
		do_action( 'manage_cart_after_app_header' );
	}
}
