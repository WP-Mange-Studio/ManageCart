<?php
/**
 * Public [manage_cart_trigger] shortcode: a compact, reusable cart
 * icon/button for headers, menus, Gutenberg, Elementor, and templates.
 *
 * @package ManageCart
 */

namespace ManageCart;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Menu_Cart_Trigger
 *
 * Registers and renders the `[manage_cart_trigger]` shortcode.
 *
 * Why this reuses the existing drawer instead of building a new one:
 * ManageCart already renders exactly one Side Cart panel/overlay per
 * page — Cart_Renderer::render_panel(), output once on wp_footer by
 * Frontend::render() — and exactly one script (assets/js/frontend-cart.js)
 * that owns that panel's open/close state, focus handling, and live
 * WooCommerce Store API refresh logic. This shortcode never duplicates
 * any of that. It renders a second, independent *trigger* button only —
 * visually similar to the floating trigger, but a distinct element, since
 * a page can legitimately contain the floating button, a menu button, or
 * both. Clicking it does not create a second drawer, overlay, cart
 * renderer, REST route, or AJAX handler: it only asks the existing
 * frontend-cart.js panel to open, via a small, purely additive
 * `[data-manage-cart-menu-trigger]` click binding added alongside the
 * script's existing floating-trigger click handler (see
 * assets/js/frontend-cart.js). No new client-side cart state is created;
 * the script's single `isOpen`/panel state continues to be the only
 * source of truth, and the existing `updateTriggerCount()` routine that
 * already keeps the floating trigger's badge in sync with every live
 * cart change (add/update/remove) is simply extended to also update any
 * `[data-manage-cart-menu-trigger]` badges found on the page.
 *
 * Server-side, this class only *reads* the current cart item count
 * (Cart_Renderer::get_cart_item_count()) and the existing panel's id
 * (Cart_Renderer::PANEL_ID) to point `aria-controls` at it. It never
 * touches the cart, never renders the panel/overlay itself, and never
 * runs on the Cart or Checkout page differently than any other page —
 * Frontend::should_load() (the same gate the floating trigger and its
 * assets already use) decides that.
 *
 * Classic WordPress menu integration
 * -----------------------------------
 * In addition to the shortcode, this class adds one *optional* item an
 * admin can insert into any classic navigation menu from
 * Appearance → Menus: a "ManageCart Cart Trigger" meta box in that
 * screen's sidebar, next to the built-in "Pages" / "Custom Links" /
 * etc. boxes. Nothing here ever modifies an existing menu on its own —
 * a site keeps rendering exactly the menus it already had until an
 * admin explicitly clicks "Add to Menu" on that box, same as with any
 * other native meta box on that screen.
 *
 * The box does not introduce a new menu item type, a custom REST route,
 * or a custom AJAX handler of its own. It is implemented the same way
 * WordPress's own built-in "Pages" / "Posts" meta boxes on this screen
 * are: render_nav_menu_meta_box() below outputs a single, always-checked
 * `.menu-item-checkbox` inside a `.tabs-panel.tabs-panel-active
 * .categorychecklist` list — the exact markup shape
 * `wp_nav_menu_item_post_type_meta_box()` produces via
 * `Walker_Nav_Menu_Checklist` for a post-type box — carrying this
 * feature's fixed `menu-item[-1][...]` fields (type, title, url), plus
 * an `id="submit-manage-cart-trigger-nav-link"` "Add to Menu" button.
 * WordPress's own already-loaded `nav-menu.js` on this screen is what
 * notices that click (it delegates on any `.submit-add-to-menu` element)
 * and, seeing an id other than `submit-customlinkdiv`, strips the
 * `submit-` prefix and calls `$( '#manage-cart-trigger-nav-link'
 * ).addSelectedToMenu()` on this box's own container — the same jQuery
 * plugin every native post-type box on this screen uses — which reads
 * the checked item's fields and POSTs WordPress's own already-registered
 * `add-menu-item` AJAX action. Nothing here fills in, reads, or clicks
 * any part of the separate "Custom Links" box; this meta box submits its
 * own fields through its own container, exactly like any other nav-menu
 * meta box. The result is still a menu item saved with `type = 'custom'`
 * and `url = '#manage-cart-trigger'` — a fragment, never a real
 * destination, since clicking it is meant to open the drawer, not
 * navigate anywhere.
 *
 * On the frontend, `filter_nav_menu_item_output()` recognizes exactly
 * that URL on `walker_nav_menu_start_el` and replaces that one item's
 * rendered content with `do_shortcode( '[manage_cart_trigger]' )` —
 * the very same shortcode callback above, not a second implementation
 * — so the menu item is guaranteed to look and behave identically to
 * the shortcode wherever it's placed, including this class's own
 * should_render() gate, live badge updates, and everything else. Only
 * the item's inner content (normally its `<a>` link) is replaced; the
 * surrounding `<li>` a theme's menu walker/CSS expects is left exactly
 * as WordPress renders it. `filter_nav_menu_objects()` removes the item
 * entirely, before it ever reaches the walker, on any request where
 * should_render() is false (the same condition the shortcode itself
 * already honors), so no empty, broken, or dead item is ever left
 * behind in the menu markup.
 *
 * None of this touches the block-editor "Navigation" block; that is a
 * different, unrelated menu system and is intentionally out of scope
 * here. If classic navigation menus are ever unavailable on a given
 * site (for example, a block theme that no longer exposes
 * Appearance → Menus), this entire section of the class simply has no
 * screen to add its meta box or filters to act on — the
 * `[manage_cart_trigger]` shortcode above is entirely independent of
 * it and keeps working exactly as before, in page content, widgets, or
 * anywhere else it is placed.
 *
 * Block-theme support: the manage-cart/cart-trigger block
 * ---------------------------------------------------------
 * Classic nav-menu integration above has no equivalent on a block
 * theme (Twenty Twenty-Five and similar) — Appearance → Menus simply
 * isn't the screen those themes use to build a header, and the
 * `[manage_cart_trigger]` shortcode, while it still works if pasted
 * into a Shortcode block, isn't discoverable the way a real block is.
 * register_block() below registers a native `manage-cart/cart-trigger`
 * block (from blocks/cart-trigger/block.json) so it shows up in the
 * inserter itself, including inside a Header/Footer template part
 * opened from Appearance → Editor — the one place block themes edit
 * their header, where WooCommerce's own core Mini-Cart block also
 * lives.
 *
 * This block is a thin wrapper around the same render() method the
 * shortcode uses above (see render_block()), never a second markup
 * implementation, and is fully dynamic/server-rendered — its editor
 * script's `save()` returns `null`, so WordPress never freezes any
 * button HTML into post content; render_block() runs fresh on every
 * request, exactly like the shortcode. That means it opens the exact
 * same Side Cart panel/overlay via the very same
 * `[data-manage-cart-menu-trigger]` delegated click handler and
 * `updateTriggerCount()` badge sync in assets/js/frontend-cart.js —
 * neither of which needed any change to support it, since both already
 * work against however many `[data-manage-cart-menu-trigger]` elements
 * exist on the page, wherever they came from. should_render() (and
 * therefore its Cart/Checkout exclusion) gates the block's output the
 * same way it gates the shortcode's.
 *
 * This plugin never inspects, hides, disables, or otherwise touches
 * WooCommerce's own Mini-Cart block. The two are entirely independent
 * blocks a site owner can each place, or not, in the same header —
 * exactly like any other pair of unrelated third-party blocks.
 */
