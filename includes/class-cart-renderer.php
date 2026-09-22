<?php
/**
 * Server-side rendering of the frontend cart trigger and panel (Phase 3A),
 * plus the cart action UI added in Phase 3B-1 Part 1 (quantity controls,
 * remove button, and the aria-live status area).
 *
 * @package ManageCart
 */

namespace ManageCart;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cart_Renderer
 *
 * Builds the markup for the floating cart trigger button and the cart
 * panel/overlay output on wp_footer. Reads real, current WooCommerce cart
 * data via the core Cart API and documented product/URL helpers only; never
 * writes to the cart and never includes or overrides a WooCommerce template
 * file. All markup uses manage-cart-prefixed classes and ids, except for the
 * `<img>` produced by WC_Product::get_image(), which is WooCommerce's own
 * supported thumbnail helper and is wrapped in a manage-cart-prefixed
 * container.
 *
 * Phase 3B-1 Part 1 adds the accessible quantity stepper and remove button
 * markup (per the `show_quantity_controls` / `show_remove_button` Side Cart
 * settings) and a persistent aria-live status region for future
 * success/error messages. These controls are UI-only in this part: they
 * carry `data-manage-cart-*` hooks and `data-cart-item-key` for a later
 * phase to wire up, but this phase does not attach any Store API/AJAX
 * request or cart-mutation behavior to them.
 *
 * Phase 3B-1 Part 5 adds a read-only sale-savings display per line item:
 * a struck-through regular unit price plus a "Save X%" badge, shown only
 * when the product's WooCommerce sale data (`is_on_sale()`, regular vs.
 * sale price) reflects a real, current discount. It renders nothing for
 * products that aren't on sale, or whose computed percentage rounds to
 * 0%. This is display-only: it does not touch quantity, remove, or
 * subtotal markup/behavior, and adds no new admin setting.
 *
 * Phase 3B adds a read-only "Only X left" low stock badge below each cart
 * line item, controlled by the new "Show low stock badge" and "Low stock
 * threshold" Side Cart settings (default off / 5). It renders only when
 * WooCommerce actively manages stock for that exact product/variation, the
 * tracked stock quantity is a positive number at or below the threshold,
 * and the item isn't currently on backorder. This is display-only and
 * leaves all other cart item markup/behavior unchanged.
 *
 * Phase 3C originally added a matching plain-data snapshot method here plus
 * a small custom REST route (Cart_Panel_Route) so the frontend script could
 * refresh the panel after an add-to-cart without a page reload. The
 * auto-open/live-cart fix removes both: the frontend script now fetches
 * WooCommerce's own `GET /wc/store/v1/cart` Store API route directly and
 * builds the panel from that real, current response with
 * document.createElement()/.textContent only — never innerHTML/
 * insertAdjacentHTML — so this class no longer needs a bespoke data
 * snapshot of its own for that purpose.
 *
 * Phase 3F-1 adds a "Have a coupon?" toggle and coupon code form to the
 * footer, above the Subtotal row (see render_coupon_section()). It is
 * display markup only: applying the coupon itself is handled entirely by
 * the frontend script against WooCommerce's own
 * `POST /wc/store/v1/cart/apply-coupon` Store API route, the same way the
 * existing quantity/remove controls talk to their own Store API routes.
 * The section renders nothing at all when `wc_coupons_enabled()` is
 * false, and no new admin setting is added to control it separately.
 *
 * Phase 3G-3B adds the same treatment to the floating trigger button:
 * render_trigger() now also writes the saved Floating Cart → Appearance
 * values (button background, icon color, count badge colors, size, and
 * border radius) as inline CSS custom properties, read by
 * assets/css/frontend.css with a fallback to the button's previous fixed
 * styling. See get_trigger_appearance_style_string().
 *
 * Phase 3H-1 adds the applied-coupons list to the coupon section (see
 * render_applied_coupons()): once at least one coupon is applied to the
 * cart, its code, discount amount, and an accessible Remove button are
 * shown above the "Have a coupon?" toggle — still above the Subtotal
 * row. Removing a coupon is handled entirely by the frontend script
 * against WooCommerce's own `POST /wc/store/v1/cart/remove-coupon`
 * Store API route, the same nonce-bearing window.fetch pattern already
 * used for apply-coupon/update-item/remove-item; no custom PHP AJAX
 * handler or REST route is added, and no new admin setting controls
 * this display.
 *
 * The Cart UX pass adds two purely presentational changes. First, an
 * original inline ManageCart basket icon (BASKET_ICON_PATH_D) replaces
 * the previous generic bag/cart glyph in the floating trigger, the
 * side-cart panel header, and the empty-cart state. Second, the panel
 * footer gains a secondary "Continue Shopping" button next to "View
 * Cart", linking to the real WooCommerce Shop page URL and omitted
 * entirely when no Shop page is configured (see render_footer()).
 * Neither change touches quantity, remove, coupon, or checkout
 * behavior, and no new admin setting is added for either.
 *
 * Pro add-on readiness: this class fires a small set of `manage_cart_*`
 * actions/filters (panel body start/end, footer top, before panel
 * actions, after each cart item, after the trigger, after the empty
 * state, and appearance-style filters) purely so a separate ManageCart
 * Pro add-on can later attach cart upsells, a free-shipping progress
 * bar, custom cart notes, and advanced styling without modifying this
 * file. None of these hooks have any callback attached in the free
 * plugin, so free ManageCart's own output and behavior are completely
 * unaffected by their presence.
 */
class Cart_Renderer {

	/**
	 * Id of the floating cart trigger button.
	 *
	 * @var string
	 */
	const TRIGGER_ID = 'manage-cart-trigger';

	/**
	 * Id of the cart panel.
	 *
	 * @var string
	 */
	const PANEL_ID = 'manage-cart-panel';

	/**
	 * Id of the overlay behind the cart panel.
	 *
	 * @var string
	 */
	const OVERLAY_ID = 'manage-cart-overlay';

	/**
	 * Id of the panel's close button.
	 *
	 * @var string
	 */
	const CLOSE_BUTTON_ID = 'manage-cart-close';

	/**
	 * Id of the panel's accessible title, referenced by aria-labelledby.
	 *
	 * @var string
	 */
	const TITLE_ID = 'manage-cart-panel-title';

	/**
	 * Id of the aria-live status region used for success/error messages
	 * (e.g. after a quantity change or item removal). Starts empty in the
	 * markup; populated client-side by the frontend script.
	 *
	 * @var string
	 */
	const STATUS_ID = 'manage-cart-status';

	/**
	 * Id of the "Have a coupon?" toggle button that shows/hides the
	 * coupon code form (Phase 3F-1).
	 *
	 * @var string
	 */
	const COUPON_TOGGLE_ID = 'manage-cart-coupon-toggle';

	/**
	 * Id of the coupon code form, referenced by the toggle's
	 * aria-controls (Phase 3F-1).
	 *
	 * @var string
	 */
	const COUPON_FORM_ID = 'manage-cart-coupon-form';

	/**
	 * Id of the coupon code text input (Phase 3F-1).
	 *
	 * @var string
	 */
	const COUPON_INPUT_ID = 'manage-cart-coupon-input';

	/**
	 * Id of the applied-coupons list shown above the coupon code form,
	 * once at least one coupon is applied to the cart (Phase 3H-1).
	 *
	 * @var string
	 */
	const COUPON_LIST_ID = 'manage-cart-coupon-list';

	/**
	 * Id of the compact "Product removed" Undo notice banner shown
	 * briefly after a successful item removal (Free — Undo Removed Cart
	 * Item, Part 1). Always rendered, hidden, as a direct child of the
	 * panel — never inside the panel body — so the item-list/empty-state
	 * DOM rebuilds in assets/js/frontend-cart.js never touch it. See
	 * render_undo_notice().
	 *
	 * @var string
	 */
	const UNDO_NOTICE_ID = 'manage-cart-undo-notice';

	/**
	 * Id of the Undo notice's own Undo button (Free — Undo Removed Cart
	 * Item, Part 1). See render_undo_notice().
	 *
	 * @var string
	 */
	const UNDO_BUTTON_ID = 'manage-cart-undo-button';

