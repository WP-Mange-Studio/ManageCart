<?php
/**
 * wp-admin settings page and menu registration.
 *
 * @package ManageCart
 */

namespace ManageCart;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin
 *
 * Registers the "Manage Cart" top-level admin menu and renders the single
 * shared Manage Cart admin screen. This screen renders three panels in
 * the DOM at the same time — Settings (this class), Cart Display
 * (Side_Cart_Admin), and, on the Free edition only, Upgrade to Pro
 * (Upgrade_Tab) — and the in-page App_Header tabs show/hide the matching
 * panel client-side, with no full page reload. The sidebar
 * submenu is arranged by arrange_submenus() (Cart Display, Contact Us,
 * Support Forum, Upgrade), and every panel remains reachable via the in-page tabs or a direct URL. This class
 * also still handles the separate Reset Settings admin-post action, and
 * adds "Settings" and "Go Pro" action links on the Plugins screen (Free
 * edition only). Access is restricted to users with the
 * `manage_woocommerce` capability, matching the Cart Display panel (see
 * Side_Cart_Admin).
 */
class Admin {

	/**
	 * Capability required to view or change Manage Cart settings.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * admin-post.php action name used for the Reset Settings form.
	 *
	 * @var string
	 */
	const RESET_ACTION = 'manage_cart_reset_settings';

	/**
	 * Nonce action used for the Reset Settings form.
	 *
	 * @var string
	 */
	const RESET_NONCE_ACTION = 'manage_cart_reset_settings_action';

	/**
	 * Nonce field name used for the Reset Settings form.
	 *
	 * @var string
	 */
	const RESET_NONCE_NAME = 'manage_cart_reset_settings_nonce';

	/**
	 * Stable HTML id given to the global Settings form. Used by CSS/JS via
	 * the shared `manage-cart-savable-form` class; the form now renders
	 * its own Save Changes submit button directly inside itself (Phase
	 * 3E-5), so this id is no longer needed to point any button at it
	 * from outside the form.
	 *
	 * @var string
	 */
	const SETTINGS_FORM_ID = 'manage-cart-settings-form';

	/**
	 * Renders WordPress Settings API notices restricted to the given
	 * option name(s), then removes those same entries from the shared
	 * `$wp_settings_errors` global so they cannot be printed again by any
	 * later, differently-scoped call on this same page load (Phase 3E-3,
	 * hardened in Phase 3E-4).
	 *
	 * Both this class and Side_Cart_Admin render a Settings panel on
	 * every load of the shared Manage Cart screen — one of them visible,
	 * the other sitting in the DOM with the `hidden` attribute. Neither
	 * ever calls the bare `settings_errors()` (no `$setting` filter),
	 * which would print every pending notice regardless of which option
	 * it belongs to; every call here is scoped to the option name(s) the
	 * calling panel actually owns.
	 *
	 * Phase 3E-4: this also de-duplicates the shared `$wp_settings_errors`
	 * global by the combination of `setting` and `code` (not `setting`
	 * alone) immediately before and after each `settings_errors()` call.
	 * `settings_errors()`/`get_settings_errors()` can merge the same
	 * `settings_errors` transient into that global more than once (for
	 * example if a sanitize callback ends up registered — and therefore
	 * firing — more than once for the same save), which previously could
	 * still surface the exact same notice twice even though each panel's
	 * call is already scoped to a single option. De-duplicating by
	 * setting+code rather than by setting alone leaves distinct notices
	 * for the same option (e.g. an error and a separate success message)
	 * intact, while collapsing true repeats down to one. Combined with
	 * the existing per-option scoping, a given notice is printed by
	 * exactly one of the two panels' calls, exactly once.
	 *
	 * @param string[] $option_names One or more Settings API option names
	 *                                 (the `$option` argument originally
	 *                                 passed to add_settings_error()) whose
	 *                                 pending notices should be shown here.
	 * @return void
	 */
	public static function render_option_notices( array $option_names ) {
		global $wp_settings_errors;

		self::dedupe_settings_errors();

		foreach ( $option_names as $option_name ) {
			settings_errors( $option_name );
		}

		self::dedupe_settings_errors();

		if ( empty( $wp_settings_errors ) ) {
			return;
		}

		$wp_settings_errors = array_values(
			array_filter(
				$wp_settings_errors,
				static function ( $error ) use ( $option_names ) {
					return ! in_array( $error['setting'], $option_names, true );
				}
			)
		);
	}