class Menu_Cart_Trigger {

	/**
	 * The shortcode tag: [manage_cart_trigger].
	 *
	 * @var string
	 */
	const SHORTCODE_TAG = 'manage_cart_trigger';

	/**
	 * Stable, namespaced class on the rendered button, for site CSS and
	 * for this feature's own JS binding (assets/js/frontend-cart.js
	 * selects on the sibling data attribute below, not this class).
	 *
	 * @var string
	 */
	const BUTTON_CLASS = 'manage-cart-menu-trigger';

	/**
	 * Namespaced data attribute the frontend script binds its click
	 * handler to (see assets/js/frontend-cart.js). Kept separate from
	 * the floating trigger's `id="manage-cart-trigger"` on purpose: this
	 * shortcode can legitimately be placed more than once on a page (a
	 * header and a mobile menu, say), and an `id` must stay unique.
	 *
	 * @var string
	 */
	const DATA_ATTR = 'data-manage-cart-menu-trigger';

	/**
	 * The fixed, namespaced fragment URL used to identify a classic nav
	 * menu item as this feature's own trigger. Never a real destination
	 * (nothing ever navigates to it) — its only job is to mark the item
	 * so filter_nav_menu_item_output() below knows to replace it with
	 * the shortcode's own markup. See the "Classic WordPress menu
	 * integration" section of this class's doc comment for the full
	 * rationale.
	 *
	 * @var string
	 */
	const NAV_MENU_ITEM_URL = '#manage-cart-trigger';