	/**
	 * SVG path data (`d` attribute, evenodd fill rule) for the cart item
	 * remove/trash icon: a lid, a handle, and a can body with three
	 * vertical cut-out lines. An original ManageCart icon, drawn to keep
	 * the same compact 24x24 viewBox and `currentColor` fill convention
	 * as the plugin's other inline icons; not sourced from any external
	 * icon library or third-party plugin. Kept in sync with the
	 * identical `REMOVE_ICON_PATH_D` constant in
	 * assets/js/frontend-cart.js, used when a row is rebuilt client-side
	 * after a live cart refresh.
	 *
	 * @var string
	 */
	const REMOVE_ICON_PATH_D = 'M5 6h14v2H5V6Z M9 3h6l.6 3H8.4L9 3Z M6 8h12l-1.1 12.1a2 2 0 0 1-2 2H9.1a2 2 0 0 1-2-2L6 8Z M9.3 10h1.4v8H9.3V10Z M11.3 10h1.4v8h-1.4V10Z M13.3 10h1.4v8h-1.4V10Z';

	/**
	 * SVG path data (`d` attribute, evenodd fill rule) for the ManageCart
	 * basket icon: an arched handle, a reinforced top rim, and a tapered
	 * basket body with two woven-slat cut-out lines. An original ManageCart
	 * icon drawn specifically for this plugin — not sourced from any icon
	 * font, icon library, CDN, or third-party plugin — using the same
	 * compact 24x24 viewBox and `currentColor` fill convention as the
	 * plugin's other inline icons (see REMOVE_ICON_PATH_D above). Used for
	 * the floating cart trigger, the side-cart panel header, and the
	 * empty-cart state. Kept in sync with the identical
	 * `CART_ICON_PATH_D` constant in assets/js/frontend-cart.js (used when
	 * the empty-cart state is rebuilt client-side after a live cart
	 * refresh) and with the matching markup in Side_Cart_Admin's Floating
	 * Cart preview.
	 *
	 * @var string
	 */
	const BASKET_ICON_PATH_D = 'M7.4 9C7.4 4.7 9.8 2.4 12 2.4C14.2 2.4 16.6 4.7 16.6 9L15.1 9C15.1 5.7 13.4 4 12 4C10.6 4 8.9 5.7 8.9 9Z M3 9h18v2H3V9Z M4.2 11L19.8 11L17.8 20L6.2 20Z M9.5 12.5h1v6h-1v-6Z M13.5 12.5h1v6h-1v-6Z';

	/**
	 * Returns the current number of items in the WooCommerce cart.
	 *
	 * @return int
	 */
	public static function get_cart_item_count() {
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return 0;
		}