	/**
	 * Collapses the shared `$wp_settings_errors` global down to one entry
	 * per unique `setting` + `code` pair, keeping the first occurrence of
	 * each. See render_option_notices() for why this is needed.
	 *
	 * @return void
	 */
	protected static function dedupe_settings_errors() {
		global $wp_settings_errors;

		if ( empty( $wp_settings_errors ) || ! is_array( $wp_settings_errors ) ) {
			return;
		}

		$seen_keys = array();
		$deduped   = array();

		foreach ( $wp_settings_errors as $error ) {
			if ( ! isset( $error['setting'], $error['code'] ) ) {
				$deduped[] = $error;
				continue;
			}

			$key = $error['setting'] . '|' . $error['code'];

			if ( isset( $seen_keys[ $key ] ) ) {
				continue;
			}

			$seen_keys[ $key ] = true;
			$deduped[]         = $error;
		}

		$wp_settings_errors = $deduped;
	}

	/**
	 * Registers admin hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		// Must run after Freemius builds its submenu rows (admin_menu priority WP_FS__LOWEST_PRIORITY, 999999999).
		add_action( 'admin_menu', array( $this, 'arrange_submenus' ), 1000000000 );
		add_action( 'admin_init', array( '\ManageCart\Settings', 'register' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( $this, 'handle_reset' ) );
		add_filter( 'option_page_capability_' . Settings::OPTION_GROUP, array( $this, 'get_settings_capability' ) );
		// Priority 20: runs after Freemius's own action-links filter
		// (hooked at its default priority when the SDK initializes), so
		// add_settings_action_link() below can see and de-duplicate
		// against whatever Freemius may have already added.
		add_filter( 'plugin_action_links_' . MANAGE_CART_PLUGIN_BASENAME, array( $this, 'add_settings_action_link' ), 20 );
	}

	/**
	 * Whether the informational "Upgrade to Pro" tab exists in this build.
	 *
	 * The tab lives in includes/class-upgrade-tab.php, which manage-cart.php
	 * loads only when the running build is not the Premium one
	 * (`! manage_cart_fs()->is_premium()`). The tab, its panel, its header button, its
	 * stylesheet, and the "Go Pro" plugin-row link are all shown only when
	 * that class was loaded, so the Premium build has none of them.
	 *
	 * @return bool
	 */
	public static function is_upgrade_tab_available() {
		return class_exists( __NAMESPACE__ . '\Upgrade_Tab', false );
	}