	/**
	 * The block's registered name, as it must appear in block.json's own
	 * `name` field (see blocks/cart-trigger/block.json) and everywhere
	 * else WordPress identifies it (the inserter, `register_block_type()`
	 * below).
	 *
	 * @var string
	 */
	const BLOCK_NAME = 'manage-cart/cart-trigger';

	/**
	 * Registers the `[manage_cart_trigger]` shortcode, the
	 * `manage-cart/cart-trigger` block, the optional classic nav menu
	 * meta box, and the filters that render this feature's own item
	 * within a rendered menu.
	 *
	 * The shortcode itself is registered unconditionally (not gated to
	 * the frontend) so the tag is always recognized wherever WordPress
	 * processes shortcodes — including inside block/page-builder editors
	 * that may preview content in an admin-side request. The actual
	 * markup decision still defers entirely to should_render(), so
	 * nothing renders anywhere ManageCart's own frontend cart wouldn't
	 * otherwise load.
	 *
	 * The block is registered on `init` (see register_block() below)
	 * rather than called directly here, since block registration —
	 * scripts, styles, and the block type itself — is only ever safe to
	 * do from that hook onward; this class's own init() runs earlier,
	 * from Plugin::run() on `plugins_loaded`.
	 *
	 * The nav-menus.php meta box is likewise registered unconditionally
	 * here; it is internally scoped to that one admin screen (see
	 * maybe_add_nav_menu_meta_box()), so registering the hook has no
	 * effect anywhere else. It needs no script of its own — its "Add to
	 * Menu" button is wired up by WordPress's own nav-menu.js, already
	 * loaded on that screen for every other meta box — so there is
	 * nothing extra to enqueue. The two `walker_nav_menu_*` filters only
	 * ever act on an item carrying this feature's own fixed URL
	 * (NAV_MENU_ITEM_URL), so they are likewise safe to add
	 * unconditionally: they simply never match anything on a site where
	 * no admin has ever added this item to a menu.
	 *
	 * @return void
	 */
	public function init() {
		add_shortcode( self::SHORTCODE_TAG, array( $this, 'render_shortcode' ) );

		add_action( 'init', array( $this, 'register_block' ) );

		add_action( 'admin_head-nav-menus.php', array( $this, 'maybe_add_nav_menu_meta_box' ) );

		add_filter( 'wp_nav_menu_objects', array( $this, 'filter_nav_menu_objects' ), 10, 2 );
		add_filter( 'walker_nav_menu_start_el', array( $this, 'filter_nav_menu_item_output' ), 10, 4 );
	}