		return (int) WC()->cart->get_cart_contents_count();
	}

	/**
	 * Renders the floating cart trigger button, showing the real current
	 * WooCommerce cart item count.
	 *
	 * Phase 3E-1 positions the button according to the saved Floating Cart
	 * "Position", "Horizontal offset", and "Vertical offset" settings: a
	 * `manage-cart-trigger--{position}` modifier class selects which pair
	 * of screen edges the button is anchored to (see assets/css/frontend.css),
	 * and the two offsets are written as inline CSS custom properties so no
	 * additional stylesheet rule is needed per possible pixel value. This
	 * only sets the button's *default* position; the existing draggable
	 * trigger behavior (assets/js/frontend-cart.js) still overrides it with
	 * an explicit left/top once a visitor drags the button, unrelated to
	 * these settings.
	 *
	 * Phase 3E-2 adds two more Floating Cart settings this reads: "Show
	 * item count badge" omits the numeric badge `<span>` entirely when
	 * off, and "Hide floating cart when cart is empty" renders the button
	 * with the `hidden` attribute up front when it is on and the cart
	 * currently has no items, so there is no flash of a visible button
	 * before JavaScript runs. Either way, a `data-manage-cart-item-count`
	 * attribute is always written on the button itself (regardless of
	 * whether the badge is shown) so the frontend script always has the
	 * current count to react to as the cart changes live.
	 *
	 * Phase 3G-3B: also writes the saved Floating Cart → Appearance values
	 * (button background, icon color, count badge background/text colors,
	 * button size, and border radius) as inline CSS custom properties on
	 * the button, alongside the existing offset custom properties. The
	 * stylesheet (assets/css/frontend.css) reads these with a fallback to
	 * its previous hard-coded values, so a request that somehow renders
	 * before Floating_Cart_Settings is available still looks exactly as it
	 * did before this phase. See get_trigger_appearance_style_string().
	 *
	 * @return void
	 */
	public static function render_trigger() {
		$count = self::get_cart_item_count();

		$label = sprintf(
			/* translators: %d: number of items currently in the WooCommerce cart. */
			_n( 'Open cart, %d item', 'Open cart, %d items', $count, 'manage-cart' ),
			$count
		);

		$count_classes = 'manage-cart-trigger-count';
		if ( 0 === $count ) {
			$count_classes .= ' manage-cart-trigger-count--zero';
		}

		$floating_cart_settings = Floating_Cart_Settings::get_settings();

		$position = in_array( $floating_cart_settings['position'], Floating_Cart_Settings::POSITION_CHOICES, true )
			? $floating_cart_settings['position']
			: 'bottom-right';

		$trigger_classes = 'manage-cart-trigger manage-cart-trigger--' . sanitize_html_class( $position );

		$appearance_style = self::get_trigger_appearance_style_string( $floating_cart_settings );

		$trigger_style = sprintf(
			'--manage-cart-trigger-h-offset: %1$dpx; --manage-cart-trigger-v-offset: %2$dpx; %3$s',
			(int) $floating_cart_settings['horizontal_offset'],
			(int) $floating_cart_settings['vertical_offset'],
			$appearance_style
		);

		$show_count_badge = ! empty( $floating_cart_settings['show_item_count_badge'] );
		$hide_when_empty  = ! empty( $floating_cart_settings['hide_when_empty'] );
		$initially_hidden = $hide_when_empty && 0 === $count;
		?>
		<button
			type="button"
			id="<?php echo esc_attr( self::TRIGGER_ID ); ?>"
			class="<?php echo esc_attr( $trigger_classes ); ?>"
			style="<?php echo esc_attr( $trigger_style ); ?>"
			data-manage-cart-position="<?php echo esc_attr( $position ); ?>"
			data-manage-cart-item-count="<?php echo esc_attr( (string) $count ); ?>"
			aria-haspopup="dialog"
			aria-expanded="false"
			aria-controls="<?php echo esc_attr( self::PANEL_ID ); ?>"
			aria-label="<?php echo esc_attr( $label ); ?>"
			<?php echo $initially_hidden ? 'hidden' : ''; ?>
		>
			<span class="manage-cart-trigger-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="24" height="24" focusable="false">
					<path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="<?php echo esc_attr( self::BASKET_ICON_PATH_D ); ?>"/>
				</svg>
			</span>
			<?php if ( $show_count_badge ) : ?>
				<span class="<?php echo esc_attr( $count_classes ); ?>" data-manage-cart-count aria-hidden="true"><?php echo esc_html( (string) $count ); ?></span>
			<?php endif; ?>
		</button>
		<?php
		/**
		 * Fires immediately after the floating trigger button markup.
		 *
		 * Reserved for a future ManageCart Pro add-on — e.g. a menu-cart
		 * icon/badge variant that reuses the same cart item count. Free
		 * ManageCart adds no output here itself.
		 *
		 * @param int $count The current WooCommerce cart item count.
		 */
		do_action( 'manage_cart_after_trigger', $count );
	}

	/**
	 * Renders the overlay and the cart panel (header, real cart contents,
	 * and footer), using the saved Side Cart layout settings to select the
	 * desktop presentation. Mobile presentation is handled entirely in CSS
	 * (always a bottom sheet, see assets/css/frontend.css).
	 *
	 * Phase 3G-2B: also writes the saved Side Cart → Appearance values
	 * (panel/header background, header/body text, checkout button colors,
	 * and border radius) as inline CSS custom properties on the panel
	 * element, alongside the existing `--manage-cart-panel-width`. The
	 * stylesheet (assets/css/frontend.css) reads these with a fallback to
	 * its previous hard-coded values, so a request that somehow renders
	 * before Side_Cart_Settings is available still looks exactly as it
	 * did before this phase.
	 *
	 * @return void
	 */
	public static function render_panel() {
		$side_cart_settings = Side_Cart_Settings::get_settings();

		$layout = 'popup' === $side_cart_settings['layout'] ? 'popup' : 'drawer';
		$drawer_side = 'left' === $side_cart_settings['drawer_side'] ? 'left' : 'right';

		$desktop_width = (int) $side_cart_settings['desktop_width'];
		if ( $desktop_width < Side_Cart_Settings::DESKTOP_WIDTH_MIN || $desktop_width > Side_Cart_Settings::DESKTOP_WIDTH_MAX ) {
			$desktop_width = 420;
		}

		$appearance_style = self::get_appearance_style_string( $side_cart_settings );

		$panel_classes = array(
			'manage-cart-panel',
			'manage-cart-panel--' . $layout,
		);

		if ( 'drawer' === $layout ) {
			$panel_classes[] = 'manage-cart-panel--' . $drawer_side;
		}

		$heading = isset( $side_cart_settings['heading'] ) ? trim( (string) $side_cart_settings['heading'] ) : '';
		if ( '' === $heading ) {
			$heading = __( 'Your cart', 'manage-cart' );
		}

		$show_cart_icon  = ! empty( $side_cart_settings['show_cart_icon'] );
		$show_item_count = ! empty( $side_cart_settings['show_item_count'] );
		$item_count      = self::get_cart_item_count();
		?>
		<div class="manage-cart-overlay" id="<?php echo esc_attr( self::OVERLAY_ID ); ?>" hidden></div>
		<div
			class="<?php echo esc_attr( implode( ' ', $panel_classes ) ); ?>"
			id="<?php echo esc_attr( self::PANEL_ID ); ?>"
			role="dialog"
			aria-modal="true"
			aria-labelledby="<?php echo esc_attr( self::TITLE_ID ); ?>"
			style="--manage-cart-panel-width: <?php echo esc_attr( $desktop_width ); ?>px; <?php echo esc_attr( $appearance_style ); ?>"
			hidden
		>
			<div
				class="manage-cart-status"
				id="<?php echo esc_attr( self::STATUS_ID ); ?>"
				role="status"
				aria-live="polite"
				aria-atomic="true"
			></div>

			<div class="manage-cart-panel-header">
				<h2 class="manage-cart-panel-title" id="<?php echo esc_attr( self::TITLE_ID ); ?>">
					<?php if ( $show_cart_icon ) : ?>
						<span class="manage-cart-panel-title-icon" aria-hidden="true">
							<svg viewBox="0 0 24 24" width="18" height="18" focusable="false">
								<path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="<?php echo esc_attr( self::BASKET_ICON_PATH_D ); ?>"/>
							</svg>
						</span>
					<?php endif; ?>
					<span class="manage-cart-panel-title-text"><?php echo esc_html( $heading ); ?></span>
					<?php if ( $show_item_count ) : ?>
						<span class="manage-cart-panel-title-count">(<?php echo esc_html( (string) $item_count ); ?>)</span>
					<?php endif; ?>
				</h2>
				<button
					type="button"
					class="manage-cart-panel-close"
					id="<?php echo esc_attr( self::CLOSE_BUTTON_ID ); ?>"
					aria-label="<?php esc_attr_e( 'Close cart', 'manage-cart' ); ?>"
				>
					<svg viewBox="0 0 24 24" width="18" height="18" focusable="false" aria-hidden="true">
						<path fill="currentColor" d="M18.3 5.71 12 12l6.3 6.29-1.41 1.42L10.59 13.4 4.3 19.71 2.89 18.3 9.17 12 2.89 5.71 4.3 4.29l6.29 6.3 6.29-6.3z"/>
					</svg>
				</button>
			</div>

			<?php self::render_undo_notice(); ?>

			<div class="manage-cart-panel-body">
				<?php
				/**
				 * Fires inside the cart panel body, before the cart contents
				 * (items list or empty state) are rendered.
				 *
				 * Reserved for a future ManageCart Pro add-on — e.g. a
				 * free-shipping progress bar shown above the line items. Free
				 * ManageCart adds no output here itself.
				 *
				 * @param array $side_cart_settings The current Side Cart settings.
				 */
				do_action( 'manage_cart_panel_body_start', $side_cart_settings );

				self::render_cart_contents();

				/**
				 * Fires inside the cart panel body, after the cart contents
				 * (items list or empty state) are rendered.
				 *
				 * Reserved for a future ManageCart Pro add-on — e.g. cart
				 * upsells/cross-sells ("You may also like"). Free ManageCart
				 * adds no output here itself.
				 *
				 * @param array $side_cart_settings The current Side Cart settings.
				 */
				do_action( 'manage_cart_panel_body_end', $side_cart_settings );
				?>
			</div>

			<?php self::render_footer(); ?>
		</div>
		<?php
	}

	/**
	 * Renders the (hidden by default) compact "Product removed" Undo
	 * notice banner (Free — Undo Removed Cart Item, Part 1), shown
	 * briefly after a successful item removal from the real Side Cart.
	 *
	 * Rendered as a direct child of the panel — a sibling of the header,
	 * body, and footer — and never inside `.manage-cart-panel-body`, so
	 * it is never touched, duplicated, or discarded by the item-list/
	 * empty-state body rebuilds in assets/js/frontend-cart.js
	 * (applyPanelRefresh()/showEmptyCartState()), and stays visible
	 * across an empty-cart <-> items-state swap exactly as required. It
	 * is also deliberately separate from the Pro Cart Note field
	 * rendered via the `manage_cart_before_panel_actions` action inside
	 * render_footer(): different markup, different id/classes, and a
	 * different place in the panel entirely, so the two can never be
	 * confused or collide.
	 *
	 * The text and button here are static, translated markup only; all
	 * show/hide/6-second-timer/restore behavior lives in
	 * assets/js/frontend-cart.js (offerUndo()/hideUndoNotice()/
	 * handleUndoClick()). The decorative countdown bar carries
	 * `aria-hidden="true"` since it conveys nothing beyond what the
	 * timer already does; removal/restoration feedback is announced
	 * separately via the existing `self::STATUS_ID` aria-live region
	 * above, so this banner has no aria-live of its own and nothing is
	 * ever announced twice.
	 *
	 * The Undo countdown (Free — small compact seconds-remaining text,
	 * e.g. "Undo (6)") adds one more static child here: an always-
	 * present, empty-by-default `data-manage-cart-undo-countdown` span
	 * right after the "Undo" label, inside the same button. It is
	 * `aria-hidden="true"` — a decorative numeral alongside the visual
	 * progress bar below, matching the same reasoning — because the
	 * button's own accessible name always comes from the explicit
	 * `aria-label` the script sets on click-target changes, never from
	 * this text, so ticking digits can never be read out by a screen
	 * reader once per second. The "Show Undo countdown" Side Cart
	 * setting controls whether the script ever writes text into this
	 * span at all (see frontend-cart.js's `showUndoCountdown`); when the
	 * setting is off the span stays empty and the notice looks exactly
	 * as it did before this feature. The one-time, polite "seconds to
	 * undo" announcement this feature adds when the setting is on goes
	 * through the existing `self::STATUS_ID` region instead, alongside
	 * the existing removal announcement, not through this span.
	 *
	 * @return void
	 */
	protected static function render_undo_notice() {
		?>
		<div
			class="manage-cart-undo-notice"
			id="<?php echo esc_attr( self::UNDO_NOTICE_ID ); ?>"
			data-manage-cart-undo-notice
			hidden
		>
			<span class="manage-cart-undo-notice-text" data-manage-cart-undo-text></span>
			<button
				type="button"
				class="manage-cart-undo-notice-btn"
				id="<?php echo esc_attr( self::UNDO_BUTTON_ID ); ?>"
				data-manage-cart-undo-button
			><?php esc_html_e( 'Undo', 'manage-cart' ); ?><span class="manage-cart-undo-notice-countdown" data-manage-cart-undo-countdown aria-hidden="true"></span></button>
			<span class="manage-cart-undo-notice-bar" aria-hidden="true"></span>
		</div>
		<?php
	}

	/**
	 * Builds the inline `--manage-cart-*` CSS custom property declarations
	 * for the Side Cart → Appearance values (Phase 3G-2B), from an
	 * already-merged Side_Cart_Settings::get_settings() array.
	 *
	 * Every color is re-validated with `sanitize_hex_color()` and the
	 * radius re-clamped to Side_Cart_Settings' own min/max here as a
	 * defensive second check, exactly like the existing desktop-width
	 * clamp just above in render_panel() — the values are already
	 * sanitized at save time, but this keeps rendering safe even if the
	 * stored option were ever edited or seeded outside that path.
	 *
	 * Public (not just internal to render_panel()) so Side_Cart_Admin's
	 * admin-only preview card (render_preview()) can reuse the exact same
	 * mapping and clamping logic for its own inline style, keeping the
	 * mock preview and the real frontend panel in sync from one place.
	 *
	 * @param array $side_cart_settings Side Cart settings, as returned by
	 *                                   Side_Cart_Settings::get_settings().
	 * @return string A string of `--custom-property: value;` declarations,
	 *                 safe to place inside an already-quoted style
	 *                 attribute (still passed through esc_attr() by the
	 *                 caller).
	 */
	public static function get_appearance_style_string( array $side_cart_settings ) {
		$defaults = Side_Cart_Settings::get_defaults();

		$color_keys = array(
			'panel_bg_color'             => '--manage-cart-panel-bg-color',
			'header_bg_color'            => '--manage-cart-header-bg-color',
			'header_text_color'         => '--manage-cart-header-text-color',
			'body_text_color'           => '--manage-cart-body-text-color',
			'checkout_button_color'     => '--manage-cart-checkout-btn-bg-color',
			'checkout_button_text_color' => '--manage-cart-checkout-btn-text-color',
		);

		$declarations = array();

		foreach ( $color_keys as $settings_key => $css_var ) {
			$value = isset( $side_cart_settings[ $settings_key ] ) ? $side_cart_settings[ $settings_key ] : $defaults[ $settings_key ];
			$value = is_string( $value ) ? sanitize_hex_color( $value ) : '';
			if ( empty( $value ) ) {
				$value = $defaults[ $settings_key ];
			}
			$declarations[] = $css_var . ': ' . $value . ';';
		}

		$border_radius = isset( $side_cart_settings['border_radius'] ) ? absint( $side_cart_settings['border_radius'] ) : $defaults['border_radius'];
		$border_radius = max( Side_Cart_Settings::BORDER_RADIUS_MIN, min( Side_Cart_Settings::BORDER_RADIUS_MAX, $border_radius ) );
		$declarations[] = '--manage-cart-panel-radius: ' . $border_radius . 'px;';

		$style_string = implode( ' ', $declarations );

		/**
		 * Filters the inline CSS custom property declarations written on the
		 * cart panel element.
		 *
		 * Reserved for a future ManageCart Pro add-on's advanced styling
		 * options (e.g. custom fonts, gradients, shadows) to append
		 * additional `--manage-cart-*` declarations without re-implementing
		 * this method. Values returned here are placed inside an
		 * already-`esc_attr()`-ed style attribute by the caller, so any
		 * filter must return safe, pre-escaped CSS declarations.
		 *
		 * @param string $style_string        The built declarations string.
		 * @param array  $side_cart_settings  The current Side Cart settings.
		 */
		return apply_filters( 'manage_cart_panel_appearance_style', $style_string, $side_cart_settings );
	}

	/**
	 * Builds the inline `--manage-cart-trigger-*` CSS custom property
	 * declarations for the Floating Cart → Appearance values (Phase 3G-3B),
	 * from an already-merged Floating_Cart_Settings::get_settings() array.
	 *
	 * Every color is re-validated with `sanitize_hex_color()` and the size
	 * and radius re-clamped to Floating_Cart_Settings' own min/max here as
	 * a defensive second check, exactly like get_appearance_style_string()
	 * does for the Side Cart panel — the values are already sanitized at
	 * save time, but this keeps rendering safe even if the stored option
	 * were ever edited or seeded outside that path.
	 *
	 * Public (not just internal to render_trigger()) so Side_Cart_Admin's
	 * admin-only Floating Cart Appearance preview can reuse the exact same
	 * mapping and clamping logic for its own inline style, keeping the
	 * mock preview and the real frontend trigger button in sync from one
	 * place.
	 *
	 * @param array $floating_cart_settings Floating Cart settings, as
	 *                                       returned by
	 *                                       Floating_Cart_Settings::get_settings().
	 * @return string A string of `--custom-property: value;` declarations,
	 *                 safe to place inside an already-quoted style
	 *                 attribute (still passed through esc_attr() by the
	 *                 caller).
	 */
	public static function get_trigger_appearance_style_string( array $floating_cart_settings ) {
		$defaults = Floating_Cart_Settings::get_defaults();

		$color_keys = array(
			'button_bg_color'  => '--manage-cart-trigger-bg-color',
			'icon_color'       => '--manage-cart-trigger-icon-color',
			'badge_bg_color'   => '--manage-cart-trigger-badge-bg-color',
			'badge_text_color' => '--manage-cart-trigger-badge-text-color',
		);

		$declarations = array();

		foreach ( $color_keys as $settings_key => $css_var ) {
			$value = isset( $floating_cart_settings[ $settings_key ] ) ? $floating_cart_settings[ $settings_key ] : $defaults[ $settings_key ];
			$value = is_string( $value ) ? sanitize_hex_color( $value ) : '';
			if ( empty( $value ) ) {
				$value = $defaults[ $settings_key ];
			}
			$declarations[] = $css_var . ': ' . $value . ';';
		}

		$button_size = isset( $floating_cart_settings['button_size'] ) ? absint( $floating_cart_settings['button_size'] ) : $defaults['button_size'];
		$button_size = max( Floating_Cart_Settings::BUTTON_SIZE_MIN, min( Floating_Cart_Settings::BUTTON_SIZE_MAX, $button_size ) );
		$declarations[] = '--manage-cart-trigger-size: ' . $button_size . 'px;';

		$border_radius = isset( $floating_cart_settings['border_radius'] ) ? absint( $floating_cart_settings['border_radius'] ) : $defaults['border_radius'];
		$border_radius = max( Floating_Cart_Settings::BORDER_RADIUS_MIN, min( Floating_Cart_Settings::BORDER_RADIUS_MAX, $border_radius ) );
		$declarations[] = '--manage-cart-trigger-radius: ' . $border_radius . '%;';

		$style_string = implode( ' ', $declarations );

		/**
		 * Filters the inline CSS custom property declarations written on the
		 * floating trigger button element.
		 *
		 * Reserved for a future ManageCart Pro add-on's advanced styling
		 * options to append additional `--manage-cart-trigger-*`
		 * declarations. Values returned here are placed inside an
		 * already-`esc_attr()`-ed style attribute by the caller, so any
		 * filter must return safe, pre-escaped CSS declarations.
		 *
		 * @param string $style_string           The built declarations string.
		 * @param array  $floating_cart_settings  The current Floating Cart settings.
		 */
		return apply_filters( 'manage_cart_trigger_appearance_style', $style_string, $floating_cart_settings );
	}

	/**
	 * Renders the panel footer (subtotal + Continue Shopping/View Cart/
	 * Checkout), or nothing at all when the cart has no items — matching
	 * the empty-cart state, which omits the footer entirely. Extracted
	 * from render_panel() so the exact same markup can also be captured as
	 * an HTML string by get_panel_footer_html() for the auto-open-on-add
	 * refresh endpoint (Phase 3C), with no duplicated markup between the
	 * two call sites.
	 *
	 * Adds a secondary "Continue Shopping" button next to "View Cart",
	 * linking to the real WooCommerce Shop page URL
	 * (`wc_get_page_permalink( 'shop' )`). It is grouped with "View Cart"
	 * in its own row above the full-width "Checkout" button so the row
	 * never has to squeeze three buttons onto one line; the two secondary
	 * buttons wrap onto their own line individually
	 * (see .manage-cart-panel-actions-row in assets/css/frontend.css) if
	 * the panel is ever too narrow to fit both comfortably. When no Shop
	 * page is configured (`wc_get_page_permalink( 'shop' )` returns
	 * nothing), the Continue Shopping button is omitted entirely rather
	 * than rendered with an empty/invalid href, leaving "View Cart" alone
	 * in its row. Checkout remains the sole primary-styled button.
	 *
	 * @return void
	 */
	protected static function render_footer() {
		if ( ! function_exists( 'WC' ) || null === WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		$shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
		?>
		<div class="manage-cart-panel-footer">
			<?php
			/**
			 * Fires at the top of the cart panel footer, before the coupon
			 * section, savings row, and subtotal.
			 *
			 * Reserved for a future ManageCart Pro add-on — e.g. a
			 * free-shipping progress bar shown in the sticky footer. Free
			 * ManageCart adds no output here itself.
			 *
			 * @param \WC_Cart $cart The WooCommerce cart instance.
			 */
			do_action( 'manage_cart_footer_top', WC()->cart );
			?>
			<?php self::render_coupon_section(); ?>
			<?php self::render_savings_row( WC()->cart ); ?>
			<div class="manage-cart-subtotal-row">
				<span class="manage-cart-subtotal-label"><?php esc_html_e( 'Subtotal', 'manage-cart' ); ?></span>
				<span class="manage-cart-subtotal-value"><?php echo wp_kses_post( WC()->cart->get_cart_subtotal() ); ?></span>
			</div>
			<?php self::render_coupon_discount_row( WC()->cart ); ?>
			<?php self::render_total_row( WC()->cart ); ?>
			<?php
			/**
			 * Fires in the cart panel footer, after the subtotal row and
			 * before the Continue Shopping/View Cart/Checkout buttons.
			 *
			 * Reserved for a future ManageCart Pro add-on — e.g. a custom
			 * cart notes field. Free ManageCart adds no output here itself.
			 *
			 * @param \WC_Cart $cart The WooCommerce cart instance.
			 */
			do_action( 'manage_cart_before_panel_actions', WC()->cart );
			?>
			<div class="manage-cart-panel-actions">
				<div class="manage-cart-panel-actions-row">
					<?php if ( $shop_url ) : ?>
						<a class="manage-cart-btn manage-cart-btn--secondary" href="<?php echo esc_url( $shop_url ); ?>">
							<?php esc_html_e( 'Continue Shopping', 'manage-cart' ); ?>
						</a>
					<?php endif; ?>
					<a class="manage-cart-btn manage-cart-btn--secondary" href="<?php echo esc_url( wc_get_cart_url() ); ?>">
						<?php esc_html_e( 'View Cart', 'manage-cart' ); ?>
					</a>
				</div>
				<a class="manage-cart-btn manage-cart-btn--primary" href="<?php echo esc_url( wc_get_checkout_url() ); ?>">
					<?php esc_html_e( 'Checkout', 'manage-cart' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the "Have a coupon?" toggle and coupon code form shown above
	 * the Subtotal row (Phase 3F-1), plus — as of Phase 3H-1 — the list of
	 * currently applied coupons (code, discount amount, and an accessible
	 * Remove button per coupon), shown above that same toggle so an
	 * applied coupon's effect is visible before the customer ever opens
	 * the "Have a coupon?" form again. Renders nothing at all when
	 * WooCommerce coupons are disabled store-wide (`wc_coupons_enabled()`),
	 * so this section never appears in that case — no new admin setting is
	 * introduced to control it separately.
	 *
	 * The form posts nowhere on its own; the frontend script intercepts
	 * its submit event and sends the code to WooCommerce's own
	 * `POST /wc/store/v1/cart/apply-coupon` Store API route via
	 * window.fetch, exactly like the existing quantity/remove controls do
	 * for their own Store API routes. Likewise, each applied coupon's
	 * Remove button is intercepted client-side and sent to WooCommerce's
	 * own `POST /wc/store/v1/cart/remove-coupon` Store API route — see
	 * render_applied_coupons() and assets/js/frontend-cart.js. This method
	 * only ever renders the closed (collapsed) initial state; the
	 * toggle's expanded/collapsed state afterward is handled entirely
	 * client-side.
	 *
	 * @return void
	 */
	protected static function render_coupon_section() {
		if ( ! function_exists( 'wc_coupons_enabled' ) || ! wc_coupons_enabled() ) {
			return;
		}
		?>
		<div class="manage-cart-coupon" data-manage-cart-coupon>
			<?php self::render_applied_coupons(); ?>
			<button
				type="button"
				class="manage-cart-coupon-toggle"
				id="<?php echo esc_attr( self::COUPON_TOGGLE_ID ); ?>"
				data-manage-cart-coupon-toggle
				aria-expanded="false"
				aria-controls="<?php echo esc_attr( self::COUPON_FORM_ID ); ?>"
			>
				<?php esc_html_e( 'Have a coupon?', 'manage-cart' ); ?>
			</button>
			<form
				class="manage-cart-coupon-form"
				id="<?php echo esc_attr( self::COUPON_FORM_ID ); ?>"
				data-manage-cart-coupon-form
				hidden
			>
				<label class="manage-cart-visually-hidden" for="<?php echo esc_attr( self::COUPON_INPUT_ID ); ?>">
					<?php esc_html_e( 'Coupon code', 'manage-cart' ); ?>
				</label>
				<input
					type="text"
					id="<?php echo esc_attr( self::COUPON_INPUT_ID ); ?>"
					class="manage-cart-coupon-input"
					data-manage-cart-coupon-input
					autocomplete="off"
					autocapitalize="characters"
					spellcheck="false"
				/>
				<button
					type="submit"
					class="manage-cart-btn manage-cart-btn--secondary manage-cart-coupon-apply"
					data-manage-cart-coupon-apply
				>
					<?php esc_html_e( 'Apply', 'manage-cart' ); ?>
				</button>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the list of currently applied coupons — code, discount
	 * amount, and an accessible Remove button per coupon — or nothing at
	 * all when no coupon is applied (Phase 3H-1). Called by
	 * render_coupon_section() above the "Have a coupon?" toggle, so it
	 * always sits above the Subtotal row along with the rest of the
	 * coupon section.
	 *
	 * The discount amount uses the same
	 * `WC()->cart->get_coupon_discount_amount()` /
	 * `WC()->cart->display_prices_including_tax()` calculation and
	 * `wc_price()` formatting WooCommerce core's own cart-totals template
	 * uses for this figure, so it always matches what the full Cart page
	 * shows for the same coupon. Each Remove button is disabled/enabled
	 * and intercepted entirely client-side (see
	 * assets/js/frontend-cart.js): submitting it sends the coupon's code
	 * to WooCommerce's own `POST /wc/store/v1/cart/remove-coupon` Store
	 * API route, the same nonce-bearing window.fetch pattern already used
	 * for apply-coupon/update-item/remove-item, and rebuilds this same
	 * list — along with the rest of the footer — from that one real
	 * response. No custom PHP AJAX handler or REST route is involved, and
	 * this method itself performs no mutation; it only reads the current
	 * cart's already-applied coupons.
	 *
	 * @return void
	 */
	protected static function render_applied_coupons() {
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return;
		}

		$applied_coupons = WC()->cart->get_applied_coupons();

		if ( empty( $applied_coupons ) ) {
			return;
		}
		?>
		<ul class="manage-cart-applied-coupons" id="<?php echo esc_attr( self::COUPON_LIST_ID ); ?>" data-manage-cart-coupon-list>
			<?php foreach ( $applied_coupons as $code ) : ?>
				<?php
				$discount_amount = WC()->cart->get_coupon_discount_amount( $code, WC()->cart->display_prices_including_tax() );
				?>
				<li class="manage-cart-applied-coupon" data-coupon-code="<?php echo esc_attr( $code ); ?>">
					<span class="manage-cart-applied-coupon-code"><?php echo esc_html( strtoupper( $code ) ); ?></span>
					<span class="manage-cart-applied-coupon-amount">&minus;<?php echo wp_kses_post( wc_price( $discount_amount ) ); ?></span>
					<button
						type="button"
						class="manage-cart-applied-coupon-remove"
						data-manage-cart-coupon-remove
						data-coupon-code="<?php echo esc_attr( $code ); ?>"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s: coupon code. */ __( 'Remove coupon %s', 'manage-cart' ), strtoupper( $code ) ) ); ?>"
					>
						<?php esc_html_e( 'Remove', 'manage-cart' ); ?>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Calculates the cart's total savings — the sum of
	 * (display regular price − display sale price) × quantity for every
	 * cart line whose product has a real, current WooCommerce sale
	 * discount. Mirrors the same eligibility used by
	 * render_item_sale_info() for a single line (a real product-level
	 * discount via `is_on_sale()` plus a positive regular price greater
	 * than the sale price), except this total does not additionally
	 * require the per-line percentage to round to at least 1%, since a
	 * fractional per-line percentage can still add up to a meaningful
	 * total across multiple lines/quantities.
	 *
	 * Both prices are read through `wc_get_price_to_display()` — the same
	 * helper `render_item_sale_info()` uses for the struck-through
	 * regular price — so the figure already matches the store's tax
	 * display setting the same way the Subtotal row does.
	 *
	 * @param \WC_Cart|null $cart The WooCommerce cart instance.
	 * @return float Total savings, in the store's display currency. Zero when there is none.
	 */
	protected static function get_cart_total_savings( $cart ) {
		if ( ! $cart instanceof \WC_Cart ) {
			return 0.0;
		}

		$total_savings = 0.0;

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;

			if ( ! $product instanceof \WC_Product || ! $product->is_on_sale() ) {
				continue;
			}

			$quantity = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
			if ( $quantity <= 0 ) {
				continue;
			}

			$regular_price = (float) $product->get_regular_price();
			$sale_price    = (float) $product->get_sale_price();

			if ( $regular_price <= 0 || $sale_price < 0 || $sale_price >= $regular_price ) {
				continue;
			}

			$display_regular_price = (float) wc_get_price_to_display( $product, array( 'price' => $regular_price ) );
			$display_sale_price    = (float) wc_get_price_to_display( $product, array( 'price' => $sale_price ) );

			if ( $display_sale_price >= $display_regular_price ) {
				continue;
			}

			$total_savings += ( $display_regular_price - $display_sale_price ) * $quantity;
		}

		return $total_savings;
	}

	/**
	 * Renders the "Product savings" total savings row shown in the panel
	 * footer totals block, or nothing at all when the cart's total savings
	 * (see get_cart_total_savings()) is zero — e.g. no cart line currently
	 * has a real sale discount. This is purely a read-only summary: it
	 * does not change how the Subtotal itself, or any line item's own
	 * price/subtotal, is calculated or displayed.
	 *
	 * Named "Product savings" (rather than "You save") so it reads
	 * unambiguously as the sum of product sale-price discounts, now that
	 * the footer also shows a separate "Coupon discount" row for the
	 * total effect of any applied coupon(s) — see
	 * render_coupon_discount_row().
	 *
	 * @param \WC_Cart|null $cart The WooCommerce cart instance.
	 * @return void
	 */
	protected static function render_savings_row( $cart ) {
		$total_savings = self::get_cart_total_savings( $cart );

		if ( $total_savings <= 0.0 ) {
			return;
		}
		?>
		<div class="manage-cart-savings-row">
			<span class="manage-cart-savings-label"><?php esc_html_e( 'Product savings', 'manage-cart' ); ?></span>
			<span class="manage-cart-savings-value"><?php echo wp_kses_post( wc_price( $total_savings ) ); ?></span>
		</div>
		<?php
	}

	/**
	 * Calculates the cart's total coupon discount across every currently
	 * applied coupon, for the aggregate "Coupon discount" row shown in the
	 * panel footer totals block (see render_coupon_discount_row()). Sums
	 * `WC_Cart::get_coupon_discount_amount( $code, ... )` for each applied
	 * coupon — the exact same real WooCommerce API call, with the exact
	 * same tax-inclusive/exclusive argument, that render_applied_coupons()
	 * already uses to show each coupon's own per-coupon amount, so the sum
	 * always agrees with what that per-coupon list shows and never parses
	 * any formatted price string.
	 *
	 * Passing `$cart->display_prices_including_tax()` as the second
	 * argument is what makes this respect the store's tax display
	 * setting: when prices are displayed inclusive of tax, each coupon's
	 * discount tax portion is folded into the returned amount, exactly
	 * mirroring how the Subtotal row's own figure is tax-inclusive under
	 * that same setting.
	 *
	 * @param \WC_Cart|null $cart The WooCommerce cart instance.
	 * @return float Total coupon discount, in the store's display currency. Zero when no coupon is applied.
	 */
	protected static function get_cart_coupon_discount_total( $cart ) {
		if ( ! $cart instanceof \WC_Cart ) {
			return 0.0;
		}

		$applied_coupons = $cart->get_applied_coupons();

		if ( empty( $applied_coupons ) ) {
			return 0.0;
		}

		$total_discount = 0.0;

		foreach ( $applied_coupons as $code ) {
			$total_discount += (float) $cart->get_coupon_discount_amount( $code, $cart->display_prices_including_tax() );
		}

		return $total_discount;
	}

	/**
	 * Renders the "Coupon discount" row shown in the panel footer totals
	 * block, below Subtotal, or nothing at all when no coupon is currently
	 * applied (or the total resolves to zero). This is a separate,
	 * aggregate summary from the per-coupon code/amount/Remove list
	 * rendered by render_applied_coupons() above the "Have a coupon?"
	 * toggle — that list is unchanged; this row exists purely to make the
	 * combined discount visible right next to Subtotal and Total.
	 *
	 * @param \WC_Cart|null $cart The WooCommerce cart instance.
	 * @return void
	 */
	protected static function render_coupon_discount_row( $cart ) {
		$total_discount = self::get_cart_coupon_discount_total( $cart );

		if ( $total_discount <= 0.0 ) {
			return;
		}
		?>
		<div class="manage-cart-coupon-discount-row">
			<span class="manage-cart-coupon-discount-label"><?php esc_html_e( 'Coupon discount', 'manage-cart' ); ?></span>
			<span class="manage-cart-coupon-discount-value">&minus;<?php echo wp_kses_post( wc_price( $total_discount ) ); ?></span>
		</div>
		<?php
	}

	/**
	 * Renders the prominent "Total" row shown at the bottom of the panel
	 * footer totals block, below Coupon discount. Always shown whenever
	 * the footer itself is shown (i.e. whenever the cart has items —
	 * render_footer() already guards that), regardless of whether there
	 * are any savings or coupons to show above it.
	 *
	 * Uses `WC_Cart::get_total()` in its default ('view') context, which
	 * returns WooCommerce's own real final cart total — after discounts,
	 * tax, fees, and calculated shipping — already formatted with
	 * `wc_price()` and passed through the same `woocommerce_cart_total`
	 * filter WooCommerce core's own Cart and Checkout pages use for this
	 * exact figure. Nothing here recomputes or parses any total itself.
	 *
	 * @param \WC_Cart|null $cart The WooCommerce cart instance.
	 * @return void
	 */
	protected static function render_total_row( $cart ) {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		?>
		<div class="manage-cart-total-row">
			<span class="manage-cart-total-label"><?php esc_html_e( 'Total', 'manage-cart' ); ?></span>
			<span class="manage-cart-total-value"><?php echo wp_kses_post( $cart->get_total() ); ?></span>
		</div>
		<?php
	}

	/**
	 * Renders the real current WooCommerce cart contents, or the empty-cart
	 * state when the cart has no items.
	 *
	 * @return void
	 */
	protected static function render_cart_contents() {
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			self::render_empty_state();
			return;
		}

		$cart = WC()->cart;

		if ( $cart->is_empty() ) {
			self::render_empty_state();
			return;
		}

		$side_cart_settings         = Side_Cart_Settings::get_settings();
		$show_product_image        = ! empty( $side_cart_settings['show_product_image'] );
		$show_variation_attributes = ! empty( $side_cart_settings['show_variation_attributes'] );
		$show_quantity_controls    = ! empty( $side_cart_settings['show_quantity_controls'] );
		$show_remove_button        = ! empty( $side_cart_settings['show_remove_button'] );
		$show_low_stock_badge      = ! empty( $side_cart_settings['show_low_stock_badge'] );
		$low_stock_threshold       = isset( $side_cart_settings['low_stock_threshold'] ) ? (int) $side_cart_settings['low_stock_threshold'] : 5;
		?>
		<ul class="manage-cart-items">
			<?php foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) : ?>
				<?php self::render_cart_item( $cart, $cart_item_key, $cart_item, $show_product_image, $show_variation_attributes, $show_quantity_controls, $show_remove_button, $show_low_stock_badge, $low_stock_threshold ); ?>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Renders a single cart line item: thumbnail (if enabled), name/link,
	 * variation attributes (if any and enabled), quantity (editable
	 * stepper or read-only text), line subtotal, and remove button (if
	 * enabled).
	 *
	 * @param \WC_Cart $cart                       The WooCommerce cart instance.
	 * @param string   $cart_item_key              The cart item key.
	 * @param array    $cart_item                  The cart item data.
	 * @param bool     $show_product_image         Whether to show the product thumbnail (Side Cart setting).
	 * @param bool     $show_variation_attributes  Whether to show variation attributes (Side Cart setting).
	 * @param bool     $show_quantity_controls     Whether to show editable quantity controls (Side Cart setting).
	 * @param bool     $show_remove_button         Whether to show the remove button (Side Cart setting).
	 * @param bool     $show_low_stock_badge       Whether to show the "Only X left" low stock badge (Side Cart setting).
	 * @param int      $low_stock_threshold        Stock level at or below which the low stock badge appears (Side Cart setting).
	 * @return void
	 */
	protected static function render_cart_item( $cart, $cart_item_key, $cart_item, $show_product_image, $show_variation_attributes, $show_quantity_controls = false, $show_remove_button = false, $show_low_stock_badge = false, $low_stock_threshold = 5 ) {
		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;

		if ( ! $product instanceof \WC_Product || ! $product->exists() || $cart_item['quantity'] <= 0 ) {
			return;
		}

		$permalink = apply_filters(
			'woocommerce_cart_item_permalink',
			$product->is_visible( $cart_item_key ) ? $product->get_permalink( $cart_item ) : '',
			$cart_item,
			$cart_item_key
		);

		$name     = apply_filters( 'woocommerce_cart_item_name', $product->get_name(), $cart_item, $cart_item_key );
		$subtotal = apply_filters( 'woocommerce_cart_item_subtotal', $cart->get_product_subtotal( $product, $cart_item['quantity'] ), $cart_item, $cart_item_key );

		$thumbnail = '';
		if ( $show_product_image ) {
			$thumbnail = apply_filters( 'woocommerce_cart_item_thumbnail', $product->get_image( 'thumbnail' ), $cart_item, $cart_item_key );
		}

		$attribute_lines = array();
		if ( $show_variation_attributes && $product instanceof \WC_Product_Variation && method_exists( $product, 'get_attribute_summary' ) ) {
			$summary = $product->get_attribute_summary();
			if ( '' !== $summary ) {
				foreach ( explode( "\n", $summary ) as $line ) {
					$line = trim( $line );
					if ( '' !== $line ) {
						$attribute_lines[] = $line;
					}
				}
			}
		}

		// Quantity controls are only offered for items where changing the
		// quantity in place actually makes sense; items sold individually
		// are fixed at a quantity of 1 and stay read-only regardless of
		// the Side Cart setting.
		$is_editable_quantity = $show_quantity_controls && ! $product->is_sold_individually();

		$max_quantity = -1;
		if ( method_exists( $product, 'get_max_purchase_quantity' ) ) {
			$max_quantity = (int) $product->get_max_purchase_quantity();
		}
		?>
		<li class="manage-cart-item" data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>">
			<?php if ( $show_remove_button || $show_product_image ) : ?>
				<div class="manage-cart-item-top">
					<?php if ( $show_remove_button ) : ?>
						<?php
						$remove_label = sprintf(
							/* translators: %s: product name. */
							__( 'Remove %s', 'manage-cart' ),
							wp_strip_all_tags( $name )
						);
						?>
						<button
							type="button"
							class="manage-cart-item-remove"
							data-manage-cart-remove
							data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"
							aria-label="<?php echo esc_attr( $remove_label ); ?>"
							title="<?php echo esc_attr( $remove_label ); ?>"
						>
							<svg viewBox="0 0 24 24" width="16" height="16" focusable="false" aria-hidden="true">
								<path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="<?php echo esc_attr( self::REMOVE_ICON_PATH_D ); ?>"/>
							</svg>
						</button>
					<?php endif; ?>
					<?php if ( $show_product_image ) : ?>
						<div class="manage-cart-item-thumb">
							<?php if ( $permalink ) : ?>
								<a href="<?php echo esc_url( $permalink ); ?>" tabindex="-1" aria-hidden="true"><?php echo wp_kses_post( $thumbnail ); ?></a>
							<?php else : ?>
								<?php echo wp_kses_post( $thumbnail ); ?>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<div class="manage-cart-item-details">
				<div class="manage-cart-item-name">
					<?php if ( $permalink ) : ?>
						<a href="<?php echo esc_url( $permalink ); ?>"><?php echo wp_kses_post( $name ); ?></a>
					<?php else : ?>
						<span><?php echo wp_kses_post( $name ); ?></span>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $attribute_lines ) ) : ?>
					<div class="manage-cart-item-attrs">
						<?php foreach ( $attribute_lines as $attribute_line ) : ?>
							<span class="manage-cart-item-attr"><?php echo esc_html( $attribute_line ); ?></span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php self::render_item_sale_info( $product ); ?>

				<div class="manage-cart-item-meta">
					<?php if ( $is_editable_quantity ) : ?>
						<div class="manage-cart-qty-stepper" role="group" aria-label="<?php echo esc_attr( sprintf(
							/* translators: %s: product name. */
							__( 'Quantity for %s', 'manage-cart' ),
							wp_strip_all_tags( $name )
						) ); ?>">
							<button
								type="button"
								class="manage-cart-qty-btn manage-cart-qty-btn--decrease"
								data-manage-cart-qty-decrease
								data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"
								aria-label="<?php echo esc_attr( sprintf(
									/* translators: %s: product name. */
									__( 'Decrease quantity of %s', 'manage-cart' ),
									wp_strip_all_tags( $name )
								) ); ?>"
							>
								<svg viewBox="0 0 24 24" width="14" height="14" focusable="false" aria-hidden="true">
									<path fill="currentColor" d="M5 11h14v2H5z"/>
								</svg>
							</button>
							<label class="manage-cart-visually-hidden" for="manage-cart-qty-<?php echo esc_attr( $cart_item_key ); ?>">
								<?php echo esc_html( sprintf(
									/* translators: %s: product name. */
									__( 'Quantity of %s', 'manage-cart' ),
									wp_strip_all_tags( $name )
								) ); ?>
							</label>
							<input
								type="number"
								inputmode="numeric"
								id="manage-cart-qty-<?php echo esc_attr( $cart_item_key ); ?>"
								class="manage-cart-qty-input"
								data-manage-cart-qty-input
								data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"
								value="<?php echo esc_attr( (int) $cart_item['quantity'] ); ?>"
								min="0"
								<?php if ( $max_quantity > 0 ) : ?>
									max="<?php echo esc_attr( $max_quantity ); ?>"
								<?php endif; ?>
								step="1"
							/>
							<button
								type="button"
								class="manage-cart-qty-btn manage-cart-qty-btn--increase"
								data-manage-cart-qty-increase
								data-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>"
								aria-label="<?php echo esc_attr( sprintf(
									/* translators: %s: product name. */
									__( 'Increase quantity of %s', 'manage-cart' ),
									wp_strip_all_tags( $name )
								) ); ?>"
							>
								<svg viewBox="0 0 24 24" width="14" height="14" focusable="false" aria-hidden="true">
									<path fill="currentColor" d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z"/>
								</svg>
							</button>
						</div>
					<?php else : ?>
						<span class="manage-cart-item-qty">
							<?php
							printf(
								/* translators: %d: quantity of this line item in the cart. */
								esc_html__( 'Qty: %d', 'manage-cart' ),
								(int) $cart_item['quantity']
							);
							?>
						</span>
					<?php endif; ?>

					<span class="manage-cart-item-meta-end">
						<span class="manage-cart-item-subtotal"><?php echo wp_kses_post( $subtotal ); ?></span>
					</span>
				</div>

				<?php self::render_low_stock_badge( $product, $show_low_stock_badge, $low_stock_threshold ); ?>
			</div>
			<?php
			/**
			 * Fires after a single cart line item's markup, still inside
			 * its `<li>` element.
			 *
			 * Reserved for a future ManageCart Pro add-on — e.g. a
			 * "frequently bought together" suggestion tied to this item.
			 * Free ManageCart adds no output here itself.
			 *
			 * @param string      $cart_item_key The cart item key.
			 * @param array       $cart_item     The cart item data.
			 * @param \WC_Product $product       The line item's product.
			 */
			do_action( 'manage_cart_after_cart_item', $cart_item_key, $cart_item, $product );
			?>
		</li>
		<?php
	}

	/**
	 * Renders the sale-savings display for a single cart line item's
	 * product: the regular unit price with a strikethrough, plus a small
	 * "Save X%" badge, computed from the product's regular vs. sale
	 * price. Renders nothing at all — not even an empty wrapper — unless
	 * the product actually has a current, real discount: `is_on_sale()`
	 * must be true (which already accounts for sale scheduling), the
	 * regular price must be a positive number greater than the sale
	 * price, and the resulting percentage must round to at least 1%, so
	 * a fractional discount too small to display sensibly (e.g. rounding
	 * to "Save 0%") is treated the same as no discount at all.
	 *
	 * This is purely a read-only display addition: it does not change
	 * how the line item's own subtotal is calculated or displayed, and
	 * it carries no interactive controls, so quantity, remove, and
	 * subtotal behavior for the item are unaffected.
	 *
	 * @param \WC_Product|null $product The line item's product.
	 * @return void
	 */
	protected static function render_item_sale_info( $product ) {
		if ( ! $product instanceof \WC_Product || ! $product->is_on_sale() ) {
			return;
		}

		$regular_price = (float) $product->get_regular_price();
		$sale_price    = (float) $product->get_sale_price();

		if ( $regular_price <= 0 || $sale_price < 0 || $sale_price >= $regular_price ) {
			return;
		}

		$percent_off = (int) round( ( ( $regular_price - $sale_price ) / $regular_price ) * 100 );

		if ( $percent_off <= 0 ) {
			return;
		}

		$display_regular_price = wc_get_price_to_display( $product, array( 'price' => $regular_price ) );
		?>
		<div class="manage-cart-item-sale">
			<span class="manage-cart-item-regular-price">
				<del><?php echo wp_kses_post( wc_price( $display_regular_price ) ); ?></del>
			</span>
			<span class="manage-cart-item-save-badge">
				<?php
				printf(
					/* translators: %d: percentage discount off the regular price. */
					esc_html__( 'Save %d%%', 'manage-cart' ),
					$percent_off
				);
				?>
			</span>
		</div>
		<?php
	}

	/**
	 * Renders the read-only "Only X left" low stock badge for a single cart
	 * line item's product, shown below the item when the "Show low stock
	 * badge" Side Cart setting is enabled. Renders nothing at all unless
	 * every condition holds: the setting is enabled, WooCommerce is
	 * actively managing stock for this exact product/variation (not
	 * inherited from a parent), a numeric stock quantity is available, that
	 * quantity is greater than zero, the product is not currently on
	 * backorder, and the quantity is less than or equal to the configured
	 * threshold.
	 *
	 * This is purely a read-only display addition: it does not change
	 * quantity, remove, or subtotal markup/behavior for the item, and adds
	 * no new frontend request.
	 *
	 * @param \WC_Product|null $product              The line item's product.
	 * @param bool             $show_low_stock_badge Whether the "Show low stock badge" setting is enabled.
	 * @param int              $low_stock_threshold  Stock level at or below which the badge appears.
	 * @return void
	 */
	protected static function render_low_stock_badge( $product, $show_low_stock_badge, $low_stock_threshold ) {
		if ( ! $show_low_stock_badge || ! $product instanceof \WC_Product ) {
			return;
		}

		// Only proceed when this exact product/variation manages its own
		// stock. `managing_stock()` returns `false` when stock isn't
		// tracked at all, and `'parent'` on a variation whose stock is
		// tracked on the parent product instead; both cases are excluded
		// since they don't reflect this line item's own stock quantity.
		if ( true !== $product->managing_stock() ) {
			return;
		}

		$stock_quantity = $product->get_stock_quantity();

		// Products without a tracked stock quantity never show the badge.
		if ( null === $stock_quantity ) {
			return;
		}

		$stock_quantity = (int) $stock_quantity;

		if ( $stock_quantity <= 0 ) {
			return;
		}

		// Backordered items are excluded even if their (negative-turned-
		// zero-or-below, or otherwise) quantity would technically qualify.
		if ( 'onbackorder' === $product->get_stock_status() ) {
			return;
		}

		$low_stock_threshold = (int) $low_stock_threshold;
		if ( $low_stock_threshold < 1 ) {
			$low_stock_threshold = 5;
		}

		if ( $stock_quantity > $low_stock_threshold ) {
			return;
		}
		?>
		<div class="manage-cart-item-low-stock">
			<span class="manage-cart-item-low-stock-badge">
				<?php
				printf(
					/* translators: %d: number of items left in stock. */
					esc_html__( 'Only %d left', 'manage-cart' ),
					$stock_quantity
				);
				?>
			</span>
		</div>
		<?php
	}

	/**
	 * Renders the polished empty-cart state.
	 *
	 * @return void
	 */
	protected static function render_empty_state() {
		/**
		 * Filters the entire empty-cart state markup, letting an add-on
		 * completely replace it (heading, description, and button)
		 * instead of only appending after it.
		 *
		 * Must return either an empty string — meaning "render the
		 * default markup below" — or a complete, ready-to-output HTML
		 * string that the callback has already escaped itself. Free
		 * ManageCart adds no callback here itself, so this filter has
		 * no effect on the free plugin's own output or behavior unless
		 * something else hooks it.
		 *
		 * Reserved for a future ManageCart Pro add-on (Custom Empty
		 * Cart Content).
		 *
		 * @param string $override Empty string by default.
		 */
		$override = apply_filters( 'manage_cart_empty_state_content', '' );

		if ( '' !== $override ) {
			echo $override; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filter contract requires the callback to pre-escape its own return value.
			return;
		}
		?>
		<div class="manage-cart-empty">
			<span class="manage-cart-empty-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="40" height="40">
					<path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="<?php echo esc_attr( self::BASKET_ICON_PATH_D ); ?>"/>
				</svg>
			</span>
			<p class="manage-cart-empty-title"><?php esc_html_e( 'Your cart is empty', 'manage-cart' ); ?></p>
			<p class="manage-cart-empty-text"><?php esc_html_e( 'Looks like you haven\'t added anything yet.', 'manage-cart' ); ?></p>
			<?php if ( function_exists( 'wc_get_page_permalink' ) ) : ?>
				<a class="manage-cart-btn manage-cart-btn--primary" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">
					<?php esc_html_e( 'Start Shopping', 'manage-cart' ); ?>
				</a>
			<?php endif; ?>
			<?php
			/**
			 * Fires after the empty-cart state's "Start Shopping" button.
			 *
			 * Reserved for a future ManageCart Pro add-on — e.g. recommended
			 * or recently viewed products shown while the cart is empty.
			 * Free ManageCart adds no output here itself.
			 */
			do_action( 'manage_cart_empty_state_after' );
			?>
		</div>
		<?php
	}
}