	/**
	 * Adds the "Settings" action link to the plugin's row on the Plugins
	 * screen and, in the Free build only, a "Go Pro" link, producing the
	 * row Settings | Go Pro | Opt In/Opt Out | Deactivate (Free) or
	 * Settings | ... | Deactivate (Premium). The existing Settings link is
	 * unchanged; "Go Pro" links to
	 * `manage_cart_fs()->get_upgrade_url()`, and works the same way
	 * whether the admin opted in or skipped opt-in, since it is added
	 * here directly rather than relying on Freemius's own conditional
	 * action-link visibility.
	 *
	 * Because the Freemius SDK may, depending on opt-in/registration
	 * state, already have added its own "Upgrade" action link (key
	 * `upgrade`) to this same row by the time this runs (this filter is
	 * hooked at priority 20, after the SDK's own), that entry is removed
	 * first so only one Pro-upgrade link — this one — ever appears; the
	 * destination itself still comes from the same
	 * `manage_cart_fs()->get_upgrade_url()` either way.
	 *
	 * @param string[] $links Existing plugin action links.
	 * @return string[]
	 */
	public function add_settings_action_link( $links ) {
		$settings_url = add_query_arg(
			array(
				'page' => MANAGE_CART_SETTINGS_SLUG,
				'tab'  => 'settings',
			),
			admin_url( 'admin.php' )
		);

		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $settings_url ),
			esc_html__( 'Settings', 'manage-cart' )
		);

		// Premium build: only the Settings link is added; Freemius's own
		// links are left exactly as the SDK produced them.
		if ( ! self::is_upgrade_tab_available() ) {
			array_unshift( $links, $settings_link );

			return $links;
		}

		$go_pro_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( manage_cart_fs()->get_upgrade_url() ),
			esc_html__( 'Go Pro', 'manage-cart' )
		);

		// Avoid showing both Freemius's own "Upgrade" link and this
		// "Go Pro" link side by side; see docblock above.
		unset( $links['upgrade'] );

		return array_merge( array( $settings_link, $go_pro_link ), $links );
	}

	/**
	 * Filters the capability the Settings API requires to submit the
	 * Manage Cart settings form via options.php. Without this, options.php
	 * defaults to requiring `manage_options`, which would block Shop
	 * Managers (who have `manage_woocommerce` but not `manage_options`)
	 * from saving settings even though they can see this page.
	 *
	 * @return string
	 */
	public function get_settings_capability() {
		return self::CAPABILITY;
	}

	/**
	 * Registers the top-level "Manage Cart" admin menu page. This is the
	 * single shared screen that renders both the Settings panel and the
	 * Cart Display panel.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'ManageCart Settings', 'manage-cart' ),
			__( 'ManageCart', 'manage-cart' ),
			self::CAPABILITY,
			MANAGE_CART_SETTINGS_SLUG,
			array( $this, 'render_page' ),
			'dashicons-cart',
			56
		);
	}

	/**
	 * Arranges the wp-admin sidebar submenu under "ManageCart".
	 *
	 * Final order: Cart Display, Contact Us, Support Forum, Upgrade.
	 *
	 * - The WordPress-generated submenu row for the top-level page
	 *   (slug MANAGE_CART_SETTINGS_SLUG) is kept, relabelled "Cart Display",
	 *   and placed first. Because WordPress uses the first submenu row as
	 *   the top-level item's destination, clicking "ManageCart" opens
	 *   admin.php?page=manage-cart-settings, whose no-`tab` default is the
	 *   Cart Display (Side Cart) panel (see get_active_tab()).
	 * - The old duplicate Side Cart row (MANAGE_CART_SIDE_CART_SLUG) is
	 *   removed; that page stays registered and reachable by direct URL.
	 * - The Freemius rows (Contact Us, Support Forum, Upgrade) follow in
	 *   that order. Any other row already present is kept after them, and
	 *   duplicate slugs are dropped.
	 *
	 * Runs after Freemius's own admin_menu callback (priority 999999999) so
	 * it has the final say on order. No CSS or JavaScript is involved.
	 *
	 * @return void
	 */
	public function arrange_submenus() {
		global $submenu;

		remove_submenu_page( MANAGE_CART_SETTINGS_SLUG, MANAGE_CART_SIDE_CART_SLUG );

		if ( empty( $submenu[ MANAGE_CART_SETTINGS_SLUG ] ) || ! is_array( $submenu[ MANAGE_CART_SETTINGS_SLUG ] ) ) {
			return;
		}

		$freemius_order = array(
			MANAGE_CART_SETTINGS_SLUG . '-contact'          => 1,
			MANAGE_CART_SETTINGS_SLUG . '-wp-support-forum' => 2,
			MANAGE_CART_SETTINGS_SLUG . '-pricing'          => 3,
		);

		$primary = null;
		$ordered = array();
		$others  = array();
		$seen    = array();

		foreach ( $submenu[ MANAGE_CART_SETTINGS_SLUG ] as $item ) {
			$slug = isset( $item[2] ) ? $item[2] : '';

			if ( '' === $slug || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;

			if ( MANAGE_CART_SETTINGS_SLUG === $slug ) {
				$primary = $item;
			} elseif ( isset( $freemius_order[ $slug ] ) ) {
				$ordered[ $freemius_order[ $slug ] ] = $item;
			} else {
				$others[] = $item;
			}
		}

		if ( null === $primary ) {
			$primary = array(
				__( 'Cart Display', 'manage-cart' ),
				self::CAPABILITY,
				MANAGE_CART_SETTINGS_SLUG,
				__( 'ManageCart Settings', 'manage-cart' ),
			);
		}

		$primary[0] = __( 'Cart Display', 'manage-cart' );

		ksort( $ordered );

		$submenu[ MANAGE_CART_SETTINGS_SLUG ] = array_merge( array( $primary ), array_values( $ordered ), $others ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Determines which tab should be active on initial page load, based on
	 * the `tab` query var. This only affects which panel starts visible
	 * server-side (and which one JavaScript takes over from); it performs
	 * no action and requires no nonce.
	 *
	 * @return string One of 'side-cart', 'upgrade' (Free build only), or 'settings'.
	 */
	protected function get_active_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'side-cart'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'side-cart' === $tab ) {
			return $tab;
		}

		// The Upgrade to Pro tab only exists in the Free build.
		if ( 'upgrade' === $tab && self::is_upgrade_tab_available() ) {
			return $tab;
		}

		return 'settings';
	}

	/**
	 * Renders the shared Manage Cart admin screen: the app header, followed
	 * by the Settings, Side Cart, and Upgrade to Pro panels, all in the DOM
	 * at once. Only the panel matching the initial active tab is visible;
	 * the others are rendered with the `hidden` attribute so the in-page
	 * tabs (assets/js/app-header-tabs.js) can switch between them instantly.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'manage-cart' ) );
		}

		$active_tab      = $this->get_active_tab();
		$side_cart_admin = new Side_Cart_Admin();
		?>
		<div class="wrap manage-cart-app-wrap">
			<?php App_Header::render( $active_tab ); ?>

			<div
				id="manage-cart-panel-settings"
				class="manage-cart-app-panel manage-cart-settings-wrap"
				role="tabpanel"
				aria-labelledby="manage-cart-tab-settings"
				<?php echo ( 'settings' === $active_tab ) ? '' : 'hidden'; ?>
			>
				<?php $this->render_panel_content(); ?>
			</div>

			<div
				id="manage-cart-panel-side-cart"
				class="manage-cart-app-panel manage-cart-side-cart-wrap"
				role="tabpanel"
				aria-labelledby="manage-cart-tab-side-cart"
				<?php echo ( 'side-cart' === $active_tab ) ? '' : 'hidden'; ?>
			>
				<?php $side_cart_admin->render_panel_content(); ?>
			</div>

			<?php if ( self::is_upgrade_tab_available() ) : ?>
			<div
				id="manage-cart-panel-upgrade"
				class="manage-cart-app-panel manage-cart-upgrade-tab-wrap"
				role="tabpanel"
				aria-labelledby="manage-cart-tab-upgrade"
				<?php echo ( 'upgrade' === $active_tab ) ? '' : 'hidden'; ?>
			>
				<?php Upgrade_Tab::render_panel_content(); ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the inner content of the Settings panel: title, notices, the
	 * settings form (Settings API fields, nonces, sanitization, and option
	 * name all unchanged), and the separate Reset Settings form.
	 *
	 * Phase 3E-5: this form renders its own real "Save Changes" submit
	 * button at the bottom of the card (inside the form itself, so it can
	 * only ever submit this form), replacing the single shared header
	 * button removed from App_Header.
	 *
	 * Notices are shown via render_option_notices(), scoped to
	 * Settings::OPTION_NAME only, so a Side Cart or Floating Cart save
	 * never appears on this tab (see that method's docblock).
	 *
	 * @return void
	 */
	public function render_panel_content() {
		?>
		<h1><?php esc_html_e( 'ManageCart Settings', 'manage-cart' ); ?></h1>

		<?php if ( isset( $_GET['manage_cart_reset'] ) && '1' === $_GET['manage_cart_reset'] ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'ManageCart settings have been reset to their defaults.', 'manage-cart' ); ?></p>
			</div>
		<?php endif; ?>

		<?php self::render_option_notices( array( Settings::OPTION_NAME ) ); ?>

		<div class="manage-cart-settings-card">
			<form
				id="<?php echo esc_attr( self::SETTINGS_FORM_ID ); ?>"
				class="manage-cart-savable-form"
				method="post"
				action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>"
			>
				<?php
				settings_fields( Settings::OPTION_GROUP );
				do_settings_sections( Settings::PAGE_SLUG );
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
				<?php wp_nonce_field( self::RESET_NONCE_ACTION, self::RESET_NONCE_NAME ); ?>
				<?php submit_button( __( 'Reset Settings', 'manage-cart' ), 'secondary', 'manage_cart_reset_settings_submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handles the Reset Settings admin-post action.
	 *
	 * Restores the default settings and redirects back to the shared
	 * Manage Cart screen, with the Settings tab selected, and a success
	 * flag used to display a WordPress success notice.
	 *
	 * @return void
	 */
	public function handle_reset() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'manage-cart' ) );
		}

		check_admin_referer( self::RESET_NONCE_ACTION, self::RESET_NONCE_NAME );

		Settings::reset_to_defaults();

		$redirect_url = add_query_arg(
			array(
				'page'              => MANAGE_CART_SETTINGS_SLUG,
				'tab'               => 'settings',
				'manage_cart_reset' => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}
}