	/**
	 * Registers the native `manage-cart/cart-trigger` Gutenberg block
	 * from blocks/cart-trigger/block.json, so it appears in the block
	 * inserter everywhere WordPress offers one — including Header/Footer
	 * template parts opened from Appearance → Editor on a block theme,
	 * which classic nav-menu integration (see this class's doc comment)
	 * cannot reach.
	 *
	 * Hooked on `init`, unconditionally, by init() above — the same hook
	 * and the same unconditional call, on every normal request WordPress
	 * makes (frontend, wp-admin, and the Site Editor/Gutenberg alike).
	 * Deliberately mirrors none of should_render()'s frontend-only
	 * checks (Frontend::should_load(), `is_cart()` / `is_checkout()`,
	 * cart state): a block must be registered before WordPress can ever
	 * decide whether to render it anywhere, including in an admin-side
	 * inserter search where there is no cart, no cart page, and often no
	 * frontend request at all. Those checks remain exactly where they
	 * already were — inside should_render(), called only from render()
	 * once this block (or the shortcode) is actually being rendered —
	 * and are untouched by this method.
	 *
	 * This is a fully dynamic (server-rendered) block: block.json ships
	 * no `save` markup of its own (its editor script's `save()` returns
	 * `null`), and render_block() above is the only thing that ever
	 * produces its front-end output, by deferring to the exact same
	 * render() method the shortcode uses. WordPress therefore never
	 * stores any button markup in post content; only the block's
	 * placement (inside whichever template/template part an admin adds
	 * it to) is persisted, exactly like WooCommerce's own Mini-Cart
	 * block.
	 *
	 * Every step below fails loudly instead of silently, on purpose: a
	 * dynamic block that simply doesn't show up in the inserter, with no
	 * visible error anywhere, is exactly the failure this method is
	 * hardened against, since it is otherwise very easy for a wrong path
	 * or an already-registered name to no-op without a trace.
	 * - The `block.json` file is confirmed to exist at the expected path
	 *   before ever calling `register_block_type()`, using the single,
	 *   centrally-defined MANAGE_CART_BLOCKS_DIR constant (see
	 *   manage-cart.php) rather than a path built ad hoc here, so there
	 *   is exactly one place that path can ever be wrong.
	 * - Registration is skipped (not re-attempted, which would itself
	 *   trigger a core "already registered" warning) if
	 *   `manage-cart/cart-trigger` is already in WordPress's own block
	 *   registry, making this method safe to ever call more than once.
	 * - `register_block_type()`'s return value is checked; a failure
	 *   (`false`) is reported via `_doing_it_wrong()`, WordPress's own
	 *   mechanism for surfacing exactly this class of "should have
	 *   worked, didn't" integration bug during development/debugging
	 *   (visible whenever `WP_DEBUG` is on), rather than being silently
	 *   swallowed the way an unchecked call would.
	 *
	 * Guarded with `function_exists()` for defensive symmetry with the
	 * rest of this codebase's optional-API checks (see, for example,
	 * Compatibility::declare_compatibility()); `register_block_type()`
	 * has existed since WordPress 5.0 and this plugin already requires
	 * 6.5, so this only ever fails closed in a hypothetically broken
	 * environment, never silently no-ops on a normal one.
	 *
	 * @return void
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		if (
			class_exists( '\WP_Block_Type_Registry' )
			&& \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_NAME )
		) {
			return;
		}

		$block_dir = defined( 'MANAGE_CART_BLOCKS_DIR' )
			? MANAGE_CART_BLOCKS_DIR . 'cart-trigger'
			: MANAGE_CART_PLUGIN_DIR . 'blocks/cart-trigger';

		if ( ! file_exists( $block_dir . '/block.json' ) ) {
			if ( function_exists( '_doing_it_wrong' ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: %s: expected absolute path to block.json. */
						esc_html__( 'ManageCart: expected to find the Cart Trigger block\'s block.json at "%s", but it was not there — the block was not registered.', 'manage-cart' ),
						esc_html( $block_dir . '/block.json' )
					),
					( defined( 'MANAGE_CART_VERSION' ) ? MANAGE_CART_VERSION : '0.1.1' )
				);
			}
			return;
		}

		$registered = register_block_type(
			$block_dir,
			array(
				'render_callback' => array( $this, 'render_block' ),
			)
		);

		if ( ! $registered && function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: the block name that failed to register. */
					esc_html__( 'ManageCart: register_block_type() returned false for "%s" — the block will not appear in the inserter.', 'manage-cart' ),
					esc_html( self::BLOCK_NAME )
				),
				( defined( 'MANAGE_CART_VERSION' ) ? MANAGE_CART_VERSION : '0.1.1' )
			);
		}
	}

	/**
	 * Whether the shortcode (and, via is_nav_menu_trigger_item() /
	 * filter_nav_menu_objects() below, the optional classic nav menu
	 * item) should render real markup on this request.
	 *
	 * Starts with Frontend::should_load() — the same check that gates
	 * the floating trigger, the panel, and their assets — so this button
	 * only ever appears where the existing drawer it controls actually
	 * exists on the page. This already excludes wp-admin, REST, and AJAX
	 * requests, sites without WooCommerce active/available, and pages
	 * where the global "Enable Manage Cart" or "Enable Floating Cart"
	 * settings are off.
	 *
	 * On top of that, this additionally excludes the native WooCommerce
	 * Cart and Checkout pages (`is_cart()` / `is_checkout()`). Unlike the
	 * floating trigger and the drawer itself — which intentionally stay
	 * available there, since a shopper on the Cart or Checkout page can
	 * still legitimately want quick access to the drawer — a second,
	 * separate cart-opening button placed by an admin in page content or
	 * the site's global navigation menu is redundant on the page that
	 * already *is* the cart, and, on Checkout, an extra "open the cart
	 * drawer" control is an unwanted distraction from completing the
	 * order. This only ever affects this shortcode/menu-item feature —
	 * Frontend::should_load() itself, and everything it already gates
	 * (the floating trigger, the panel/overlay, the Store API refresh
	 * logic), is untouched, so the drawer remains fully available on
	 * Cart and Checkout exactly as before.
	 *
	 * `is_cart()` / `is_checkout()` are guarded with `function_exists()`
	 * only for defensive symmetry with how the rest of this codebase
	 * already checks for optional WooCommerce conditional tags (see, for
	 * example, Frontend::should_load() itself); by the time this method
	 * can ever return true in the first place, Frontend::should_load()
	 * has already confirmed WooCommerce is active, so both functions are
	 * guaranteed to exist here regardless.
	 *
	 * @return bool
	 */
	protected function should_render() {
		if ( ! Frontend::should_load() ) {
			return false;
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return false;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return false;
		}

		return true;
	}

	/**
	 * Shortcode callback for `[manage_cart_trigger]`.
	 *
	 * A thin wrapper: normalizes shortcode attributes the way every
	 * shortcode callback does (`shortcode_atts()`, which also runs the
	 * `shortcode_atts_{tag}` filter third-party code may already rely
	 * on) and then defers to render() below for the actual markup, the
	 * same shared method render_block() (and therefore the block below)
	 * uses. Nothing about the resulting button differs from before this
	 * method existed — this is a pure refactor, not a behavior change.
	 *
	 * @param array|string $atts Shortcode attributes as passed by WordPress.
	 * @return string Button markup, or an empty string when the existing
	 *                 ManageCart frontend (and therefore the drawer this
	 *                 button controls) should not load on this request.
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				// Optional extra class(es) a theme/page builder can add
				// (e.g. an Elementor "additional CSS classes" field)
				// without needing to touch this plugin's own class.
				'class' => '',
			),
			$atts,
			self::SHORTCODE_TAG
		);

		return $this->render( $atts );
	}

	/**
	 * `render_callback` for the `manage-cart/cart-trigger` block (see
	 * register_block() below). Takes no attributes of its own — the
	 * block intentionally exposes none in block.json (`supports.className`
	 * is left at its default only for the block-editor selection outline;
	 * `customClassName` is off, so there is no "Additional CSS class(es)"
	 * field to read here) — and simply defers to the exact same render()
	 * method render_shortcode() above uses, so a merchant who places this
	 * block in a Site Editor header gets byte-identical markup, aria
	 * attributes, and live badge behavior to `[manage_cart_trigger]`
	 * anywhere else it's used.
	 *
	 * @param array         $block_attributes Block attributes (unused; the block defines none).
	 * @param string        $content          Inner block content (unused; this is a leaf block).
	 * @param \WP_Block|null $block           The block instance (unused).
	 * @return string Button markup, or an empty string — see render().
	 */
	public function render_block( $block_attributes, $content, $block ) {
		unset( $block_attributes, $content, $block );

		return $this->render();
	}

	/**
	 * Shared render method behind both the `[manage_cart_trigger]`
	 * shortcode and the `manage-cart/cart-trigger` block — the single
	 * place that actually builds the button markup, so the two entry
	 * points above can never drift apart. See this class's doc comment
	 * for the full behavioral rationale (should_render() gating, the
	 * `data-manage-cart-menu-trigger` binding assets/js/frontend-cart.js
	 * already handles, and the live badge updates).
	 *
	 * @param array $atts {
	 *     Optional. Rendering options.
	 *
	 *     @type string $class Extra CSS class(es) to add alongside the
	 *                          button's own `manage-cart-menu-trigger`
	 *                          class (e.g. from the shortcode's `class`
	 *                          attribute). Empty by default.
	 * }
	 * @return string Button markup, or an empty string when the existing
	 *                 ManageCart frontend (and therefore the drawer this
	 *                 button controls) should not load on this request.
	 */
	public function render( $atts = array() ) {
		if ( ! $this->should_render() ) {
			return '';
		}

		$atts = wp_parse_args(
			is_array( $atts ) ? $atts : array(),
			array( 'class' => '' )
		);

		$count = Cart_Renderer::get_cart_item_count();

		$label = sprintf(
			/* translators: %d: number of items currently in the WooCommerce cart. */
			_n( 'Open cart, %d item', 'Open cart, %d items', $count, 'manage-cart' ),
			$count
		);

		$button_classes = self::BUTTON_CLASS;

		if ( ! empty( $atts['class'] ) ) {
			$extra_classes = array_map( 'sanitize_html_class', explode( ' ', (string) $atts['class'] ) );
			$button_classes .= ' ' . implode( ' ', array_filter( $extra_classes ) );
		}

		ob_start();
		?>
		<button
			type="button"
			class="<?php echo esc_attr( trim( $button_classes ) ); ?>"
			<?php echo esc_attr( self::DATA_ATTR ); ?>
			data-manage-cart-item-count="<?php echo esc_attr( (string) $count ); ?>"
			aria-haspopup="dialog"
			aria-expanded="false"
			aria-controls="<?php echo esc_attr( Cart_Renderer::PANEL_ID ); ?>"
			aria-label="<?php echo esc_attr( $label ); ?>"
		>
			<span class="manage-cart-menu-trigger-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="20" height="20" focusable="false">
					<path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="<?php echo esc_attr( Cart_Renderer::BASKET_ICON_PATH_D ); ?>"/>
				</svg>
			</span>
			<?php if ( $count > 0 ) : ?>
				<span class="manage-cart-menu-trigger-count" data-manage-cart-menu-count aria-hidden="true"><?php echo esc_html( (string) $count ); ?></span>
			<?php endif; ?>
		</button>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Registers the "ManageCart Cart Trigger" meta box on the
	 * Appearance → Menus screen, alongside WordPress's own native
	 * "Pages" / "Custom Links" / etc. boxes.
	 *
	 * Hooked on `admin_head-nav-menus.php`, which — unlike the generic
	 * `add_meta_boxes` action — is guaranteed to fire only while
	 * nav-menus.php itself is loading, and always early enough for
	 * `add_meta_box()` calls made here to be picked up by that screen's
	 * own `do_meta_boxes( 'nav-menus', 'side', $nav_menu_selected_id )`
	 * call later in the same request. This never fires on any other
	 * admin screen, so it never needs its own extra guard.
	 *
	 * @return void
	 */
	public function maybe_add_nav_menu_meta_box() {
		add_meta_box(
			'manage-cart-trigger-nav-menu',
			__( 'ManageCart Cart Trigger', 'manage-cart' ),
			array( $this, 'render_nav_menu_meta_box' ),
			'nav-menus',
			'side',
			'low'
		);
	}

	/**
	 * Renders the "ManageCart Cart Trigger" meta box's contents: a short
	 * explanation and a single "Add to Menu" button, styled to match the
	 * native boxes on this screen.
	 *
	 * This box intentionally does not offer a list to choose from, or a
	 * URL/label field to fill in — there is exactly one thing it can
	 * add, so it is represented as a single, always-checked, visually
	 * hidden checkbox rather than a field an admin has to interact with.
	 * An admin can still rename the item afterward from the menu
	 * structure below, the same as with any other menu item.
	 *
	 * The markup below deliberately mirrors what
	 * `wp_nav_menu_item_post_type_meta_box()` renders for a native
	 * "Pages"/"Posts" box (a `.tabs-panel.tabs-panel-active
	 * .categorychecklist` list of `.menu-item-checkbox` inputs inside a
	 * uniquely-`id`'d container, plus a `.submit-add-to-menu` button
	 * whose `id` is `submit-` followed by that same container `id`) so
	 * that WordPress's own nav-menu.js — already loaded on this screen —
	 * recognizes and drives it through the exact same
	 * `wpNavMenu.addSelectedToMenu()` / `add-menu-item` AJAX workflow
	 * every other meta box here uses. See this class's own doc comment
	 * ("Classic WordPress menu integration") for the full rationale.
	 *
	 * @return void
	 */
	public function render_nav_menu_meta_box() {
		?>
		<p>
			<?php
			esc_html_e(
				'Adds a cart button that opens the ManageCart Side Cart drawer — the same button as the [manage_cart_trigger] shortcode, as a menu item.',
				'manage-cart'
			);
			?>
		</p>
		<div id="manage-cart-trigger-nav-link" class="manage-cart-trigger-nav-link hidden" aria-hidden="true">
			<div id="tabs-panel-manage-cart-trigger-nav-link" class="tabs-panel tabs-panel-active">
				<ul class="categorychecklist form-no-clear">
					<li>
						<label class="menu-item-title">
							<input
								type="checkbox"
								checked="checked"
								tabindex="-1"
								class="menu-item-checkbox"
								name="menu-item[-1][menu-item-object-id]"
								value="-1"
							/>
							<?php esc_html_e( 'ManageCart Cart Trigger', 'manage-cart' ); ?>
						</label>
						<input type="hidden" class="menu-item-type" name="menu-item[-1][menu-item-type]" value="custom" />
						<input type="hidden" class="menu-item-object" name="menu-item[-1][menu-item-object]" value="manage-cart-trigger" />
						<input type="hidden" class="menu-item-title" name="menu-item[-1][menu-item-title]" value="<?php echo esc_attr__( 'ManageCart Cart Trigger', 'manage-cart' ); ?>" />
						<input type="hidden" class="menu-item-url" name="menu-item[-1][menu-item-url]" value="<?php echo esc_attr( self::NAV_MENU_ITEM_URL ); ?>" />
					</li>
				</ul>
			</div>
		</div>
		<p class="button-controls">
			<span class="add-to-menu">
				<input
					type="submit"
					id="submit-manage-cart-trigger-nav-link"
					class="button-secondary submit-add-to-menu right"
					name="add-manage-cart-trigger-nav-link"
					value="<?php esc_attr_e( 'Add to Menu', 'manage-cart' ); ?>"
				/>
				<span class="spinner"></span>
			</span>
		</p>
		<?php
	}

	/**
	 * Removes this feature's own nav menu item entirely, before the menu
	 * walker ever renders it, on any request where should_render() is
	 * false — the same condition the shortcode itself already honors
	 * (see render_shortcode()) — so no empty, dead menu item is ever
	 * left showing in a menu on, say, a request where the global
	 * "Enable Manage Cart" setting is off, or WooCommerce itself is
	 * inactive.
	 *
	 * Runs on `wp_nav_menu_objects`, which fires with the full, already
	 * sorted list of items for a given menu before Walker_Nav_Menu turns
	 * any of them into HTML, so removing an entry here means it simply
	 * never reaches filter_nav_menu_item_output() below at all.
	 *
	 * @param array $items The menu items about to be rendered.
	 * @param mixed $args  The wp_nav_menu() arguments (unused).
	 * @return array
	 */
	public function filter_nav_menu_objects( $items, $args ) {
		unset( $args );

		if ( $this->should_render() || ! is_array( $items ) ) {
			return $items;
		}

		foreach ( $items as $key => $item ) {
			if ( $this->is_nav_menu_trigger_item( $item ) ) {
				unset( $items[ $key ] );
			}
		}

		return $items;
	}

	/**
	 * Replaces this feature's own nav menu item's rendered content with
	 * the `[manage_cart_trigger]` shortcode's own output — the exact
	 * same markup and behavior as the shortcode anywhere else, since
	 * this calls that same shortcode callback rather than building any
	 * markup of its own. Only the item's inner content (normally its
	 * `<a>` link) is replaced; the surrounding `<li>` a theme's menu
	 * walker/CSS expects is left exactly as WordPress renders it.
	 *
	 * By the time this runs, filter_nav_menu_objects() above has already
	 * removed this item entirely wherever should_render() is false, so
	 * `do_shortcode()` is expected to render real markup here — but it
	 * remains the single source of truth for that decision either way,
	 * so this simply defers to it rather than re-deciding independently.
	 *
	 * @param string $item_output The menu item's rendered HTML so far.
	 * @param object $item        The menu item object.
	 * @param int    $depth       Menu item depth (unused).
	 * @param object $args        The wp_nav_menu() arguments (unused).
	 * @return string
	 */
	public function filter_nav_menu_item_output( $item_output, $item, $depth, $args ) {
		unset( $depth, $args );

		if ( ! $this->is_nav_menu_trigger_item( $item ) ) {
			return $item_output;
		}

		return do_shortcode( '[' . self::SHORTCODE_TAG . ']' );
	}

	/**
	 * Whether the given nav menu item is this feature's own trigger item
	 * — identified solely by its fixed, namespaced URL
	 * (NAV_MENU_ITEM_URL), the one value render_nav_menu_meta_box()
	 * writes into its hidden `menu-item[-1][menu-item-url]` field above
	 * and nothing else in WordPress or this plugin ever sets.
	 *
	 * @param mixed $item A menu item object, as passed by the
	 *                     `wp_nav_menu_objects` / `walker_nav_menu_start_el`
	 *                     filters.
	 * @return bool
	 */
	protected function is_nav_menu_trigger_item( $item ) {
		return is_object( $item )
			&& isset( $item->url )
			&& self::NAV_MENU_ITEM_URL === $item->url;
	}
}
