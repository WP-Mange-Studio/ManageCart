/**
 * Manage Cart — frontend cart trigger/panel behavior (Phase 3A).
 *
 * Vanilla JS, no jQuery, no AJAX. Handles opening/closing the cart panel,
 * the overlay, the Escape key, restoring keyboard focus to the trigger on
 * close, and (Phase 3A.2) making the floating trigger draggable via
 * Pointer Events with its position persisted in localStorage.
 *
 * As of Phase 3B-1 Part 1, the panel markup includes accessible quantity
 * stepper and remove button controls (see class-cart-renderer.php).
 *
 * Phase 3B-1 Part 2 connects those controls to the WooCommerce Store API
 * (update-item / remove-item) using window.fetch — no jQuery and no custom
 * PHP AJAX handler. It disables only the affected item's own controls
 * while its request is pending, and announces success/failure via the
 * existing aria-live status region.
 *
 * Phase 3B-1 Part 3 updates the rest of the open cart panel from those
 * same successful Store API responses, without a page reload: the
 * changed item's row (quantity and line subtotal, or removing the row
 * entirely), the floating trigger's count badge and aria-label, the
 * panel heading's item count, the footer subtotal, and swapping in the
 * empty-cart state once the last item is removed. All of this is built
 * with document.createElement()/createElementNS() and .textContent —
 * server response data is never assigned via innerHTML. After a
 * removal, keyboard focus moves to a sensible remaining control (the
 * next item's, falling back to the previous item's), or to the panel
 * heading when the cart is now empty. The cart panel itself is never
 * closed by any of this. Coupons, undo, auto-open after add-to-cart,
 * the menu cart, and any styling/admin settings remain reserved for
 * later phases.
 *
 * Phase 3B-1 Part 4 hides the floating trigger (`hidden` attribute)
 * completely while the panel is open, and shows it again — in its
 * exact saved draggable position — whenever the panel closes, however
 * that happens (close button, overlay click, Escape, or the trigger's
 * own toggle logic), all funneling through the shared closePanel().
 * Keyboard focus restoration to the trigger on close is unaffected;
 * the trigger is always made visible again before it is focused.
 *
 * Phase 3C adds auto-open-on-add. Every successful WooCommerce
 * add-to-cart, however it happened, fetches WooCommerce's own real
 * Store API cart (`GET /wc/store/v1/cart` — no custom REST/AJAX route
 * of this plugin's own) and applies the result: the trigger badge,
 * heading count, item list, and footer. The panel opens on the first
 * add (when the "Auto-open cart after add to cart" Side Cart setting
 * is on and it isn't already open) and simply updates in place on
 * later adds while already open — it is never closed and reopened for
 * this, so once the customer closes it themselves, the next
 * successful add opens it again. Quantity changes and removals are
 * unaffected: they still go through the existing Store API
 * update-item/remove-item handling below and never open the panel.
 *
 * Three distinct add-to-cart flows are covered, since WooCommerce
 * does not report a successful add the same way in all of them:
 *
 * - Classic AJAX add-to-cart (archive/loop "Add to cart" buttons):
 *   WooCommerce core fires this on document.body only through
 *   jQuery's own event system, so jQuery is used for this one
 *   listener alone, and only when WooCommerce has already loaded it.
 * - Block-based add-to-cart (the Add to Cart with Options block, the
 *   Product Collection block, the Mini-Cart, and the Cart block):
 *   WooCommerce Blocks translates that same moment into a native
 *   `wc-blocks_added_to_cart` DOM event on document.body — no jQuery
 *   involved, so this is listened for directly with
 *   addEventListener().
 * - Normal (non-AJAX) add-to-cart, i.e. a plain form submit that
 *   causes a full page reload: there is no in-page event to listen
 *   for at all, since the script that would fire it is unloaded by
 *   the reload. Instead, on the page that loads back in, this checks
 *   the URL for WooCommerce's own `added-to-cart` redirect query
 *   argument. The server has already rendered that page's cart panel
 *   from the current cart, so this path simply opens the panel
 *   (subject to the same auto-open setting/already-open check) and
 *   cleans the query argument from the URL, without needing to fetch
 *   anything.
 *
 * The Store API cart response is fetched with `credentials:
 * 'same-origin'`, a `Cache-Control: no-cache` header (so a stale,
 * browser-cached empty cart is never applied right after an add), and
 * the current Store API `Nonce` header, saving whatever fresh nonce
 * the response header returns for the next request — the exact same
 * nonce lifecycle already used below for update-item/remove-item.
 * Every part of the refresh is applied with
 * document.createElement()/.textContent — never innerHTML or
 * insertAdjacentHTML.
 *
 * Phase 3F-1 adds the "Have a coupon?" toggle and coupon code form
 * (see Cart_Renderer::render_coupon_section()). Submitting it sends the
 * code to WooCommerce's own `POST /wc/store/v1/cart/apply-coupon` Store
 * API route — no custom AJAX/REST route, no jQuery — using the exact
 * same nonce lifecycle as every other Store API request here. On
 * success it reuses applyPanelRefresh(), the same full-refresh routine
 * the add-to-cart flow above already uses, to rebuild the item list,
 * footer subtotal, heading count, and floating trigger badge from that
 * one real response, without a page reload and without ever closing the
 * panel. On error the cart is left untouched and the message is
 * announced via the existing aria-live status region. The whole section
 * is omitted client-side (config.couponsEnabled) to match
 * Cart_Renderer's own `wc_coupons_enabled()` check server-side.
 *
 * Phase 3H-1 adds the applied-coupons list (code, discount amount, and
 * an accessible Remove button per coupon) built above that same "Have a
 * coupon?" toggle — see buildAppliedCouponsList(), which mirrors
 * Cart_Renderer::render_applied_coupons()'s server-rendered markup, and
 * getCouponDiscountDisplay(), which reads a Store API coupon entry's own
 * `totals` the same way the existing item/cart total helpers do.
 * Clicking a coupon's Remove button sends its code to WooCommerce's own
 * `POST /wc/store/v1/cart/remove-coupon` Store API route — no custom
 * AJAX/REST route, no jQuery — using the exact same nonce lifecycle as
 * every other Store API request here, then reuses applyPanelRefresh() to
 * update the item list, footer subtotal, coupon list, heading count, and
 * floating trigger badge from that one real response, without a page
 * reload and without ever closing the panel. On error the cart is left
 * untouched, the button is re-enabled, and the message is announced via
 * the existing aria-live status region.
 *
 * ## Extension point: `managecart:cart-updated`
 *
 * After ManageCart has successfully applied a WooCommerce Store API cart
 * response *and* finished its own DOM refresh, it dispatches a single
 * `managecart:cart-updated` CustomEvent on document.body (bubbling, so
 * document and window listeners receive it too). `event.detail.cart` is
 * the raw, unmodified Store API cart response.
 *
 * It fires for every cart change this script applies: add-to-cart,
 * quantity update, item removal, coupon apply, coupon remove, and the
 * full panel refresh. It does not fire when a request fails.
 *
 * This exists so an add-on, theme, or site customization can react to a
 * cart change without monkey-patching window.fetch/XMLHttpRequest or
 * running a MutationObserver over the panel — both of which are
 * error-prone and can interfere with unrelated site traffic. See
 * scheduleCartUpdatedEvent() below, and docs/hooks.md.
 *
 * ## Extension point: `managecart:request-cart-refresh`
 *
 * The reverse direction of the event above: any other script can
 * dispatch this generic `managecart:request-cart-refresh` CustomEvent
 * (on document.body, or anything that bubbles to it) to ask ManageCart
 * to refresh the panel from the real, current WooCommerce Store API
 * cart -- for example, after that script has posted its own request to
 * a Store API route (its own add-to-cart button, say). This script
 * never trusts any cart data or markup the dispatching script might
 * attach to the event; it always re-fetches the cart itself via
 * fetchStoreApiCart() and applies it with applyPanelRefresh(), the same
 * function a classic/block add-to-cart uses. Duplicate requests fired
 * in quick succession are throttled. See handleRequestCartRefresh()
 * below, and docs/hooks.md.
 *
 * Single Product Page Auto-open, Part 2 adds a fourth add-to-cart flow
 * alongside the three described above. WooCommerce's default
 * single-product page form doesn't redirect and fires none of the three
 * in-page events those rely on (Single_Product_Auto_Open's own doc
 * comment on the PHP side covers why) — this very page load already is
 * the destination the item was added on. This checks the one-shot
 * `config.singleProductAddToCartSuccess` flag the server only ever
 * localizes into this page's config when a qualifying single-product
 * add was detected on this exact request, and — if set — reuses
 * handleAddedToCart() unchanged: the same real Store API fetch, panel
 * refresh, and open-only-if-not-already-open logic every other flow
 * above already uses, so this never creates a second panel or a second
 * event listener. The flag is cleared the instant it's read, before
 * handleAddedToCart() even runs, so nothing can act on it twice.
 *
 * The Menu Cart Trigger shortcode ([manage_cart_trigger], see
 * class-menu-cart-trigger.php) adds a delegated document click listener
 * for any `[data-manage-cart-menu-trigger]` button (keyboard activation
 * is handled for free, since each is a real `<button>`) and extends
 * updateTriggerCount() with updateMenuTriggers() so any number of these
 * buttons stay in sync with the live cart count/badge, plus
 * syncMenuTriggersExpanded() so their `aria-expanded` tracks the shared
 * panel open/closed state exactly like the floating trigger's own. All
 * of this reuses the exact same openPanel()/closePanel() and
 * panel/isOpen state this file already maintains for the floating
 * trigger — the same overlay, focus handling, and Escape handling —
 * and the exact same cart-refresh functions (applyPanelRefresh(),
 * applyCartWideUpdates()) every existing flow (add-to-cart, quantity
 * change, item removal, Undo restore, coupon apply/remove) already
 * calls; none of it adds a second drawer, a second Store API fetch, or
 * any new cart state of its own.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var trigger  = document.getElementById( 'manage-cart-trigger' );
		var panel    = document.getElementById( 'manage-cart-panel' );
		var overlay  = document.getElementById( 'manage-cart-overlay' );
		var closeBtn = document.getElementById( 'manage-cart-close' );

		if ( ! trigger || ! panel || ! overlay ) {
			return;
		}

		var PANEL_TRANSITION_MS = 320;
		var isOpen = false;
		var hideTimer = null;

		// "Hide floating cart when cart is empty" (Phase 3E-2 Floating Cart
		// setting). currentItemCount starts from the count the server
		// already rendered into data-manage-cart-item-count, and is kept in
		// sync by updateTriggerCount() below on every later cart change, so
		// this reflects the real cart even when the numeric badge itself is
		// turned off (see "Show item count badge").
		var hideWhenEmpty    = !! ( window.ManageCartFrontend && window.ManageCartFrontend.hideWhenEmpty );
		var currentItemCount = parseInt( trigger.getAttribute( 'data-manage-cart-item-count' ), 10 );
		if ( isNaN( currentItemCount ) ) {
			currentItemCount = 0;
		}

		/**
		 * Shows or hides the floating trigger. The existing "hidden while
		 * the panel is open" rule (Phase 3B-1 Part 4) always takes
		 * priority and is unchanged by this; only when the panel is
		 * closed does the "Hide floating cart when cart is empty" setting
		 * (when on) additionally keep the trigger hidden while the cart
		 * has no items.
		 *
		 * @return void
		 */
		function syncTriggerVisibility() {
			if ( isOpen ) {
				trigger.hidden = true;
				return;
			}

			trigger.hidden = hideWhenEmpty && 0 === currentItemCount;
		}

		function onKeydown( event ) {
			if ( 'Escape' === event.key || 'Esc' === event.key ) {
				closePanel();
			}
		}

		/**
		 * Keeps every Menu Cart Trigger shortcode button's
		 * `aria-expanded` in sync with the shared panel open/closed
		 * state, the same way the floating trigger's own
		 * `aria-expanded` already is. Reuses the panel's single
		 * `isOpen` state as its only source of truth — this never
		 * tracks a second open/closed state of its own.
		 *
		 * @param {boolean} expanded Whether the panel is now open.
		 * @return {void}
		 */
		function syncMenuTriggersExpanded( expanded ) {
			var menuTriggers = document.querySelectorAll( '[data-manage-cart-menu-trigger]' );

			for ( var i = 0; i < menuTriggers.length; i++ ) {
				menuTriggers[ i ].setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
			}
		}

		function openPanel() {
			if ( isOpen ) {
				return;
			}

			if ( hideTimer ) {
				window.clearTimeout( hideTimer );
				hideTimer = null;
			}

			isOpen = true;
			panel.hidden = false;
			overlay.hidden = false;
			document.body.classList.add( 'manage-cart-scroll-lock' );
			trigger.setAttribute( 'aria-expanded', 'true' );
			syncMenuTriggersExpanded( true );

			// Hide the floating trigger completely while the panel is open
			// (Phase 3B-1 Part 4). Its saved draggable position is kept in
			// inline left/top styles, which this does not touch, so it
			// reappears exactly where it was once the panel closes.
			trigger.hidden = true;

			window.requestAnimationFrame( function () {
				panel.classList.add( 'is-open' );
				overlay.classList.add( 'is-open' );
			} );

			document.addEventListener( 'keydown', onKeydown, true );

			var focusTarget = closeBtn || panel;
			window.setTimeout( function () {
				focusTarget.focus();
			}, 10 );
		}

		function closePanel() {
			if ( ! isOpen ) {
				return;
			}

			isOpen = false;
			panel.classList.remove( 'is-open' );
			overlay.classList.remove( 'is-open' );
			document.body.classList.remove( 'manage-cart-scroll-lock' );
			trigger.setAttribute( 'aria-expanded', 'false' );
			syncMenuTriggersExpanded( false );
			document.removeEventListener( 'keydown', onKeydown, true );

			// Show the floating trigger again, however the panel was closed
			// (close button, overlay click, Escape, or the trigger's own
			// toggle logic) — unless "Hide floating cart when cart is
			// empty" is on and the cart currently has no items, in which
			// case syncTriggerVisibility() keeps it hidden. Re-clamp its
			// saved position in case the viewport changed while it was
			// hidden; reclampCustomPosition() is a no-op unless a custom
			// position was actually saved (and unless the trigger is still
			// hidden, in which case it skips itself).
			syncTriggerVisibility();
			reclampCustomPosition();

			hideTimer = window.setTimeout( function () {
				panel.hidden = true;
				overlay.hidden = true;
				hideTimer = null;
			}, PANEL_TRANSITION_MS );

			// Only move focus to the trigger if it's actually visible;
			// focusing a hidden button (cart empty + "hide when empty")
			// would silently fail to do anything useful.
			if ( ! trigger.hidden ) {
				trigger.focus();
			}
		}

		/**
		 * Draggable trigger (Phase 3A.2).
		 *
		 * Pointer Events only (covers mouse + touch + pen), no external
		 * libraries. Dragging is only ever initiated while the panel is
		 * closed (aria-expanded="false"); a plain click/tap without
		 * meaningful movement still toggles the panel as before.
		 */
		var STORAGE_KEY     = 'manage-cart-trigger-position';
		var DRAG_MARGIN     = 8;  // Safe margin (px) kept from any viewport edge.
		var DRAG_THRESHOLD  = 6;  // Movement (px) before a pointer-down counts as a real drag.

		var supportsPointerEvents = !! window.PointerEvent;
		var allowTriggerDrag      = !! ( window.ManageCartFrontend && window.ManageCartFrontend.allowTriggerDrag );
		var hasCustomPosition     = false;
		var isDragging            = false;
		var dragMoved              = false;
		var dragPointerId          = null;
		var dragStartX              = 0;
		var dragStartY              = 0;
		var triggerStartLeft        = 0;
		var triggerStartTop         = 0;
		var suppressNextClick       = false;

		function getTriggerRect() {
			return trigger.getBoundingClientRect();
		}

		function clampPosition( left, top ) {
			var rect   = getTriggerRect();
			var width  = rect.width || trigger.offsetWidth || 58;
			var height = rect.height || trigger.offsetHeight || 58;

			var minLeft = DRAG_MARGIN;
			var minTop  = DRAG_MARGIN;
			var maxLeft = window.innerWidth - width - DRAG_MARGIN;
			var maxTop  = window.innerHeight - height - DRAG_MARGIN;

			if ( maxLeft < minLeft ) {
				maxLeft = minLeft;
			}

			if ( maxTop < minTop ) {
				maxTop = minTop;
			}

			return {
				left: Math.min( Math.max( left, minLeft ), maxLeft ),
				top:  Math.min( Math.max( top, minTop ), maxTop )
			};
		}

		function applyPosition( left, top ) {
			trigger.style.left   = left + 'px';
			trigger.style.top    = top + 'px';
			trigger.style.right  = 'auto';
			trigger.style.bottom = 'auto';
		}

		function savePosition( left, top ) {
			try {
				window.localStorage.setItem( STORAGE_KEY, JSON.stringify( { left: left, top: top } ) );
			} catch ( error ) {
				// Storage unavailable (private mode, quota, disabled) — fail silently.
			}
		}

		function loadSavedPosition() {
			try {
				var raw = window.localStorage.getItem( STORAGE_KEY );

				if ( ! raw ) {
					return null;
				}

				var parsed = JSON.parse( raw );

				if ( parsed && 'number' === typeof parsed.left && 'number' === typeof parsed.top ) {
					return parsed;
				}
			} catch ( error ) {
				// Ignore malformed or unavailable storage.
			}

			return null;
		}

		function restoreSavedPosition() {
			var saved = loadSavedPosition();

			if ( ! saved ) {
				return;
			}

			var clamped = clampPosition( saved.left, saved.top );
			applyPosition( clamped.left, clamped.top );
			hasCustomPosition = true;
		}

		function reclampCustomPosition() {
			// While the panel is open the trigger is hidden (display:none
			// via the `hidden` attribute), so getBoundingClientRect() would
			// report a zero-size rect and this would clamp against the
			// wrong dimensions. Skip it until the trigger is visible again;
			// closePanel() re-runs this right after unhiding it.
			if ( ! hasCustomPosition || trigger.hidden ) {
				return;
			}

			var rect    = getTriggerRect();
			var clamped = clampPosition( rect.left, rect.top );
			applyPosition( clamped.left, clamped.top );
			savePosition( clamped.left, clamped.top );
		}

		function onViewportChange() {
			window.requestAnimationFrame( reclampCustomPosition );
		}

		function endDrag() {
			if ( ! isDragging ) {
				return;
			}

			isDragging = false;
			trigger.classList.remove( 'manage-cart-trigger--dragging' );

			if ( null !== dragPointerId ) {
				try {
					trigger.releasePointerCapture( dragPointerId );
				} catch ( error ) {
					// Capture may already be released; ignore.
				}
			}

			document.removeEventListener( 'pointermove', onPointerMove );
			document.removeEventListener( 'pointerup', onPointerUp );
			document.removeEventListener( 'pointercancel', onPointerCancel );

			if ( dragMoved ) {
				var rect    = getTriggerRect();
				var clamped = clampPosition( rect.left, rect.top );
				applyPosition( clamped.left, clamped.top );
				savePosition( clamped.left, clamped.top );
				hasCustomPosition = true;
				suppressNextClick = true;
			}

			dragPointerId = null;
			dragMoved = false;
		}

		function onPointerMove( event ) {
			if ( ! isDragging || event.pointerId !== dragPointerId ) {
				return;
			}

			var deltaX = event.clientX - dragStartX;
			var deltaY = event.clientY - dragStartY;

			if ( ! dragMoved && ( Math.abs( deltaX ) > DRAG_THRESHOLD || Math.abs( deltaY ) > DRAG_THRESHOLD ) ) {
				dragMoved = true;
			}

			if ( ! dragMoved ) {
				return;
			}

			// Prevent touch-scrolling/selection while an actual drag is in progress.
			event.preventDefault();

			var next = clampPosition( triggerStartLeft + deltaX, triggerStartTop + deltaY );
			applyPosition( next.left, next.top );
		}

		function onPointerUp( event ) {
			if ( event.pointerId !== dragPointerId ) {
				return;
			}

			endDrag();
		}

		function onPointerCancel( event ) {
			if ( event.pointerId !== dragPointerId ) {
				return;
			}

			endDrag();
		}

		function onPointerDown( event ) {
			// Dragging is only ever initiated while the panel is closed.
			if ( 'false' !== trigger.getAttribute( 'aria-expanded' ) ) {
				return;
			}

			// Ignore non-primary mouse buttons (right-click, middle-click).
			if ( 'mouse' === event.pointerType && 0 !== event.button ) {
				return;
			}

			isDragging     = true;
			dragMoved      = false;
			dragPointerId  = event.pointerId;

			var rect = getTriggerRect();
			triggerStartLeft = rect.left;
			triggerStartTop  = rect.top;
			dragStartX       = event.clientX;
			dragStartY       = event.clientY;

			trigger.classList.add( 'manage-cart-trigger--dragging' );

			try {
				trigger.setPointerCapture( dragPointerId );
			} catch ( error ) {
				// Pointer capture is best-effort; continue without it if unsupported.
			}

			document.addEventListener( 'pointermove', onPointerMove );
			document.addEventListener( 'pointerup', onPointerUp );
			document.addEventListener( 'pointercancel', onPointerCancel );
		}

		// Dragging (and its localStorage restore / resize / orientation
		// handlers) is only initialized when the admin-controlled
		// "allow_trigger_drag" setting is enabled. When disabled, any
		// previously saved visitor position in localStorage is left
		// untouched so it can be restored later if the admin re-enables
		// the feature; the trigger simply stays in its default position
		// and behaves as a regular clickable button.
		if ( supportsPointerEvents && allowTriggerDrag ) {
			restoreSavedPosition();

			trigger.addEventListener( 'pointerdown', onPointerDown );
			window.addEventListener( 'resize', onViewportChange );
			window.addEventListener( 'orientationchange', onViewportChange );
		}

		trigger.addEventListener( 'click', function ( event ) {
			if ( suppressNextClick ) {
				suppressNextClick = false;
				event.preventDefault();
				return;
			}

			if ( isOpen ) {
				closePanel();
			} else {
				openPanel();
			}
		} );

		overlay.addEventListener( 'click', closePanel );

		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', closePanel );
		}

		/**
		 * Menu Cart Trigger shortcode ([manage_cart_trigger] — see
		 * class-menu-cart-trigger.php). Any number of these buttons can
		 * exist on a page (a header, a mobile menu, etc.), each marked
		 * with the `data-manage-cart-menu-trigger` attribute, so this
		 * binds via a single delegated click listener on the document
		 * rather than one addEventListener() per button — that also
		 * covers a menu-trigger button added to the page after this
		 * script ran (e.g. inside a lazy-loaded off-canvas menu), the
		 * same way this closure only ever finds one floating `trigger`
		 * once, above. It reuses the exact same openPanel()/closePanel()
		 * this file already uses for the floating trigger — the same
		 * overlay, focus handling, and Escape handling, with no second
		 * copy of any of it — and it does not open or manage anything
		 * else.
		 *
		 * Handles keyboard activation for free: each button is a real
		 * `<button type="button">` (see class-menu-cart-trigger.php), so
		 * the browser itself already dispatches a native `click` event
		 * for both Enter and Space, which this delegated listener
		 * receives exactly like a pointer click. No separate keydown
		 * handler is needed or added.
		 */
		document.addEventListener( 'click', function ( event ) {
			var menuTrigger = event.target.closest
				? event.target.closest( '[data-manage-cart-menu-trigger]' )
				: null;

			if ( ! menuTrigger ) {
				return;
			}

			event.preventDefault();

			if ( isOpen ) {
				closePanel();
			} else {
				openPanel();
			}
		} );

		/**
		 * Cart item actions (Phase 3B-1 Part 2): quantity +/- and remove,
		 * wired to the WooCommerce Store API. No jQuery, no custom PHP
		 * AJAX endpoint — this talks to WooCommerce's own
		 * /wc/store/v1/cart/update-item and /wc/store/v1/cart/remove-item
		 * REST routes directly.
		 */
		var config        = window.ManageCartFrontend || {};
		var storeApiNonce = config.storeApiNonce || '';
		var updateItemUrl = config.updateItemUrl || '';
		var removeItemUrl = config.removeItemUrl || '';
		var applyCouponUrl = config.applyCouponUrl || '';
		var removeCouponUrl = config.removeCouponUrl || '';
		var addItemUrl    = config.addItemUrl || '';
		var cartApiUrl    = config.cartApiUrl || '';
		var couponsEnabled = !! config.couponsEnabled;
		var i18n          = config.i18n || {};
		var statusEl      = document.getElementById( config.statusId || 'manage-cart-status' );
		var pricesIncludeTax = !! config.pricesIncludeTax;
		var SVG_NS        = 'http://www.w3.org/2000/svg';

		// Side Cart display toggles, localized once at page load (not part
		// of the live Store API response), used to mirror the same
		// show/hide rules Cart_Renderer applies server-side when the
		// script rebuilds cart item rows from that response.
		var displaySettings = {
			showProductImage:        !! config.showProductImage,
			showVariationAttributes: !! config.showVariationAttributes,
			showQuantityControls:    !! config.showQuantityControls,
			showRemoveButton:        !! config.showRemoveButton,
			showLowStockBadge:       !! config.showLowStockBadge
		};

		// Same original ManageCart basket glyph used server-side for the
		// trigger icon, the panel title icon, and the empty-cart state
		// (see Cart_Renderer::BASKET_ICON_PATH_D in class-cart-renderer.php).
		// An arched handle, a reinforced top rim, and a tapered basket body
		// with two woven-slat cut-out lines, drawn with an evenodd fill
		// rule — not sourced from any icon font, icon library, CDN, or
		// third-party plugin. Kept as a single constant so the empty-cart
		// icon built below stays in sync with it.
		var CART_ICON_PATH_D = 'M7.4 9C7.4 4.7 9.8 2.4 12 2.4C14.2 2.4 16.6 4.7 16.6 9L15.1 9C15.1 5.7 13.4 4 12 4C10.6 4 8.9 5.7 8.9 9Z M3 9h18v2H3V9Z M4.2 11L19.8 11L17.8 20L6.2 20Z M9.5 12.5h1v6h-1v-6Z M13.5 12.5h1v6h-1v-6Z';

		/**
		 * Writes a message to the aria-live status region. The text is
		 * cleared first and re-set on the next tick so assistive
		 * technology re-announces it even if it's identical to the
		 * previous message.
		 *
		 * @param {string} message Message to announce.
		 * @return {void}
		 */
		function announce( message ) {
			if ( ! statusEl || ! message ) {
				return;
			}

			statusEl.textContent = '';

			window.setTimeout( function () {
				statusEl.textContent = message;
			}, 30 );
		}

		/**
		 * Looks up the interactive controls belonging to one cart item.
		 *
		 * @param {string} itemKey Cart item key.
		 * @return {Object|null}
		 */
		function getItemControls( itemKey ) {
			if ( ! panel || ! itemKey ) {
				return null;
			}

			var selectorSuffix = '[data-cart-item-key="' + itemKey + '"]';

			return {
				row:         panel.querySelector( '.manage-cart-item' + selectorSuffix ),
				decreaseBtn: panel.querySelector( '[data-manage-cart-qty-decrease]' + selectorSuffix ),
				increaseBtn: panel.querySelector( '[data-manage-cart-qty-increase]' + selectorSuffix ),
				input:       panel.querySelector( '[data-manage-cart-qty-input]' + selectorSuffix ),
				removeBtn:   panel.querySelector( '[data-manage-cart-remove]' + selectorSuffix )
			};
		}

		/**
		 * Disables/enables one item's own controls while its request is
		 * pending. Other items' controls are left untouched.
		 *
		 * @param {Object}  controls Result of getItemControls().
		 * @param {boolean} pending  Whether a request is in flight.
		 * @return {void}
		 */
		function setItemPending( controls, pending ) {
			if ( ! controls ) {
				return;
			}

			[ controls.decreaseBtn, controls.increaseBtn, controls.input, controls.removeBtn ].forEach( function ( el ) {
				if ( el ) {
					el.disabled = pending;
				}
			} );

			if ( controls.row ) {
				controls.row.classList.toggle( 'manage-cart-item--pending', pending );
			}
		}

		/**
		 * Removes one item's row from the panel.
		 *
		 * @param {Object} controls Result of getItemControls().
		 * @return {void}
		 */
		function removeItemRow( controls ) {
			if ( controls && controls.row && controls.row.parentNode ) {
				controls.row.parentNode.removeChild( controls.row );
			}
		}

		/**
		 * Builds an inline SVG icon element via the SVG namespace (never
		 * innerHTML), for the empty-cart state below.
		 *
		 * @param {number} size Width and height, in pixels.
		 * @return {SVGElement}
		 */
		function createCartIcon( size ) {
			var svg = document.createElementNS( SVG_NS, 'svg' );
			svg.setAttribute( 'viewBox', '0 0 24 24' );
			svg.setAttribute( 'width', String( size ) );
			svg.setAttribute( 'height', String( size ) );
			svg.setAttribute( 'focusable', 'false' );

			var path = document.createElementNS( SVG_NS, 'path' );
			path.setAttribute( 'fill', 'currentColor' );
			path.setAttribute( 'fill-rule', 'evenodd' );
			path.setAttribute( 'clip-rule', 'evenodd' );
			path.setAttribute( 'd', CART_ICON_PATH_D );
			svg.appendChild( path );

			return svg;
		}

		/**
		 * Formats an integer amount given in a currency's smallest unit
		 * (as the Store API returns all monetary values) into a display
		 * string, using that response's own currency fields. Built by
		 * hand (no Intl dependency) so it matches whatever
		 * separators/prefix/suffix the store itself reports.
		 *
		 * @param {string|number} minorAmount Amount in the currency's minor unit.
		 * @param {Object}        currency    An object carrying the Store API's
		 *                                    currency_minor_unit / currency_prefix /
		 *                                    currency_suffix / currency_decimal_separator /
		 *                                    currency_thousand_separator fields.
		 * @return {string}
		 */
		function formatMoney( minorAmount, currency ) {
			currency = currency || {};

			var minorUnit    = ( 'number' === typeof currency.currency_minor_unit ) ? currency.currency_minor_unit : 2;
			var prefix       = ( 'string' === typeof currency.currency_prefix ) ? currency.currency_prefix : '';
			var suffix       = ( 'string' === typeof currency.currency_suffix ) ? currency.currency_suffix : '';
			var decimalSep   = ( 'string' === typeof currency.currency_decimal_separator ) ? currency.currency_decimal_separator : '.';
			var thousandSep  = ( 'string' === typeof currency.currency_thousand_separator ) ? currency.currency_thousand_separator : ',';

			var amount = parseInt( minorAmount, 10 );
			if ( isNaN( amount ) ) {
				amount = 0;
			}

			var negative = amount < 0;
			amount = Math.abs( amount );

			var divisor      = Math.pow( 10, minorUnit );
			var integerPart  = Math.floor( amount / divisor );
			var fractionPart = amount - ( integerPart * divisor );

			var integerStr = String( integerPart );
			var groups     = [];
			while ( integerStr.length > 3 ) {
				groups.unshift( integerStr.slice( -3 ) );
				integerStr = integerStr.slice( 0, -3 );
			}
			groups.unshift( integerStr );
			integerStr = groups.join( thousandSep );

			var formatted = integerStr;

			if ( minorUnit > 0 ) {
				var fractionStr = String( fractionPart );
				while ( fractionStr.length < minorUnit ) {
					fractionStr = '0' + fractionStr;
				}
				formatted += decimalSep + fractionStr;
			}

			return ( negative ? '-' : '' ) + prefix + formatted + suffix;
		}

		/**
		 * Resolves the display amount for one cart item's line subtotal
		 * from a Store API item's `totals`, honoring the store's existing
		 * "tax-inclusive cart prices" setting (config.pricesIncludeTax)
		 * the same way the server-rendered subtotal does.
		 *
		 * @param {Object} item Store API cart item.
		 * @return {?string}
		 */
		function getItemSubtotalDisplay( item ) {
			if ( ! item || ! item.totals ) {
				return null;
			}

			var totals    = item.totals;
			var subtotal  = parseInt( totals.line_subtotal, 10 ) || 0;
			var taxAmount = parseInt( totals.line_subtotal_tax, 10 ) || 0;
			var amount    = pricesIncludeTax ? ( subtotal + taxAmount ) : subtotal;

			return formatMoney( amount, totals );
		}

		/**
		 * Resolves the display amount for the panel footer's cart
		 * subtotal from a Store API cart response's cart-level `totals`
		 * (`total_items`, the sum of all line items before discounts, tax,
		 * or shipping — the same figure the server-rendered footer shows).
		 *
		 * @param {Object} data Store API cart response.
		 * @return {?string}
		 */
		function getCartSubtotalDisplay( data ) {
			if ( ! data || ! data.totals ) {
				return null;
			}

			var totals    = data.totals;
			var subtotal  = parseInt( totals.total_items, 10 ) || 0;
			var taxAmount = parseInt( totals.total_items_tax, 10 ) || 0;
			var amount    = pricesIncludeTax ? ( subtotal + taxAmount ) : subtotal;

			return formatMoney( amount, totals );
		}

		/**
		 * Resolves the display amount for the panel footer's total
		 * savings row from a Store API cart response's `items` array —
		 * the sum of (unit regular price − unit current price) ×
		 * quantity for every item with a real discount (regular price
		 * greater than current price), in the currency's minor unit.
		 * Mirrors Cart_Renderer::get_cart_total_savings()'s server-side
		 * calculation, except (like that method) it does not require the
		 * per-line percentage to round to at least 1% the way the
		 * per-item "Save X%" badge does, since small per-line amounts
		 * can still add up to a meaningful total.
		 *
		 * Returns null (render nothing) when the total isn't a positive
		 * amount, e.g. no item in the cart currently has a real sale
		 * discount.
		 *
		 * @param {Object} data Store API cart response.
		 * @return {?string}
		 */
		function getCartTotalSavingsDisplay( data ) {
			if ( ! data || ! Array.isArray( data.items ) || 0 === data.items.length ) {
				return null;
			}

			var totalSavings = 0;
			var currency     = null;

			data.items.forEach( function ( item ) {
				var prices = item && item.prices;

				if ( ! prices ) {
					return;
				}

				var regularPrice = parseInt( prices.regular_price, 10 );
				var currentPrice = parseInt( prices.price, 10 );
				var quantity     = parseInt( item.quantity, 10 );

				if ( isNaN( regularPrice ) || isNaN( currentPrice ) || isNaN( quantity ) ) {
					return;
				}

				if ( regularPrice <= 0 || currentPrice < 0 || currentPrice >= regularPrice || quantity <= 0 ) {
					return;
				}

				totalSavings += ( regularPrice - currentPrice ) * quantity;

				if ( ! currency ) {
					currency = prices;
				}
			} );

			if ( totalSavings <= 0 ) {
				return null;
			}

			return formatMoney( totalSavings, currency || data.totals );
		}

		/**
		 * Resolves the display amount for one applied coupon's discount
		 * (Phase 3H-1) from a Store API coupon entry's own `totals`
		 * (`total_discount` / `total_discount_tax`), honoring the same
		 * tax-inclusive display setting (config.pricesIncludeTax) the
		 * subtotal and line-item helpers above use, so the figure matches
		 * Cart_Renderer::render_applied_coupons()'s server-rendered
		 * amount for the same coupon.
		 *
		 * @param {Object} coupon One entry from the Store API cart response's `coupons` array.
		 * @return {?string}
		 */
		function getCouponDiscountDisplay( coupon ) {
			if ( ! coupon || ! coupon.totals ) {
				return null;
			}

			var totals    = coupon.totals;
			var discount  = parseInt( totals.total_discount, 10 ) || 0;
			var taxAmount = parseInt( totals.total_discount_tax, 10 ) || 0;
			var amount    = pricesIncludeTax ? ( discount + taxAmount ) : discount;

			return formatMoney( amount, totals );
		}

		/**
		 * Resolves the display amount for the panel footer's aggregate
		 * "Coupon discount" row directly from the Store API cart
		 * response's cart-level `totals.total_discount` (plus
		 * `totals.total_discount_tax` when prices are displayed inclusive
		 * of tax, honoring config.pricesIncludeTax the same way every
		 * other cart-level total helper here does — see
		 * getCartSubtotalDisplay()/getCartTotalDisplay()). `total_discount`
		 * is WooCommerce's own real, already-aggregated discount across
		 * every currently applied coupon, so this never re-sums the
		 * per-coupon `coupons[].totals` entries itself (those are read
		 * separately by getCouponDiscountDisplay() for each coupon's own
		 * row in the applied-coupons list) and never parses any formatted
		 * price string.
		 *
		 * Returns null (render nothing) when there is no positive
		 * discount to show — e.g. no coupon is currently applied, which
		 * is exactly when a coupon has just been removed, so this row
		 * disappears on the very next response along with the Total row's
		 * value updating to match.
		 *
		 * @param {Object} data Store API cart response.
		 * @return {?string}
		 */
		function getCartCouponDiscountDisplay( data ) {
			if ( ! data || ! data.totals || 'undefined' === typeof data.totals.total_discount ) {
				return null;
			}

			var totals    = data.totals;
			var discount  = parseInt( totals.total_discount, 10 ) || 0;
			var taxAmount = parseInt( totals.total_discount_tax, 10 ) || 0;
			var amount    = pricesIncludeTax ? ( discount + taxAmount ) : discount;

			if ( amount <= 0 ) {
				return null;
			}

			return formatMoney( amount, totals );
		}

		/**
		 * Resolves the display amount for the panel footer's prominent
		 * "Total" row from a Store API cart response's cart-level
		 * `totals.total_price` — WooCommerce's own real final cart total
		 * after discounts, tax, fees, and calculated shipping, exactly
		 * matching Cart_Renderer::render_total_row()'s server-side
		 * `WC_Cart::get_total()` figure. Unlike the Subtotal/line-item
		 * helpers above, this is never adjusted for
		 * config.pricesIncludeTax: `total_price` is already the real,
		 * final amount the customer pays, which always includes
		 * applicable tax regardless of how individual prices are
		 * *displayed* elsewhere in the cart.
		 *
		 * @param {Object} data Store API cart response.
		 * @return {?string}
		 */
		function getCartTotalDisplay( data ) {
			if ( ! data || ! data.totals || 'undefined' === typeof data.totals.total_price ) {
				return null;
			}

			var amount = parseInt( data.totals.total_price, 10 ) || 0;

			return formatMoney( amount, data.totals );
		}

		/**
		 * Reads the cart's current item count from a Store API cart
		 * response, preferring the dedicated `items_count` field and
		 * falling back to the length of the `items` array.
		 *
		 * @param {Object} data Store API cart response.
		 * @return {?number}
		 */
		function getResponseItemCount( data ) {
			if ( ! data ) {
				return null;
			}

			if ( 'number' === typeof data.items_count ) {
				return data.items_count;
			}

			if ( Array.isArray( data.items ) ) {
				return data.items.length;
			}

			return null;
		}

		/**
		 * Updates the floating trigger's visible count badge (when the
		 * "Show item count badge" setting has it present in the DOM at
		 * all) and its aria-label (which always states the count for
		 * assistive tech, regardless of that setting). Also keeps the
		 * trigger's own `data-manage-cart-item-count` attribute and its
		 * "Hide floating cart when cart is empty" visibility in sync with
		 * every live cart change, not just the initial server-rendered
		 * count (Phase 3E-2).
		 *
		 * @param {number} count Current cart item count.
		 * @return {void}
		 */
		function updateTriggerCount( count ) {
			var badge = trigger.querySelector( '[data-manage-cart-count]' );

			if ( badge ) {
				badge.textContent = String( count );
				badge.classList.toggle( 'manage-cart-trigger-count--zero', 0 === count );
			}

			var template = ( 1 === count )
				? ( i18n.triggerLabelSingle || 'Open cart, %d item' )
				: ( i18n.triggerLabelPlural || 'Open cart, %d items' );

			trigger.setAttribute( 'aria-label', template.replace( '%d', String( count ) ) );

			trigger.setAttribute( 'data-manage-cart-item-count', String( count ) );
			currentItemCount = count;
			syncTriggerVisibility();

			updateMenuTriggers( count );
		}

		/**
		 * Keeps every Menu Cart Trigger shortcode button
		 * (`[data-manage-cart-menu-trigger]`, see
		 * class-menu-cart-trigger.php) in sync with the same live count
		 * this file already applies to the floating trigger above: its
		 * `aria-label`, its `data-manage-cart-item-count` attribute, and
		 * its numeric badge, which — matching the shortcode's own
		 * server-side rule — is only present in the DOM while the count
		 * is greater than zero, so it's created here on the first item
		 * and removed again if the cart returns to empty. No badge
		 * markup is ever built from server/Store API response data;
		 * only the known-safe integer `count` this function already
		 * received is written, and only via createElement()/.textContent,
		 * never innerHTML.
		 *
		 * @param {number} count Current cart item count.
		 * @return {void}
		 */
		function updateMenuTriggers( count ) {
			var menuTriggers = document.querySelectorAll( '[data-manage-cart-menu-trigger]' );

			if ( ! menuTriggers.length ) {
				return;
			}

			var template = ( 1 === count )
				? ( i18n.triggerLabelSingle || 'Open cart, %d item' )
				: ( i18n.triggerLabelPlural || 'Open cart, %d items' );
			var label = template.replace( '%d', String( count ) );

			for ( var i = 0; i < menuTriggers.length; i++ ) {
				var menuTrigger = menuTriggers[ i ];

				menuTrigger.setAttribute( 'aria-label', label );
				menuTrigger.setAttribute( 'data-manage-cart-item-count', String( count ) );

				var badge = menuTrigger.querySelector( '[data-manage-cart-menu-count]' );

				if ( count > 0 ) {
					if ( ! badge ) {
						badge = document.createElement( 'span' );
						badge.className = 'manage-cart-menu-trigger-count';
						badge.setAttribute( 'data-manage-cart-menu-count', '' );
						badge.setAttribute( 'aria-hidden', 'true' );
						menuTrigger.appendChild( badge );
					}

					badge.textContent = String( count );
				} else if ( badge && badge.parentNode ) {
					badge.parentNode.removeChild( badge );
				}
			}
		}

		/**
		 * Updates the "(N)" item count shown next to the panel heading,
		 * when that optional element is present (Side Cart "Show item
		 * count" setting).
		 *
		 * @param {number} count Current cart item count.
		 * @return {void}
		 */
		function updateHeadingCount( count ) {
			var countEl = panel.querySelector( '.manage-cart-panel-title-count' );

			if ( countEl ) {
				countEl.textContent = '(' + count + ')';
			}
		}

		/**
		 * Updates the footer subtotal value, when the footer is present
		 * (it is omitted entirely once the cart is empty).
		 *
		 * @param {Object} data Store API cart response.
		 * @return {void}
		 */
		function updateFooterSubtotal( data ) {
			var subtotalEl = panel.querySelector( '.manage-cart-subtotal-value' );

			if ( ! subtotalEl ) {
				return;
			}

			var display = getCartSubtotalDisplay( data );

			if ( null !== display ) {
				subtotalEl.textContent = display;
			}
		}

		/**
		 * Updates (inserts, refreshes, or removes) the "Product savings"
		 * total savings row above the Subtotal row, when the footer is present
		 * (it is omitted entirely once the cart is empty) — the
		 * incremental-update counterpart to buildSavingsRow(), used after
		 * a quantity change or item removal so the row stays correct
		 * without a full footer rebuild. Removes the row when the
		 * current response no longer has any savings to show (e.g. the
		 * last discounted item was just removed or its quantity dropped
		 * to 0), and inserts it — right before the Subtotal row — the
		 * first time a discounted item appears in an otherwise
		 * savings-free cart.
		 *
		 * @param {Object} data Store API cart response.
		 * @return {void}
		 */
		function updateFooterSavings( data ) {
			var subtotalRow = panel.querySelector( '.manage-cart-subtotal-row' );

			if ( ! subtotalRow || ! subtotalRow.parentNode ) {
				return;
			}

			var existingRow = panel.querySelector( '.manage-cart-savings-row' );
			var newRow      = buildSavingsRow( data );

			if ( existingRow && existingRow.parentNode ) {
				existingRow.parentNode.removeChild( existingRow );
			}

			if ( newRow ) {
				subtotalRow.parentNode.insertBefore( newRow, subtotalRow );
			}
		}

		/**
		 * Updates (inserts, refreshes, or removes) the aggregate "Coupon
		 * discount" row shown below the Subtotal row, when the footer is
		 * present — the incremental-update counterpart to
		 * buildCouponDiscountRow(), used after a quantity change or item
		 * removal so a percentage-based coupon's discount amount (which
		 * changes along with the subtotal it's a percentage of) stays
		 * correct without a full footer rebuild. Removes the row when the
		 * current response no longer has a positive coupon discount (e.g.
		 * the last coupon was removed, handled via applyPanelRefresh()
		 * rather than here, or every applied coupon now resolves to a
		 * zero discount), and inserts it — right before the Total row —
		 * the first time a discount appears.
		 *
		 * @param {Object} data Store API cart response.
		 * @return {void}
		 */
		function updateFooterCouponDiscount( data ) {
			var subtotalRow = panel.querySelector( '.manage-cart-subtotal-row' );
			var totalRow    = panel.querySelector( '.manage-cart-total-row' );

			if ( ! subtotalRow || ! subtotalRow.parentNode ) {
				return;
			}

			var existingRow = panel.querySelector( '.manage-cart-coupon-discount-row' );
			var newRow      = buildCouponDiscountRow( data );

			if ( existingRow && existingRow.parentNode ) {
				existingRow.parentNode.removeChild( existingRow );
			}

			if ( newRow ) {
				if ( totalRow && totalRow.parentNode ) {
					totalRow.parentNode.insertBefore( newRow, totalRow );
				} else {
					subtotalRow.parentNode.insertBefore( newRow, subtotalRow.nextSibling );
				}
			}
		}

		/**
		 * Updates the prominent "Total" row's value, when the footer is
		 * present (it is omitted entirely once the cart is empty).
		 * The Total row itself is always present once the footer is
		 * present (see buildFooter()/Cart_Renderer::render_total_row()),
		 * so — unlike the Product savings and Coupon discount rows — this
		 * never needs to insert or remove the row, only refresh its text.
		 *
		 * @param {Object} data Store API cart response.
		 * @return {void}
		 */
		function updateFooterTotal( data ) {
			var totalEl = panel.querySelector( '.manage-cart-total-value' );

			if ( ! totalEl ) {
				return;
			}

			var display = getCartTotalDisplay( data );

			if ( null !== display ) {
				totalEl.textContent = display;
			}
		}

		/**
		 * Updates (rebuilds) the applied-coupons list — each applied
		 * coupon's own code/amount/Remove row, shown above the "Have a
		 * coupon?" toggle — from the latest Store API cart response,
		 * when the coupon section is present at all (it's omitted
		 * entirely when WooCommerce coupons are disabled store-wide, or
		 * before the panel's first Store API response). This is the
		 * incremental-update counterpart to buildAppliedCouponsList(),
		 * used after a quantity change or item removal (via
		 * applyCartWideUpdates()) so a percentage coupon's displayed
		 * discount amount — which changes along with the subtotal it's
		 * a percentage of — never goes stale, the same way
		 * updateFooterCouponDiscount() already keeps the cart-wide
		 * Coupon discount row correct for the same actions.
		 *
		 * Only the list itself is added, replaced, or removed — the
		 * "Have a coupon?" toggle button and its form are never
		 * touched, so the customer's open/closed toggle state (and
		 * anything already typed into the coupon input) is preserved.
		 * The existing list, if any, is always removed before a new one
		 * is inserted in its place, so this never leaves two lists in
		 * the DOM at once. Coupon apply/remove themselves still go
		 * through applyPanelRefresh()'s full footer rebuild, which
		 * already builds a correct, single list of its own.
		 *
		 * @param {Object} data Store API cart response.
		 * @return {void}
		 */
		function updateFooterAppliedCoupons( data ) {
			var wrapper = panel.querySelector( '[data-manage-cart-coupon]' );

			if ( ! wrapper ) {
				return;
			}

			var existingList = wrapper.querySelector( '[data-manage-cart-coupon-list]' );

			if ( existingList && existingList.parentNode ) {
				existingList.parentNode.removeChild( existingList );
			}

			var newList = buildAppliedCouponsList( data && data.coupons );

			if ( newList ) {
				wrapper.insertBefore( newList, wrapper.firstChild );
			}
		}

		/**
		 * Applies the cart-wide parts of a successful Store API cart
		 * response to the open panel: the trigger badge/aria-label, the
		 * heading count, the applied-coupons list, the footer subtotal,
		 * the Product savings row, the Coupon discount row, and the
		 * Total row. Does not touch any individual item row — callers
		 * handle that themselves, since the right per-row update differs
		 * between a quantity change and a removal.
		 *
		 * The applied-coupons list (each applied coupon's own code/
		 * amount/Remove row) is included here as of Phase 3H-2, since a
		 * percentage coupon's per-coupon discount amount changes along
		 * with the subtotal it's a percentage of — without this, that
		 * list could show a stale amount after a quantity change or
		 * item removal even though the cart-wide Coupon discount and
		 * Total rows below already update correctly. Coupon apply/
		 * remove themselves still go through applyPanelRefresh()'s full
		 * footer rebuild instead of this function, which already builds
		 * a correct, freshly-built list.
		 *
		 * @param {Object} data Store API cart response.
		 * @return {void}
		 */
		/**
		 * Name of the public "cart updated" extension event.
		 *
		 * This is a generic ManageCart extension point, not tied to any
		 * particular add-on: any script (a Pro add-on, a theme, a site's
		 * own customization) can listen for it to react to a cart change
		 * ManageCart has just applied, without having to observe network
		 * traffic or watch the DOM for changes.
		 *
		 * @type {string}
		 */
		var CART_UPDATED_EVENT = 'managecart:cart-updated';

		// Latest Store API cart response awaiting dispatch, and whether a
		// dispatch is already scheduled for this tick.
		var pendingCartUpdate      = null;
		var cartUpdateDispatchSet  = false;

		/**
		 * Queues the public `managecart:cart-updated` event for the
		 * Store API cart response just applied.
		 *
		 * Dispatch is deliberately deferred to the end of the current
		 * task rather than fired inline, for two reasons:
		 *
		 * - Some callers do more DOM work *after* calling into the
		 *   update functions below (finishRemoval(), for example, swaps
		 *   in the empty-cart state and moves focus after calling
		 *   applyCartWideUpdates()). Deferring guarantees listeners only
		 *   ever see the panel in its finished state, which is the
		 *   contract this event promises.
		 * - A single user action that touches both update paths still
		 *   results in exactly one event, carrying the most recent cart
		 *   response.
		 *
		 * Listeners receive the raw, unmodified Store API cart response
		 * as `event.detail.cart`. The event is dispatched on
		 * document.body and bubbles, so listeners bound to document.body,
		 * document, or window all receive it.
		 *
		 * Any failure here is swallowed: an extension point must never be
		 * able to break ManageCart's own cart behavior.
		 *
		 * @param {Object} data Store API cart response just applied.
		 * @return {void}
		 */
		function scheduleCartUpdatedEvent( data ) {
			if ( ! data ) {
				return;
			}

			pendingCartUpdate = data;

			if ( cartUpdateDispatchSet ) {
				return;
			}

			cartUpdateDispatchSet = true;

			window.setTimeout( function () {
				var payload = pendingCartUpdate;

				cartUpdateDispatchSet = false;
				pendingCartUpdate     = null;

				if ( ! payload ) {
					return;
				}

				try {
					var target = document.body || document;

					target.dispatchEvent( new CustomEvent( CART_UPDATED_EVENT, {
						bubbles: true,
						cancelable: false,
						detail: { cart: payload }
					} ) );
				} catch ( e ) {
					// Never let a listener-facing dispatch failure affect
					// the cart itself.
				}
			}, 0 );
		}

		/**
		 * Per-item snapshots (product/variation identity, quantity, name)
		 * read from every real Store API cart response this script
		 * processes, keyed by cart item key. This is the sole source of
		 * "what was this row immediately before it was removed" that the
		 * Undo Removed Cart Item feature restores from — see
		 * captureUndoSnapshot()/offerUndo() below — kept fresh entirely
		 * from responses this script would fetch/receive anyway (add-to-
		 * cart, quantity change, coupon apply/remove, full refresh, and a
		 * one-time warm-up fetch on load — see warmUndoSnapshotCache()),
		 * so it never requires a request beyond the existing WooCommerce
		 * Store API cart calls already used elsewhere in this file.
		 *
		 * @type {Object<string, {id: number, quantity: number, name: string}>}
		 */
		var cartItemsCache = {};

		/**
		 * Refreshes cartItemsCache from a real Store API cart response's
		 * `items` array, and prunes any cached key no longer present so a
		 * stale snapshot can never be read back for an unrelated later
		 * item that happens to reuse a freed cart item key.
		 *
		 * @param {Object} data Store API cart response.
		 * @return {void}
		 */
		function updateItemsCache( data ) {
			if ( ! data || ! Array.isArray( data.items ) ) {
				return;
			}

			var freshKeys = {};

			data.items.forEach( function ( item ) {
				if ( ! item || ! item.key ) {
					return;
				}

				freshKeys[ item.key ] = true;
				cartItemsCache[ item.key ] = {
					id: item.id,
					quantity: item.quantity,
					name: item.name
				};
			} );

			for ( var key in cartItemsCache ) {
				if ( Object.prototype.hasOwnProperty.call( cartItemsCache, key ) && ! freshKeys[ key ] ) {
					delete cartItemsCache[ key ];
				}
			}
		}

		/**
		 * Reads the snapshot cartItemsCache currently holds for one cart
		 * item key — the product/variation identity and the quantity that
		 * existed immediately before whatever action is about to remove
		 * it — for the Undo notice to restore later. Returns null when
		 * nothing usable is cached (e.g. the item was removed before the
		 * one-time cache warm-up below ever completed), in which case
		 * callers simply never offer Undo for that removal rather than
		 * risk restoring the wrong thing.
		 *
		 * @param {string} itemKey Cart item key.
		 * @return {?Object}
		 */
		function captureUndoSnapshot( itemKey ) {
			var cached = cartItemsCache[ itemKey ];

			if ( ! cached || ! cached.id ) {
				return null;
			}

			return {
				id: cached.id,
				quantity: cached.quantity,
				name: cached.name
			};
		}

		function applyCartWideUpdates( data ) {
			updateItemsCache( data );

			var count = getResponseItemCount( data );

			if ( null !== count ) {
				updateTriggerCount( count );
				updateHeadingCount( count );
			}

			// Rebuild/update in the same order the footer displays them:
			// applied-coupons list, Product savings, Subtotal, Coupon
			// discount, Total. Each function positions its own
			// row/list via insertBefore()/removeChild() regardless of
			// call order, but keeping this order matches the visual
			// layout and keeps these rows easy to reason about together.
			updateFooterAppliedCoupons( data );
			updateFooterSavings( data );
			updateFooterSubtotal( data );
			updateFooterCouponDiscount( data );
			updateFooterTotal( data );

			// Public extension point — see scheduleCartUpdatedEvent().
			scheduleCartUpdatedEvent( data );
		}

		/**
		 * Auto-open on add-to-cart (Phase 3C).
		 *
		 * Listens for WooCommerce's own classic `added_to_cart` event
		 * (fired on document.body via jQuery by WooCommerce core's AJAX
		 * add-to-cart handling — there is no native DOM equivalent, so
		 * jQuery is used here only for this one listener, and only when
		 * WooCommerce has already loaded it). On every successful add it
		 * fetches WooCommerce's own real Store API cart
		 * (`GET /wc/store/v1/cart`) and:
		 *
		 * - Always refreshes the trigger badge, heading count, item list,
		 *   and footer so an already-open panel updates live in place —
		 *   the panel is never closed and reopened for this.
		 * - Opens the panel only if the "Auto-open cart after add to
		 *   cart" Side Cart setting is on and the panel isn't already
		 *   open, so the first add opens it and later adds while it's
		 *   open just update it.
		 *
		 * Once opened this way, the panel only ever closes through the
		 * customer's own action (close button, overlay click, Escape, or
		 * the trigger toggle) — nothing here closes it — and the next
		 * successful add-to-cart after that opens it again, since that
		 * check is simply "is the panel currently open".
		 *
		 * Quantity changes and removals never go through this path; they
		 * call applyCartWideUpdates() directly from their own Store API
		 * handlers below and never open the panel.
		 *
		 * Single Product Page Auto-open, Part 3: never auto-opens on the
		 * native Cart or Checkout page itself (config.isCartOrCheckoutPage,
		 * set server-side — see Assets::register_frontend_assets()). This
		 * matters for the `hasAddedToCartParam()` flow below: WooCommerce's
		 * own `added-to-cart` redirect argument can land the customer
		 * directly on the Cart page (e.g. "redirect to the cart page after
		 * successful addition"), and popping the Side Cart open on top of
		 * the page that already *is* the cart would be redundant and
		 * confusing. The panel's normal live refresh (badge, item list,
		 * footer) on those pages is unaffected — only the auto-open is
		 * suppressed.
		 */
		var autoOpenOnAdd = !! config.autoOpenOnAdd && ! config.isCartOrCheckoutPage;

		// SVG icon paths reused when building cart item rows from scratch
		// (see buildItemRow() below) — kept in sync with the equivalent
		// inline markup in class-cart-renderer.php.
		// Original ManageCart icon (lid, handle, can body with three
		// vertical cut-out lines), drawn with an evenodd fill rule — not
		// sourced from any external icon library or third-party plugin.
		// Kept in sync with the identical REMOVE_ICON_PATH_D constant in
		// class-cart-renderer.php.
		var REMOVE_ICON_PATH_D   = 'M5 6h14v2H5V6Z M9 3h6l.6 3H8.4L9 3Z M6 8h12l-1.1 12.1a2 2 0 0 1-2 2H9.1a2 2 0 0 1-2-2L6 8Z M9.3 10h1.4v8H9.3V10Z M11.3 10h1.4v8h-1.4V10Z M13.3 10h1.4v8h-1.4V10Z';
		var DECREASE_ICON_PATH_D = 'M5 11h14v2H5z';
		var INCREASE_ICON_PATH_D = 'M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6z';

		/**
		 * Builds an inline SVG icon element via the SVG namespace (never
		 * innerHTML), for cart item row controls.
		 *
		 * @param {number}  size     Width and height, in pixels.
		 * @param {string}  pathD    The icon's SVG path `d` attribute.
		 * @param {?string} fillRule Optional `fill-rule`/`clip-rule` value
		 *                           (e.g. 'evenodd' for icons whose path
		 *                           data relies on cut-out subpaths, like
		 *                           the remove/trash icon above).
		 * @return {SVGElement}
		 */
		function createSvgIcon( size, pathD, fillRule ) {
			var svg = document.createElementNS( SVG_NS, 'svg' );
			svg.setAttribute( 'viewBox', '0 0 24 24' );
			svg.setAttribute( 'width', String( size ) );
			svg.setAttribute( 'height', String( size ) );
			svg.setAttribute( 'focusable', 'false' );
			svg.setAttribute( 'aria-hidden', 'true' );

			var path = document.createElementNS( SVG_NS, 'path' );
			path.setAttribute( 'fill', 'currentColor' );
			if ( fillRule ) {
				path.setAttribute( 'fill-rule', fillRule );
				path.setAttribute( 'clip-rule', fillRule );
			}
			path.setAttribute( 'd', pathD );
			svg.appendChild( path );

			return svg;
		}

		/**
		 * Fills in a `%s`/`%d` template with a single value. Small local
		 * helper so the i18n label templates below read the same way the
		 * equivalent PHP sprintf()/printf() calls do — including treating
		 * a literal `%%` in the template as an escaped percent sign, the
		 * same way PHP's printf() family does. Without that last step, a
		 * translatable template such as "Save %d%%" (used so the msgid
		 * matches Cart_Renderer::render_item_sale_info()'s PHP-side
		 * `printf( __( 'Save %d%%', ... ), $percent_off )`) would leave
		 * the trailing `%%` untouched after the `%d` substitution and
		 * render as "Save 26%%" instead of "Save 26%".
		 *
		 * @param {string}        template Template containing one `%s` or `%d`, and optionally a literal `%%`.
		 * @param {string|number} value    Value to substitute.
		 * @return {string}
		 */
		function formatTemplate( template, value ) {
			return template.replace( '%s', value ).replace( '%d', value ).replace( /%%/g, '%' );
		}

		/**
		 * Resolves plain sale-display data for one Store API cart item,
		 * from its `prices` object (unit regular price vs. current
		 * price, both in the currency's minor unit, same as `totals`).
		 * Mirrors Cart_Renderer::render_item_sale_info()'s conditions:
		 * nothing is returned unless there's a real discount that rounds
		 * to at least 1%.
		 *
		 * @param {Object} item A Store API cart item.
		 * @return {?Object}
		 */
		function getItemSaleInfo( item ) {
			var prices = item && item.prices;

			if ( ! prices ) {
				return null;
			}

			var regularPrice = parseInt( prices.regular_price, 10 );
			var currentPrice = parseInt( prices.price, 10 );

			if ( isNaN( regularPrice ) || isNaN( currentPrice ) || regularPrice <= 0 || currentPrice < 0 || currentPrice >= regularPrice ) {
				return null;
			}

			var percentOff = Math.round( ( ( regularPrice - currentPrice ) / regularPrice ) * 100 );

			if ( percentOff <= 0 ) {
				return null;
			}

			return {
				regularPriceDisplay: formatMoney( regularPrice, prices ),
				saveLabel: formatTemplate( i18n.saveLabel || 'Save %d%%', percentOff )
			};
		}

		/**
		 * Resolves the "Only X left" low stock text for one Store API
		 * cart item from its `low_stock_remaining` field — WooCommerce's
		 * own computed remaining-stock figure, already accounting for
		 * stock tracking and backorders — when the "Show low stock
		 * badge" Side Cart setting is on.
		 *
		 * @param {Object} item A Store API cart item.
		 * @return {?string}
		 */
		function getLowStockText( item ) {
			if ( ! displaySettings.showLowStockBadge || ! item || 'number' !== typeof item.low_stock_remaining ) {
				return null;
			}

			return formatTemplate( i18n.lowStockText || 'Only %d left', item.low_stock_remaining );
		}

		/**
		 * Builds one cart item row (`<li class="manage-cart-item">`) from
		 * a real WooCommerce Store API cart item, via
		 * document.createElement()/.textContent only. Structure and
		 * classes match Cart_Renderer::render_cart_item() exactly — the
		 * remove button (when shown) is always the row's first control,
		 * keeping it at the top-left of the row — so the existing
		 * delegated click/change listeners and CSS apply without change.
		 *
		 * @param {Object} item     One entry from the Store API cart response's `items` array.
		 * @param {Object} settings The Side Cart display toggles (see displaySettings above).
		 * @return {HTMLElement}
		 */
		function buildItemRow( item, settings ) {
			var li = document.createElement( 'li' );
			li.className = 'manage-cart-item';
			li.setAttribute( 'data-cart-item-key', item.key );

			if ( settings.showRemoveButton || settings.showProductImage ) {
				var top = document.createElement( 'div' );
				top.className = 'manage-cart-item-top';

				if ( settings.showRemoveButton ) {
					var removeBtn = document.createElement( 'button' );
					removeBtn.type = 'button';
					removeBtn.className = 'manage-cart-item-remove';
					removeBtn.setAttribute( 'data-manage-cart-remove', '' );
					removeBtn.setAttribute( 'data-cart-item-key', item.key );
					var removeLabel = formatTemplate( i18n.removeLabel || 'Remove %s', item.name );
					removeBtn.setAttribute( 'aria-label', removeLabel );
					removeBtn.setAttribute( 'title', removeLabel );
					removeBtn.appendChild( createSvgIcon( 16, REMOVE_ICON_PATH_D, 'evenodd' ) );
					top.appendChild( removeBtn );
				}

				var thumbnail = ( item.images && item.images.length ) ? item.images[ 0 ] : null;

				if ( settings.showProductImage && thumbnail ) {
					var thumbWrap = document.createElement( 'div' );
					thumbWrap.className = 'manage-cart-item-thumb';

					var img = document.createElement( 'img' );
					img.setAttribute( 'src', thumbnail.thumbnail || thumbnail.src );
					if ( thumbnail.srcset ) {
						img.setAttribute( 'srcset', thumbnail.srcset );
					}
					if ( thumbnail.sizes ) {
						img.setAttribute( 'sizes', thumbnail.sizes );
					}
					img.alt = thumbnail.alt || item.name || '';

					if ( item.permalink ) {
						var thumbLink = document.createElement( 'a' );
						thumbLink.setAttribute( 'href', item.permalink );
						thumbLink.setAttribute( 'tabindex', '-1' );
						thumbLink.setAttribute( 'aria-hidden', 'true' );
						thumbLink.appendChild( img );
						thumbWrap.appendChild( thumbLink );
					} else {
						thumbWrap.appendChild( img );
					}

					top.appendChild( thumbWrap );
				}

				li.appendChild( top );
			}

			var details = document.createElement( 'div' );
			details.className = 'manage-cart-item-details';

			var nameWrap = document.createElement( 'div' );
			nameWrap.className = 'manage-cart-item-name';
			if ( item.permalink ) {
				var nameLink = document.createElement( 'a' );
				nameLink.setAttribute( 'href', item.permalink );
				nameLink.textContent = item.name;
				nameWrap.appendChild( nameLink );
			} else {
				var nameSpan = document.createElement( 'span' );
				nameSpan.textContent = item.name;
				nameWrap.appendChild( nameSpan );
			}
			details.appendChild( nameWrap );

			if ( settings.showVariationAttributes && Array.isArray( item.variation ) && item.variation.length ) {
				var attrsWrap = document.createElement( 'div' );
				attrsWrap.className = 'manage-cart-item-attrs';
				item.variation.forEach( function ( attribute ) {
					if ( ! attribute || ! attribute.attribute ) {
						return;
					}
					var attrSpan = document.createElement( 'span' );
					attrSpan.className = 'manage-cart-item-attr';
					attrSpan.textContent = attribute.attribute + ': ' + attribute.value;
					attrsWrap.appendChild( attrSpan );
				} );
				details.appendChild( attrsWrap );
			}

			var saleInfo = getItemSaleInfo( item );

			if ( saleInfo ) {
				var saleWrap = document.createElement( 'div' );
				saleWrap.className = 'manage-cart-item-sale';

				var regPriceSpan = document.createElement( 'span' );
				regPriceSpan.className = 'manage-cart-item-regular-price';
				var del = document.createElement( 'del' );
				del.textContent = saleInfo.regularPriceDisplay;
				regPriceSpan.appendChild( del );
				saleWrap.appendChild( regPriceSpan );

				var saveBadge = document.createElement( 'span' );
				saveBadge.className = 'manage-cart-item-save-badge';
				saveBadge.textContent = saleInfo.saveLabel;
				saleWrap.appendChild( saveBadge );

				details.appendChild( saleWrap );
			}

			var meta = document.createElement( 'div' );
			meta.className = 'manage-cart-item-meta';

			var isEditableQuantity = settings.showQuantityControls && ! item.sold_individually;
			var maxQuantity = ( item.quantity_limits && 'number' === typeof item.quantity_limits.maximum )
				? item.quantity_limits.maximum
				: -1;

			if ( isEditableQuantity ) {
				var stepper = document.createElement( 'div' );
				stepper.className = 'manage-cart-qty-stepper';
				stepper.setAttribute( 'role', 'group' );
				stepper.setAttribute( 'aria-label', formatTemplate( i18n.qtyGroupLabel || 'Quantity for %s', item.name ) );

				var decreaseBtn = document.createElement( 'button' );
				decreaseBtn.type = 'button';
				decreaseBtn.className = 'manage-cart-qty-btn manage-cart-qty-btn--decrease';
				decreaseBtn.setAttribute( 'data-manage-cart-qty-decrease', '' );
				decreaseBtn.setAttribute( 'data-cart-item-key', item.key );
				decreaseBtn.setAttribute( 'aria-label', formatTemplate( i18n.decreaseLabel || 'Decrease quantity of %s', item.name ) );
				decreaseBtn.appendChild( createSvgIcon( 14, DECREASE_ICON_PATH_D ) );
				stepper.appendChild( decreaseBtn );

				var qtyInputId = 'manage-cart-qty-' + item.key;

				var qtyLabel = document.createElement( 'label' );
				qtyLabel.className = 'manage-cart-visually-hidden';
				qtyLabel.setAttribute( 'for', qtyInputId );
				qtyLabel.textContent = formatTemplate( i18n.qtyOfLabel || 'Quantity of %s', item.name );
				stepper.appendChild( qtyLabel );

				var qtyInput = document.createElement( 'input' );
				qtyInput.setAttribute( 'type', 'number' );
				qtyInput.setAttribute( 'inputmode', 'numeric' );
				qtyInput.id = qtyInputId;
				qtyInput.className = 'manage-cart-qty-input';
				qtyInput.setAttribute( 'data-manage-cart-qty-input', '' );
				qtyInput.setAttribute( 'data-cart-item-key', item.key );
				qtyInput.value = item.quantity;
				qtyInput.setAttribute( 'min', '0' );
				if ( maxQuantity > 0 ) {
					qtyInput.setAttribute( 'max', String( maxQuantity ) );
				}
				qtyInput.setAttribute( 'step', '1' );
				stepper.appendChild( qtyInput );

				var increaseBtn = document.createElement( 'button' );
				increaseBtn.type = 'button';
				increaseBtn.className = 'manage-cart-qty-btn manage-cart-qty-btn--increase';
				increaseBtn.setAttribute( 'data-manage-cart-qty-increase', '' );
				increaseBtn.setAttribute( 'data-cart-item-key', item.key );
				increaseBtn.setAttribute( 'aria-label', formatTemplate( i18n.increaseLabel || 'Increase quantity of %s', item.name ) );
				increaseBtn.appendChild( createSvgIcon( 14, INCREASE_ICON_PATH_D ) );
				stepper.appendChild( increaseBtn );

				meta.appendChild( stepper );
			} else {
				var qtySpan = document.createElement( 'span' );
				qtySpan.className = 'manage-cart-item-qty';
				qtySpan.textContent = formatTemplate( i18n.qtyReadOnly || 'Qty: %d', item.quantity );
				meta.appendChild( qtySpan );
			}

			var metaEnd = document.createElement( 'span' );
			metaEnd.className = 'manage-cart-item-meta-end';
			var subtotalSpan = document.createElement( 'span' );
			subtotalSpan.className = 'manage-cart-item-subtotal';
			subtotalSpan.textContent = getItemSubtotalDisplay( item ) || '';
			metaEnd.appendChild( subtotalSpan );
			meta.appendChild( metaEnd );

			details.appendChild( meta );

			var lowStockText = getLowStockText( item );

			if ( lowStockText ) {
				var lowStockWrap = document.createElement( 'div' );
				lowStockWrap.className = 'manage-cart-item-low-stock';
				var lowStockBadge = document.createElement( 'span' );
				lowStockBadge.className = 'manage-cart-item-low-stock-badge';
				lowStockBadge.textContent = lowStockText;
				lowStockWrap.appendChild( lowStockBadge );
				details.appendChild( lowStockWrap );
			}

			li.appendChild( details );

			return li;
		}

		/**
		 * Builds the applied-coupons list (Phase 3H-1) — code, discount
		 * amount, and an accessible Remove button per coupon — via
		 * document.createElement()/.textContent only, matching
		 * Cart_Renderer::render_applied_coupons()'s server-rendered
		 * markup exactly (same id/classes/data attributes) so the
		 * existing delegated click listener below applies without
		 * change after a full footer rebuild. Returns null when there
		 * are no applied coupons, so callers can skip appending it.
		 *
		 * @param {Array} coupons The Store API cart response's `coupons` array.
		 * @return {?HTMLElement}
		 */
		function buildAppliedCouponsList( coupons ) {
			if ( ! Array.isArray( coupons ) || 0 === coupons.length ) {
				return null;
			}

			var list = document.createElement( 'ul' );
			list.className = 'manage-cart-applied-coupons';
			list.id = 'manage-cart-coupon-list';
			list.setAttribute( 'data-manage-cart-coupon-list', '' );

			coupons.forEach( function ( coupon ) {
				var code = coupon && coupon.code ? String( coupon.code ) : '';

				var li = document.createElement( 'li' );
				li.className = 'manage-cart-applied-coupon';
				li.setAttribute( 'data-coupon-code', code );

				var codeEl = document.createElement( 'span' );
				codeEl.className = 'manage-cart-applied-coupon-code';
				codeEl.textContent = code.toUpperCase();
				li.appendChild( codeEl );

				var amountEl = document.createElement( 'span' );
				amountEl.className = 'manage-cart-applied-coupon-amount';
				var discountDisplay = getCouponDiscountDisplay( coupon );
				amountEl.textContent = '\u2212' + ( null !== discountDisplay ? discountDisplay : '' );
				li.appendChild( amountEl );

				var removeBtn = document.createElement( 'button' );
				removeBtn.type = 'button';
				removeBtn.className = 'manage-cart-applied-coupon-remove';
				removeBtn.setAttribute( 'data-manage-cart-coupon-remove', '' );
				removeBtn.setAttribute( 'data-coupon-code', code );
				removeBtn.setAttribute( 'aria-label', formatTemplate( i18n.removeCouponLabel || 'Remove coupon %s', code.toUpperCase() ) );
				removeBtn.textContent = i18n.couponRemoveButton || 'Remove';
				li.appendChild( removeBtn );

				list.appendChild( li );
			} );

			return list;
		}

		/**
		 * Builds the "Have a coupon?" toggle and coupon code form (Phase
		 * 3F-1), preceded by the applied-coupons list when at least one
		 * coupon is currently applied (Phase 3H-1), via
		 * document.createElement()/.textContent only, matching
		 * Cart_Renderer::render_coupon_section()'s server-rendered
		 * markup exactly (same ids/classes/data attributes) so the
		 * existing delegated click/submit listeners below apply without
		 * change after a full footer rebuild. The toggle/form always
		 * start collapsed — callers only invoke this when
		 * config.couponsEnabled is true.
		 *
		 * @param {Array} [coupons] The Store API cart response's `coupons` array, if any.
		 * @return {HTMLElement}
		 */
		function buildCouponSection( coupons ) {
			var wrapper = document.createElement( 'div' );
			wrapper.className = 'manage-cart-coupon';
			wrapper.setAttribute( 'data-manage-cart-coupon', '' );

			var appliedList = buildAppliedCouponsList( coupons );
			if ( appliedList ) {
				wrapper.appendChild( appliedList );
			}

			var toggle = document.createElement( 'button' );
			toggle.type = 'button';
			toggle.className = 'manage-cart-coupon-toggle';
			toggle.id = 'manage-cart-coupon-toggle';
			toggle.setAttribute( 'data-manage-cart-coupon-toggle', '' );
			toggle.setAttribute( 'aria-expanded', 'false' );
			toggle.setAttribute( 'aria-controls', 'manage-cart-coupon-form' );
			toggle.textContent = i18n.couponToggleLabel || 'Have a coupon?';
			wrapper.appendChild( toggle );

			var form = document.createElement( 'form' );
			form.className = 'manage-cart-coupon-form';
			form.id = 'manage-cart-coupon-form';
			form.setAttribute( 'data-manage-cart-coupon-form', '' );
			form.hidden = true;

			var label = document.createElement( 'label' );
			label.className = 'manage-cart-visually-hidden';
			label.setAttribute( 'for', 'manage-cart-coupon-input' );
			label.textContent = i18n.couponInputLabel || 'Coupon code';
			form.appendChild( label );

			var input = document.createElement( 'input' );
			input.setAttribute( 'type', 'text' );
			input.id = 'manage-cart-coupon-input';
			input.className = 'manage-cart-coupon-input';
			input.setAttribute( 'data-manage-cart-coupon-input', '' );
			input.setAttribute( 'autocomplete', 'off' );
			input.setAttribute( 'autocapitalize', 'characters' );
			input.setAttribute( 'spellcheck', 'false' );
			form.appendChild( input );

			var applyBtn = document.createElement( 'button' );
			applyBtn.type = 'submit';
			applyBtn.className = 'manage-cart-btn manage-cart-btn--secondary manage-cart-coupon-apply';
			applyBtn.setAttribute( 'data-manage-cart-coupon-apply', '' );
			applyBtn.textContent = i18n.couponApplyButton || 'Apply';
			form.appendChild( applyBtn );

			wrapper.appendChild( form );

			return wrapper;
		}

		/**
		 * Builds the "Product savings" total savings row shown above the
		 * Subtotal row (see getCartTotalSavingsDisplay()), or returns
		 * null when there's nothing to save (e.g. no item in the cart
		 * currently has a real sale discount) — mirroring
		 * Cart_Renderer::render_savings_row()'s "render nothing" case.
		 *
		 * @param {Object} [data] The Store API cart response to compute savings from.
		 * @return {?HTMLElement}
		 */
		function buildSavingsRow( data ) {
			var display = getCartTotalSavingsDisplay( data );

			if ( null === display ) {
				return null;
			}

			var row = document.createElement( 'div' );
			row.className = 'manage-cart-savings-row';

			var label = document.createElement( 'span' );
			label.className = 'manage-cart-savings-label';
			label.textContent = i18n.savingsLabel || 'Product savings';
			row.appendChild( label );

			var value = document.createElement( 'span' );
			value.className = 'manage-cart-savings-value';
			value.textContent = display;
			row.appendChild( value );

			return row;
		}

		/**
		 * Builds the aggregate "Coupon discount" row shown below the
		 * Subtotal row (see getCartCouponDiscountDisplay()), or returns
		 * null when there's no applied coupon or nothing to discount —
		 * mirroring Cart_Renderer::render_coupon_discount_row()'s "render
		 * nothing" case. Separate from the per-coupon code/amount/Remove
		 * list built by buildCouponSection(); that list is unchanged by
		 * this row.
		 *
		 * @param {Object} [data] The Store API cart response to compute the discount from.
		 * @return {?HTMLElement}
		 */
		function buildCouponDiscountRow( data ) {
			var display = getCartCouponDiscountDisplay( data );

			if ( null === display ) {
				return null;
			}

			var row = document.createElement( 'div' );
			row.className = 'manage-cart-coupon-discount-row';

			var label = document.createElement( 'span' );
			label.className = 'manage-cart-coupon-discount-label';
			label.textContent = i18n.couponDiscountLabel || 'Coupon discount';
			row.appendChild( label );

			var value = document.createElement( 'span' );
			value.className = 'manage-cart-coupon-discount-value';
			value.textContent = '\u2212' + display;
			row.appendChild( value );

			return row;
		}

		/**
		 * Builds the prominent "Total" row shown at the bottom of the
		 * footer totals block, below Coupon discount (see
		 * getCartTotalDisplay()). Unlike buildSavingsRow() and
		 * buildCouponDiscountRow(), this always returns an element — the
		 * Total row is shown whenever the footer itself is shown, matching
		 * Cart_Renderer::render_total_row().
		 *
		 * @param {Object} [data] The Store API cart response to read the total from.
		 * @return {HTMLElement}
		 */
		function buildTotalRow( data ) {
			var row = document.createElement( 'div' );
			row.className = 'manage-cart-total-row';

			var label = document.createElement( 'span' );
			label.className = 'manage-cart-total-label';
			label.textContent = i18n.totalLabel || 'Total';
			row.appendChild( label );

			var value = document.createElement( 'span' );
			value.className = 'manage-cart-total-value';
			value.textContent = getCartTotalDisplay( data ) || '';
			row.appendChild( value );

			return row;
		}

		/**
		 * Builds the panel footer (coupon UI + totals block + Continue
		 * Shopping/View Cart/Checkout), via document.createElement()/
		 * .textContent only. The subtotal, coupon discount, total, and
		 * applied-coupons list all come from the live Store API cart
		 * response; the Continue Shopping/View Cart/Checkout links use the
		 * static URLs localized once at page load (they don't depend on
		 * live cart data, so there's no need to fetch them from anywhere).
		 * Continue Shopping is grouped with View Cart in its own row above
		 * the full-width Checkout button, and is omitted entirely when
		 * `config.shopUrl` is empty (no Shop page configured) — matching
		 * Cart_Renderer::render_footer()'s server-side markup and guard
		 * exactly, including the Product savings / Subtotal / Coupon
		 * discount / Total row order.
		 *
		 * @param {string} subtotalDisplay Formatted cart subtotal for the current response.
		 * @param {Object} [data]          The Store API cart response this subtotal came from,
		 *                                 used to read its `coupons` array and cart-level `totals`.
		 * @return {HTMLElement}
		 */
		function buildFooter( subtotalDisplay, data ) {
			var footer = document.createElement( 'div' );
			footer.className = 'manage-cart-panel-footer';

			if ( couponsEnabled ) {
				footer.appendChild( buildCouponSection( data && data.coupons ) );
			}

			var savingsRow = buildSavingsRow( data );
			if ( savingsRow ) {
				footer.appendChild( savingsRow );
			}

			var subtotalRow = document.createElement( 'div' );
			subtotalRow.className = 'manage-cart-subtotal-row';

			var label = document.createElement( 'span' );
			label.className = 'manage-cart-subtotal-label';
			label.textContent = i18n.subtotalLabel || 'Subtotal';
			subtotalRow.appendChild( label );

			var value = document.createElement( 'span' );
			value.className = 'manage-cart-subtotal-value';
			value.textContent = subtotalDisplay || '';
			subtotalRow.appendChild( value );

			footer.appendChild( subtotalRow );

			var couponDiscountRow = buildCouponDiscountRow( data );
			if ( couponDiscountRow ) {
				footer.appendChild( couponDiscountRow );
			}

			footer.appendChild( buildTotalRow( data ) );

			var actions = document.createElement( 'div' );
			actions.className = 'manage-cart-panel-actions';

			var secondaryRow = document.createElement( 'div' );
			secondaryRow.className = 'manage-cart-panel-actions-row';

			if ( config.shopUrl ) {
				var continueShoppingLink = document.createElement( 'a' );
				continueShoppingLink.className = 'manage-cart-btn manage-cart-btn--secondary';
				continueShoppingLink.setAttribute( 'href', config.shopUrl );
				continueShoppingLink.textContent = i18n.continueShoppingButton || 'Continue Shopping';
				secondaryRow.appendChild( continueShoppingLink );
			}

			var viewCartLink = document.createElement( 'a' );
			viewCartLink.className = 'manage-cart-btn manage-cart-btn--secondary';
			viewCartLink.setAttribute( 'href', config.cartUrl || '#' );
			viewCartLink.textContent = i18n.viewCartButton || 'View Cart';
			secondaryRow.appendChild( viewCartLink );

			actions.appendChild( secondaryRow );

			var checkoutLink = document.createElement( 'a' );
			checkoutLink.className = 'manage-cart-btn manage-cart-btn--primary';
			checkoutLink.setAttribute( 'href', config.checkoutUrl || '#' );
			checkoutLink.textContent = i18n.checkoutButton || 'Checkout';
			actions.appendChild( checkoutLink );

			footer.appendChild( actions );

			return footer;
		}

		/**
		 * Replaces the panel body's item list (or swaps in the empty-cart
		 * state) and the footer from a real, freshly fetched WooCommerce
		 * Store API cart response (`GET /wc/store/v1/cart`), and updates
		 * the trigger badge / heading count to match. Every node is built
		 * with document.createElement()/.textContent — this never uses
		 * innerHTML or insertAdjacentHTML.
		 *
		 * @param {Object} data Parsed Store API cart response.
		 * @return {void}
		 */
		function applyPanelRefresh( data ) {
			if ( ! data ) {
				return;
			}

			updateItemsCache( data );

			var count = getResponseItemCount( data );

			if ( null !== count ) {
				updateTriggerCount( count );
				updateHeadingCount( count );
			}

			var items = Array.isArray( data.items ) ? data.items : [];

			var body = panel.querySelector( '.manage-cart-panel-body' );
			if ( body ) {
				while ( body.firstChild ) {
					body.removeChild( body.firstChild );
				}

				if ( 0 === items.length ) {
					body.appendChild( buildEmptyCartState() );
				} else {
					var list = document.createElement( 'ul' );
					list.className = 'manage-cart-items';

					items.forEach( function ( item ) {
						list.appendChild( buildItemRow( item, displaySettings ) );
					} );

					body.appendChild( list );
				}
			}

			var existingFooter = panel.querySelector( '.manage-cart-panel-footer' );
			if ( existingFooter && existingFooter.parentNode ) {
				existingFooter.parentNode.removeChild( existingFooter );
			}

			if ( items.length && body && body.parentNode ) {
				body.parentNode.insertBefore( buildFooter( getCartSubtotalDisplay( data ), data ), body.nextSibling );
			}

			// Public extension point — see scheduleCartUpdatedEvent().
			// Fired here, after the footer has been rebuilt, so listeners
			// that add their own markup to the footer can re-add it.
			scheduleCartUpdatedEvent( data );
		}

		/**
		 * Fetches WooCommerce's own real Store API cart
		 * (`GET /wc/store/v1/cart` — no custom REST/AJAX route of this
		 * plugin's own), sending the current Store API nonce and saving
		 * whatever fresh nonce the response header returns, exactly like
		 * storeApiRequest() does for update-item/remove-item below.
		 * `Cache-Control: no-cache` is sent so a browser- or
		 * proxy-cached empty/stale cart response is never applied right
		 * after an add.
		 *
		 * @return {Promise<Object>} Resolves with the parsed Store API cart response body.
		 */
		function fetchStoreApiCart() {
			return window.fetch( cartApiUrl, {
				method: 'GET',
				credentials: 'same-origin',
				headers: {
					'Cache-Control': 'no-cache',
					'Nonce': storeApiNonce
				}
			} ).then( function ( response ) {
				var freshNonce = response.headers.get( 'Nonce' );

				if ( freshNonce ) {
					storeApiNonce = freshNonce;
				}

				if ( ! response.ok ) {
					throw new Error( 'Cart refresh failed.' );
				}

				return response.json();
			} );
		}

		/**
		 * One-time warm-up so cartItemsCache already has a usable
		 * snapshot for every item the server rendered at page load, in
		 * case the very first thing the customer does is remove one of
		 * them — before any add-to-cart, quantity change, or coupon
		 * action would otherwise have populated it. Reuses the exact
		 * same real Store API cart fetch already used everywhere else in
		 * this file (fetchStoreApiCart(); no new endpoint), and only
		 * updates the cache — it never touches the DOM, so the server-
		 * rendered panel markup is left exactly as it was rendered. Only
		 * runs when the server-rendered item count is above zero, since
		 * an empty cart has nothing a customer could remove yet. Fails
		 * silently, the same as every other background cart fetch here:
		 * a customer who removes an item before this resolves simply
		 * won't be offered Undo for that one removal (see
		 * captureUndoSnapshot()), which is the same graceful outcome as
		 * any other transient Store API failure.
		 *
		 * @return {void}
		 */
		function warmUndoSnapshotCache() {
			if ( ! cartApiUrl || ! window.fetch || currentItemCount <= 0 ) {
				return;
			}

			fetchStoreApiCart().then( function ( data ) {
				updateItemsCache( data );
			} ).catch( function () {
				// Fail silently — see function comment above.
			} );
		}

		warmUndoSnapshotCache();

		/**
		 * Handles a successful add-to-cart from any in-page flow (classic
		 * AJAX or block-based): refreshes the panel from the real
		 * WooCommerce Store API cart, then opens it if this is the first
		 * add (panel not already open) and auto-open is enabled.
		 *
		 * @return {void}
		 */
		function handleAddedToCart() {
			if ( ! cartApiUrl || ! window.fetch ) {
				return;
			}

			fetchStoreApiCart().then( function ( data ) {
				applyPanelRefresh( data );

				if ( autoOpenOnAdd && ! isOpen ) {
					openPanel();
				}
			} ).catch( function () {
				// Fail silently — this never blocks WooCommerce's own
				// add-to-cart success handling, and a full page load
				// (or the customer opening the panel themselves) shows
				// the current cart regardless.
			} );
		}

		// Classic AJAX add-to-cart (archive/loop buttons): WooCommerce
		// core only fires this through jQuery's own event system.
		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'added_to_cart', handleAddedToCart );
		}

		// Block-based add-to-cart (Add to Cart with Options, Product
		// Collection, Mini-Cart, Cart block): WooCommerce Blocks
		// translates the same moment into a native DOM event, no jQuery
		// required or assumed.
		document.body.addEventListener( 'wc-blocks_added_to_cart', handleAddedToCart );

		/**
		 * ## Extension point: `managecart:request-cart-refresh`
		 *
		 * A generic, one-way "please refresh" signal any other script on
		 * the page (a ManageCart add-on, a theme, or a site customization)
		 * can dispatch when it has changed the WooCommerce cart through
		 * some means of its own — e.g. after posting to a Store API route
		 * directly, the way the Cart Recommendations add-on's "Add to
		 * cart" button does.
		 *
		 * This listener never trusts anything the dispatching script might
		 * attach to the event: it ignores `event.detail` entirely and
		 * always re-fetches the real, current WooCommerce Store API cart
		 * itself via fetchStoreApiCart(), then applies it with the exact
		 * same applyPanelRefresh() used for a classic/block add-to-cart
		 * above. A caller cannot hand this listener fabricated totals or
		 * markup to render — it can only ask "please go check the real
		 * cart," and what gets rendered is always this script's own
		 * fetch of that real cart.
		 *
		 * Duplicate requests fired in quick succession from the same
		 * interaction (e.g. more than one add-to-cart button reacting to
		 * the same click, or a caller dispatching the event more than
		 * once) are throttled two ways: a request already in flight
		 * suppresses any further one until it settles, and a short
		 * cooldown after each completed refresh suppresses another
		 * immediate re-fetch. Either way, at most one Store API request is
		 * in flight for this event at any time.
		 *
		 * Like handleAddedToCart(), a failed refresh fails silently: it
		 * must never surface as a broken panel, since the customer's
		 * underlying cart change (if any) already succeeded or failed on
		 * its own terms before this event was even dispatched.
		 */
		var REQUEST_CART_REFRESH_EVENT = 'managecart:request-cart-refresh';
		var cartRefreshRequestInFlight = false;
		var lastCartRefreshRequestAt   = 0;
		var CART_REFRESH_THROTTLE_MS   = 400;

		function handleRequestCartRefresh() {
			if ( ! cartApiUrl || ! window.fetch ) {
				return;
			}

			if ( cartRefreshRequestInFlight ) {
				return;
			}

			var now = Date.now ? Date.now() : new Date().getTime();

			if ( now - lastCartRefreshRequestAt < CART_REFRESH_THROTTLE_MS ) {
				return;
			}

			cartRefreshRequestInFlight = true;
			lastCartRefreshRequestAt   = now;

			fetchStoreApiCart().then( function ( data ) {
				applyPanelRefresh( data );
			} ).catch( function () {
				// Fail silently — see the extension-point note above.
			} ).then( function () {
				cartRefreshRequestInFlight = false;
			} );
		}

		document.body.addEventListener( REQUEST_CART_REFRESH_EVENT, handleRequestCartRefresh );

		/**
		 * Detects a normal (non-AJAX) add-to-cart form submit that just
		 * completed a full page reload, via WooCommerce's own
		 * `added-to-cart` redirect query argument.
		 *
		 * @return {boolean}
		 */
		function hasAddedToCartParam() {
			try {
				return new window.URLSearchParams( window.location.search ).has( 'added-to-cart' );
			} catch ( error ) {
				return /(?:^|[?&])added-to-cart(?:=|&|$)/.test( window.location.search || '' );
			}
		}

		/**
		 * Removes the `added-to-cart` query argument from the current URL
		 * without a navigation, so refreshing or revisiting this exact
		 * URL later doesn't re-open the panel.
		 *
		 * @return {void}
		 */
		function stripAddedToCartParam() {
			if ( ! window.history || ! window.history.replaceState ) {
				return;
			}

			try {
				var url = new window.URL( window.location.href );
				url.searchParams.delete( 'added-to-cart' );
				window.history.replaceState( null, '', url.toString() );
			} catch ( error ) {
				// Unsupported URL API — leave the query string as-is.
			}
		}

		// Normal (non-AJAX) add-to-cart: the page the customer lands back
		// on after the redirect already has the current cart rendered
		// server-side, so this just opens the panel — the same "only if
		// auto-open is on and it isn't already open" rule as every other
		// path — with no extra fetch needed. The query argument is
		// stripped whenever it's present, regardless of whether the panel
		// actually opens (e.g. on the Cart/Checkout page, where
		// autoOpenOnAdd is false — see its own definition above), so a
		// later refresh of this same URL never has it to react to either.
		if ( hasAddedToCartParam() ) {
			if ( autoOpenOnAdd ) {
				openPanel();
			}

			stripAddedToCartParam();
		}

		/**
		 * Single Product Page Auto-open, Part 2: consumes the Part 1
		 * detection signal.
		 *
		 * `config.singleProductAddToCartSuccess` is a plain boolean the
		 * server only ever localizes into this page's own config object
		 * when a qualifying single-product-page add-to-cart was detected
		 * on this exact request *and* "Auto-open cart after add to cart"
		 * is on (see Single_Product_Auto_Open and
		 * Assets::register_frontend_assets()). Unlike the
		 * `added-to-cart` redirect case above, WooCommerce's default
		 * single-product form doesn't redirect at all — this very page
		 * load already *is* the destination the item was added on — so
		 * there is no URL argument to read or strip here.
		 *
		 * Reuses handleAddedToCart() itself: the exact same real Store
		 * API cart fetch + applyPanelRefresh() + "open only if auto-open
		 * is on and it isn't already open" behavior already used by
		 * every other add-to-cart flow above, so this can never create a
		 * second cart panel or register a second event listener — it
		 * only ever calls the one shared handler already wired up.
		 *
		 * The flag is cleared the moment it's read, before
		 * handleAddedToCart() even runs, so nothing later in this same
		 * page load (or a stray duplicate check) can act on it again —
		 * and, since the server only ever sets this flag from a genuine
		 * POST submission of the add-to-cart form (see
		 * Single_Product_Auto_Open::is_single_product_page_submission()),
		 * a normal reload of this same URL is a plain GET and never
		 * carries the flag in the first place.
		 */
		if ( config.singleProductAddToCartSuccess ) {
			config.singleProductAddToCartSuccess = false;
			handleAddedToCart();
		}

		/**
		 * Builds the empty-cart state markup (icon, title, text, and an
		 * optional "Start Shopping" link), matching
		 * Cart_Renderer::render_empty_state() but built entirely with
		 * document.createElement()/createElementNS() and .textContent.
		 *
		 * @return {HTMLElement}
		 */
		function buildEmptyCartState() {
			var wrapper = document.createElement( 'div' );
			wrapper.className = 'manage-cart-empty';

			var iconWrap = document.createElement( 'span' );
			iconWrap.className = 'manage-cart-empty-icon';
			iconWrap.setAttribute( 'aria-hidden', 'true' );
			iconWrap.appendChild( createCartIcon( 40 ) );
			wrapper.appendChild( iconWrap );

			var title = document.createElement( 'p' );
			title.className = 'manage-cart-empty-title';
			title.textContent = i18n.emptyTitle || 'Your cart is empty';
			wrapper.appendChild( title );

			var text = document.createElement( 'p' );
			text.className = 'manage-cart-empty-text';
			text.textContent = i18n.emptyText || 'Looks like you haven\'t added anything yet.';
			wrapper.appendChild( text );

			if ( config.shopUrl ) {
				var link = document.createElement( 'a' );
				link.className = 'manage-cart-btn manage-cart-btn--primary';
				link.setAttribute( 'href', config.shopUrl );
				link.textContent = i18n.emptyShopButton || 'Start Shopping';
				wrapper.appendChild( link );
			}

			return wrapper;
		}

		/**
		 * Swaps the panel body over to the empty-cart state and removes
		 * the footer (subtotal + View Cart/Checkout), mirroring what the
		 * server renders when the cart has no items.
		 *
		 * @return {void}
		 */
		function showEmptyCartState() {
			var body = panel.querySelector( '.manage-cart-panel-body' );

			if ( body ) {
				while ( body.firstChild ) {
					body.removeChild( body.firstChild );
				}
				body.appendChild( buildEmptyCartState() );
			}

			var footer = panel.querySelector( '.manage-cart-panel-footer' );
			if ( footer && footer.parentNode ) {
				footer.parentNode.removeChild( footer );
			}
		}

		/**
		 * Moves keyboard focus to the panel heading (used once the cart
		 * becomes empty). The heading is a plain, non-interactive <h2> in
		 * the markup, so a tabindex is added the first time this runs to
		 * make it programmatically focusable.
		 *
		 * @return {void}
		 */
		function focusPanelHeading() {
			var heading = document.getElementById( config.titleId || 'manage-cart-panel-title' );

			if ( ! heading ) {
				return;
			}

			if ( ! heading.hasAttribute( 'tabindex' ) ) {
				heading.setAttribute( 'tabindex', '-1' );
			}

			heading.focus();
		}

		/**
		 * Finds the first still-enabled interactive control within a cart
		 * item row, preferring remove, then increase/decrease, then the
		 * quantity input, so focus lands somewhere immediately usable.
		 *
		 * @param {Element} row A `.manage-cart-item` row.
		 * @return {?HTMLElement}
		 */
		function findFocusableInRow( row ) {
			var selectors = [
				'[data-manage-cart-remove]',
				'[data-manage-cart-qty-increase]',
				'[data-manage-cart-qty-decrease]',
				'[data-manage-cart-qty-input]'
			];

			for ( var i = 0; i < selectors.length; i++ ) {
				var el = row.querySelector( selectors[ i ] );
				if ( el && ! el.disabled ) {
					return el;
				}
			}

			return null;
		}

		/**
		 * Moves keyboard focus to a sensible remaining cart control after
		 * a row has been removed: the next item's, falling back to the
		 * previous item's, falling back to the panel close button if
		 * neither yields a usable control.
		 *
		 * @param {?Element} nextRow The removed row's former next sibling.
		 * @param {?Element} prevRow The removed row's former previous sibling.
		 * @return {void}
		 */
		function focusAfterRemoval( nextRow, prevRow ) {
			var candidates = [ nextRow, prevRow ];

			for ( var i = 0; i < candidates.length; i++ ) {
				var row = candidates[ i ];

				if ( row && row.classList && row.classList.contains( 'manage-cart-item' ) ) {
					var focusable = findFocusableInRow( row );
					if ( focusable ) {
						focusable.focus();
						return;
					}
				}
			}

			if ( closeBtn ) {
				closeBtn.focus();
			}
		}

		/**
		 * Undo Removed Cart Item (Free, Part 1).
		 *
		 * A small, accessible "Product removed" notice with an Undo
		 * button, shown inside the real Side Cart panel for 6 seconds
		 * after any successful item removal — whether from the remove
		 * button (handleRemove()) or from decreasing quantity to 0
		 * (handleQuantityChange()'s "item gone" branch) — both of which
		 * already funnel through the shared finishRemoval() below.
		 *
		 * The notice element itself (see
		 * Cart_Renderer::render_undo_notice()) is static, always-present
		 * markup rendered as a direct child of the panel, never inside
		 * `.manage-cart-panel-body` — so it is never touched, duplicated,
		 * or discarded by applyPanelRefresh()/showEmptyCartState()'s body
		 * rebuilds, and stays visible across an empty-cart <-> items-state
		 * swap exactly as required.
		 *
		 * Restoring uses WooCommerce's own real
		 * `POST /wc/store/v1/cart/add-item` Store API route — no custom
		 * REST/AJAX endpoint — with the exact `id` (product ID, or
		 * variation ID for a variable product line, per the Store API's
		 * own cart item schema) and `quantity` snapshotted immediately
		 * before removal by captureUndoSnapshot() above, using the same
		 * nonce lifecycle as every other Store API request in this file.
		 * Because a variation's own ID already fully identifies which
		 * variation it is, WooCommerce resolves the exact product and
		 * variation attributes from that one `id` alone — no separate
		 * attribute list needs to be sent or reconstructed. On success,
		 * the response is applied with the existing applyPanelRefresh(),
		 * the same full-panel-from-one-real-response routine already
		 * used after add-to-cart/coupon actions, so items, item count,
		 * totals, coupon state, and the floating trigger badge all come
		 * from that one real response — and, because applyPanelRefresh()
		 * itself dispatches the existing `managecart:cart-updated` event,
		 * Cart Note/Free Shipping Progress/Cart Recommendations (each of
		 * which already listens for that event to re-sync/re-insert
		 * itself after a full refresh) update the same way they already
		 * do after any other full refresh, with no changes needed here.
		 *
		 * Only one Undo opportunity is ever live: offerUndo() always
		 * clears any previous timer/state first, so a newer successful
		 * removal replaces an older still-pending one, and the button is
		 * disabled the instant it's clicked (plus an in-memory
		 * `restoring` flag) so a repeated click/Enter can never fire a
		 * second restore request for the same opportunity.
		 *
		 * Rapid consecutive removals: relying on the order requests were
		 * *sent* in to decide which response may touch cart-wide totals/
		 * the Undo notice doesn't work — Store API responses for
		 * near-simultaneous removals are not guaranteed to resolve in the
		 * order the requests were sent, so a request that was started
		 * first can still be the one carrying the newest real cart state
		 * (e.g. it was simply slower to reach the server). Discarding a
		 * successful response because its request happened to be sent
		 * earlier can leave stale totals or hand the Undo notice to the
		 * wrong item.
		 *
		 * Instead, item-mutation requests (update-item/remove-item — see
		 * queueItemMutationRequest() below, used by storeApiRequest())
		 * are serialized: only one is ever in flight at a time, and each
		 * next one is only sent once the previous one's response has been
		 * fully applied. That guarantees responses always arrive, and are
		 * applied, in the exact order the requests were sent — so the
		 * response finishRemoval() below is handling is, by construction,
		 * always the newest authoritative Store API cart state, and the
		 * last one to complete is always the most recently *successful*
		 * removal, which is exactly what the Undo notice must represent.
		 * Serializing also avoids a subtler problem: the Store API nonce
		 * returned by one response is required by the next request, so
		 * firing removals concurrently could race two requests against
		 * the same nonce and fail one outright.
		 */
		var UNDO_DURATION_MS = 6000;
		var showUndoCountdown = !! config.showUndoCountdown;
		var undoNotice        = document.getElementById( config.undoNoticeId || 'manage-cart-undo-notice' );
		var undoTextEl        = undoNotice ? undoNotice.querySelector( '[data-manage-cart-undo-text]' ) : null;
		var undoButtonEl      = undoNotice ? undoNotice.querySelector( '[data-manage-cart-undo-button]' ) : null;
		var undoCountdownEl   = undoNotice ? undoNotice.querySelector( '[data-manage-cart-undo-countdown]' ) : null;

		// The single currently-live Undo opportunity, or null when none is
		// shown. `restoring` guards against duplicate restore requests;
		// `timerId` is the pending auto-hide timeout.
		var undoState = null;

		// Bumped every time offerUndo() (re)shows the notice. A pending
		// requestAnimationFrame callback captures its own value and checks
		// it's still current before touching the notice, so a rAF queued by
		// an older offerUndo() call can never re-apply visibility classes
		// after a newer offerUndo() (or a hide) has already moved on.
		var undoNoticeGeneration = 0;

		function clearUndoTimer() {
			if ( undoState && undoState.timerId ) {
				window.clearTimeout( undoState.timerId );
				undoState.timerId = null;
			}
		}

		// Interval id for the visible "Undo (6)" seconds-remaining ticker,
		// active only while the "Show Undo countdown" setting is on and a
		// notice is showing. Always cleared alongside the notice itself
		// (hideUndoNotice()) or replaced by a newer one (offerUndo()), so a
		// stale interval can never keep ticking against a notice it no
		// longer belongs to.
		var undoCountdownIntervalId = null;

		function clearUndoCountdownInterval() {
			if ( undoCountdownIntervalId ) {
				window.clearInterval( undoCountdownIntervalId );
				undoCountdownIntervalId = null;
			}
		}

		/**
		 * Formats the compact seconds-remaining text shown on the Undo
		 * button, e.g. "(6)".
		 *
		 * @param {number} secondsRemaining
		 * @return {string}
		 */
		function formatUndoCountdownText( secondsRemaining ) {
			return i18n.undoCountdownFormat
				? formatTemplate( i18n.undoCountdownFormat, secondsRemaining )
				: ( '(' + secondsRemaining + ')' );
		}

		/**
		 * Starts the once-per-second visible countdown ("Undo (6)" down to
		 * "Undo (1)") on the Undo button, when the "Show Undo countdown"
		 * setting is on. Purely visual/decorative (aria-hidden — see
		 * Cart_Renderer::render_undo_notice()); the one-time accessible
		 * announcement of how long the customer has is a separate, polite
		 * aria-live message sent once from announceRemoval() below, not a
		 * per-second update, so screen reader users are informed without
		 * being interrupted every second. Does nothing when the setting is
		 * off, leaving the notice exactly as it was before this feature.
		 *
		 * @return {void}
		 */
		function startUndoCountdown() {
			clearUndoCountdownInterval();

			if ( ! showUndoCountdown || ! undoCountdownEl ) {
				return;
			}

			var secondsRemaining = Math.round( UNDO_DURATION_MS / 1000 );

			undoCountdownEl.textContent = formatUndoCountdownText( secondsRemaining );

			undoCountdownIntervalId = window.setInterval( function () {
				secondsRemaining -= 1;

				if ( secondsRemaining <= 0 ) {
					clearUndoCountdownInterval();
					return;
				}

				undoCountdownEl.textContent = formatUndoCountdownText( secondsRemaining );
			}, 1000 );
		}

		/**
		 * Hides the Undo notice (timer expiry, successful restore,
		 * failed restore, or being replaced by a newer removal) and
		 * clears the current Undo opportunity so a stray late response
		 * can never act on it again.
		 *
		 * @return {void}
		 */
		function hideUndoNotice() {
			clearUndoTimer();
			clearUndoCountdownInterval();
			undoState = null;
			undoNoticeGeneration += 1;

			if ( undoCountdownEl ) {
				undoCountdownEl.textContent = '';
			}

			if ( ! undoNotice ) {
				return;
			}

			undoNotice.hidden = true;
			undoNotice.classList.remove( 'is-visible' );
			undoNotice.classList.remove( 'is-counting' );

			if ( undoButtonEl ) {
				undoButtonEl.disabled = false;
			}
		}

		/**
		 * Shows the Undo notice for one just-removed item's snapshot,
		 * replacing any previous still-pending Undo opportunity, and
		 * starts the 6-second auto-hide timer. Does nothing when there is
		 * no usable snapshot (see captureUndoSnapshot()) or the add-item
		 * Store API URL wasn't localized, so a removal is never left
		 * dangling with a notice that couldn't actually restore anything.
		 *
		 * @param {?Object} snapshot Result of captureUndoSnapshot().
		 * @return {void}
		 */
		function offerUndo( snapshot ) {
			if ( ! undoNotice || ! undoButtonEl || ! snapshot || ! snapshot.id || ! addItemUrl || ! window.fetch ) {
				return;
			}

			// A newer successful removal always replaces any previous
			// Undo opportunity — only one notice is ever shown at a time.
			clearUndoTimer();

			undoState = {
				snapshot: snapshot,
				restoring: false,
				timerId: null
			};

			if ( undoTextEl ) {
				undoTextEl.textContent = i18n.productRemoved || 'Product removed';
			}

			undoButtonEl.disabled = false;
			undoButtonEl.setAttribute(
				'aria-label',
				snapshot.name
					? formatTemplate( i18n.undoAriaLabel || 'Undo removing %s', snapshot.name )
					: ( i18n.undoButton || 'Undo' )
			);

			undoNotice.hidden = false;
			undoNotice.classList.remove( 'is-counting' );
			startUndoCountdown();

			// Force a reflow so the decorative countdown bar's CSS
			// transition restarts even when a previous notice was already
			// mid-animation (rapid consecutive removals) — see
			// assets/css/frontend.css.
			void undoNotice.offsetWidth;

			undoNoticeGeneration += 1;
			var thisGeneration = undoNoticeGeneration;

			window.requestAnimationFrame( function () {
				// A newer offerUndo() (or a hide) may have already run by
				// the time this frame fires — see undoNoticeGeneration
				// above. When it has, adding these classes here would be
				// acting on a stale removal, so skip it.
				if ( thisGeneration !== undoNoticeGeneration ) {
					return;
				}

				undoNotice.classList.add( 'is-visible' );
				undoNotice.classList.add( 'is-counting' );
			} );

			undoState.timerId = window.setTimeout( function () {
				hideUndoNotice();
			}, UNDO_DURATION_MS );
		}

		/**
		 * Finds the cart item row a successful restore just added, by
		 * matching the restored snapshot's product/variation `id` against
		 * the fresh Store API response's items — the restored line gets a
		 * new cart item key, so the old (now-gone) key can't be reused to
		 * find it. Falls back to null (callers move focus to the panel
		 * heading instead) if no match is found.
		 *
		 * @param {Object} data     Store API cart response from add-item.
		 * @param {Object} snapshot Result of captureUndoSnapshot().
		 * @return {?Element}
		 */
		function findRestoredItemRow( data, snapshot ) {
			if ( ! data || ! Array.isArray( data.items ) || ! snapshot ) {
				return null;
			}

			for ( var i = 0; i < data.items.length; i++ ) {
				if ( data.items[ i ] && data.items[ i ].id === snapshot.id ) {
					return panel.querySelector( '.manage-cart-item[data-cart-item-key="' + data.items[ i ].key + '"]' );
				}
			}

			return null;
		}

		/**
		 * Moves keyboard focus to a sensible control after a successful
		 * restore: the restored item's own row control when it can be
		 * found, otherwise the panel heading.
		 *
		 * @param {Object} data     Store API cart response from add-item.
		 * @param {Object} snapshot Result of captureUndoSnapshot().
		 * @return {void}
		 */
		function focusAfterRestore( data, snapshot ) {
			var row = findRestoredItemRow( data, snapshot );

			if ( row ) {
				var focusable = findFocusableInRow( row );

				if ( focusable ) {
					focusable.focus();
					return;
				}
			}

			focusPanelHeading();
		}

		/**
		 * Handles a click on the Undo button: restores the snapshotted
		 * item via `POST /wc/store/v1/cart/add-item`, then applies the
		 * response with the existing applyPanelRefresh() and moves focus
		 * to the restored control. See the section comment above for the
		 * full behavior. On failure, the notice is safely hidden, the
		 * cart is left exactly as it was (still missing the item), and
		 * the failure is announced via the existing aria-live status
		 * region.
		 *
		 * @return {void}
		 */
		function handleUndoClick() {
			if ( ! undoState || undoState.restoring ) {
				return;
			}

			var snapshot = undoState.snapshot;

			if ( ! snapshot || ! snapshot.id || ! addItemUrl || ! window.fetch ) {
				hideUndoNotice();
				return;
			}

			undoState.restoring = true;
			clearUndoTimer();
			undoButtonEl.disabled = true;

			performStoreApiRequest( addItemUrl, {
				id: snapshot.id,
				quantity: snapshot.quantity || 1
			} ).then( function ( data ) {
				hideUndoNotice();
				applyPanelRefresh( data );
				announce( i18n.undoRestored || 'Item restored.' );
				focusAfterRestore( data, snapshot );
			} ).catch( function ( error ) {
				hideUndoNotice();
				announce( ( error && error.message ) ? error.message : ( i18n.undoError || 'Could not restore that item. Please try again.' ) );
			} );
		}

		if ( undoButtonEl ) {
			undoButtonEl.addEventListener( 'click', handleUndoClick );
		}

		/**
		 * Finishes handling a successful removal — whether from
		 * remove-item, or from update-item when the server reports the
		 * item is no longer in the cart. Removes the row, applies the
		 * cart-wide updates from the response, offers Undo for the
		 * snapshot captured immediately before this removal was
		 * requested (when one is available — see captureUndoSnapshot()),
		 * and either swaps in the empty-cart state (focusing the
		 * heading) or moves focus to a remaining control.
		 *
		 * Because item-mutation requests are serialized (see the section
		 * comment above and queueItemMutationRequest() below), the
		 * response passed in here is always the newest authoritative
		 * Store API cart state at the time it was requested, so it's
		 * always safe to apply — there is no older/newer response left
		 * to compare it against.
		 *
		 * @param {Object}  controls Result of getItemControls() for the removed item.
		 * @param {Object}  data     Store API cart response.
		 * @param {?Object} snapshot Result of captureUndoSnapshot(), taken before the request was sent.
		 * @return {void}
		 */
		function finishRemoval( controls, data, snapshot ) {
			var row     = controls && controls.row;
			var nextRow = row ? row.nextElementSibling : null;
			var prevRow = row ? row.previousElementSibling : null;

			// The row comes out — the server really did remove this item.
			removeItemRow( controls );

			applyCartWideUpdates( data );
			offerUndo( snapshot );

			var remaining = getResponseItemCount( data );

			if ( 0 === remaining ) {
				showEmptyCartState();
				focusPanelHeading();
				return;
			}

			focusAfterRemoval( nextRow, prevRow );
		}

		/**
		 * Announces a successful removal via the existing `self::STATUS_ID`
		 * aria-live status region. When the "Show Undo countdown" setting
		 * is on and Undo was actually offered for this removal (a usable
		 * snapshot existed), folds in one polite, one-time mention of how
		 * many seconds are left to press Undo — never repeated every
		 * second; the visible "Undo (6)"..."Undo (1)" ticker on the button
		 * itself (startUndoCountdown() above) is purely visual and
		 * `aria-hidden`, so it never competes with this announcement.
		 *
		 * @param {?Object} snapshot Result of captureUndoSnapshot() for this removal.
		 * @return {void}
		 */
		function announceRemoval( snapshot ) {
			if ( showUndoCountdown && snapshot && snapshot.id && i18n.removedWithUndoCountdown ) {
				announce( formatTemplate( i18n.removedWithUndoCountdown, Math.round( UNDO_DURATION_MS / 1000 ) ) );
				return;
			}

			announce( i18n.removed || 'Item removed from cart.' );
		}

		/**
		 * Finds one item's data in a Store API cart response.
		 *
		 * @param {Object} data    Parsed Store API response body.
		 * @param {string} itemKey Cart item key to find.
		 * @return {Object|null}
		 */
		function findResponseItem( data, itemKey ) {
			if ( ! data || ! Array.isArray( data.items ) ) {
				return null;
			}

			for ( var i = 0; i < data.items.length; i++ ) {
				if ( data.items[ i ] && data.items[ i ].key === itemKey ) {
					return data.items[ i ];
				}
			}

			return null;
		}

		/**
		 * Reads the current quantity from a quantity input.
		 *
		 * @param {HTMLInputElement} input Quantity input element.
		 * @return {number}
		 */
		function readQuantity( input ) {
			var value = parseInt( input.value, 10 );
			return isNaN( value ) ? 0 : value;
		}

		/**
		 * Sends a POST request to a WooCommerce Store API cart route with
		 * the current nonce, saving the nonce the response header returns
		 * for the next request — the shared low-level request used by
		 * storeApiRequest() (update-item/remove-item) and
		 * applyCouponRequest() (apply-coupon) below.
		 *
		 * @param {string} url  Store API endpoint URL.
		 * @param {Object} body Request body to send as JSON.
		 * @return {Promise<Object>} Resolves with the parsed response body.
		 */
		function performStoreApiRequest( url, body ) {
			return window.fetch( url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'Nonce': storeApiNonce
				},
				body: JSON.stringify( body )
			} ).then( function ( response ) {
				var freshNonce = response.headers.get( 'Nonce' );

				if ( freshNonce ) {
					storeApiNonce = freshNonce;
				}

				return response.json().then( function ( data ) {
					if ( ! response.ok ) {
						var error = new Error( ( data && data.message ) ? data.message : ( i18n.error || 'Something went wrong.' ) );
						throw error;
					}

					return data;
				} );
			} );
		}

		// Tail of the item-mutation (update-item/remove-item) request
		// queue — see queueItemMutationRequest() and the section comment
		// above finishRemoval() for why these are serialized rather than
		// fired concurrently. Starts resolved so the very first request
		// sends immediately.
		var itemMutationQueue = Promise.resolve();

		/**
		 * Queues a Store API item-mutation request behind any
		 * update-item/remove-item request still in flight, so the
		 * underlying fetch for this one is only sent once the previous
		 * one's response has arrived — guaranteeing responses are always
		 * received in the same order the requests were made, and that
		 * each one uses the freshest nonce.
		 *
		 * The shared queue promise always resolves (never rejects), even
		 * when a given request fails, so one failed removal/update can
		 * never permanently jam later ones; the failure itself still
		 * propagates to this call's own returned Promise.
		 *
		 * @param {Function} sendFn Zero-arg function that performs and returns the actual request Promise.
		 * @return {Promise<Object>} Resolves/rejects exactly as sendFn()'s own Promise does.
		 */
		function queueItemMutationRequest( sendFn ) {
			var request = itemMutationQueue.then( sendFn, sendFn );

			itemMutationQueue = request.then( function () {}, function () {} );

			return request;
		}

		/**
		 * Sends a Store API cart item request (update-item/remove-item),
		 * sending the current nonce and saving the nonce the response
		 * header returns for the next request. Queued behind any other
		 * item-mutation request already in flight — see
		 * queueItemMutationRequest() above — so rapid consecutive
		 * removals/updates always resolve, and are applied, in the exact
		 * order they were requested.
		 *
		 * @param {string} url      Store API endpoint URL.
		 * @param {string} itemKey  Cart item key.
		 * @param {number} [quantity] Quantity, when the endpoint needs one.
		 * @return {Promise<Object>} Resolves with the parsed response body.
		 */
		function storeApiRequest( url, itemKey, quantity ) {
			var body = { key: itemKey };

			if ( 'undefined' !== typeof quantity ) {
				body.quantity = quantity;
			}

			return queueItemMutationRequest( function () {
				return performStoreApiRequest( url, body );
			} );
		}

		/**
		 * Sends a coupon code to WooCommerce's own
		 * `POST /wc/store/v1/cart/apply-coupon` Store API route (Phase
		 * 3F-1), using the same nonce lifecycle as every other Store API
		 * request here. Resolves with the full, current Store API cart
		 * response on success, exactly like update-item/remove-item do.
		 *
		 * @param {string} code Coupon code entered by the customer.
		 * @return {Promise<Object>} Resolves with the parsed cart response body.
		 */
		function applyCouponRequest( code ) {
			return performStoreApiRequest( applyCouponUrl, { code: code } );
		}

		/**
		 * Sends a coupon code to WooCommerce's own
		 * `POST /wc/store/v1/cart/remove-coupon` Store API route (Phase
		 * 3H-1), using the same nonce lifecycle as every other Store API
		 * request here. Resolves with the full, current Store API cart
		 * response on success, exactly like apply-coupon/update-item/
		 * remove-item do.
		 *
		 * @param {string} code Coupon code to remove.
		 * @return {Promise<Object>} Resolves with the parsed cart response body.
		 */
		function removeCouponRequest( code ) {
			return performStoreApiRequest( removeCouponUrl, { code: code } );
		}

		/**
		 * Requests a quantity change for one item via update-item, then
		 * either updates its input to the server-confirmed quantity, or
		 * removes its row if the server no longer has it in the cart
		 * (e.g. the requested quantity was 0).
		 *
		 * @param {string} itemKey     Cart item key.
		 * @param {number} newQuantity Requested quantity.
		 * @return {void}
		 */
		function handleQuantityChange( itemKey, newQuantity ) {
			var controls = getItemControls( itemKey );

			if ( ! controls || ! controls.input ) {
				return;
			}

			var min = parseInt( controls.input.getAttribute( 'min' ), 10 );
			if ( isNaN( min ) ) {
				min = 0;
			}

			var maxAttr = controls.input.getAttribute( 'max' );
			var max     = ( null !== maxAttr && '' !== maxAttr ) ? parseInt( maxAttr, 10 ) : null;

			if ( newQuantity < min ) {
				newQuantity = min;
			}

			if ( null !== max && ! isNaN( max ) && newQuantity > max ) {
				newQuantity = max;
			}

			if ( newQuantity === readQuantity( controls.input ) ) {
				return;
			}

			// Snapshotted before the request goes out, in case this
			// change turns out to drop the item from the cart entirely
			// (requested quantity 0) — see the "item gone" branch below
			// and captureUndoSnapshot() above. Unused (and harmless) for
			// an ordinary quantity change that keeps the item in the cart.
			var quantitySnapshot = captureUndoSnapshot( itemKey );

			setItemPending( controls, true );

			storeApiRequest( updateItemUrl, itemKey, newQuantity ).then( function ( data ) {
				var item = findResponseItem( data, itemKey );

				if ( item ) {
					controls.input.value = item.quantity;

					var subtotalEl = controls.row ? controls.row.querySelector( '.manage-cart-item-subtotal' ) : null;
					if ( subtotalEl ) {
						var display = getItemSubtotalDisplay( item );
						if ( null !== display ) {
							subtotalEl.textContent = display;
						}
					}

					applyCartWideUpdates( data );
					setItemPending( controls, false );
					announce( i18n.updated || 'Cart updated.' );
				} else {
					// The server no longer has this item in the cart (e.g.
					// the requested quantity was 0) — handle it the same
					// way as an explicit removal, including offering Undo
					// for the quantity that existed immediately before
					// this request.
					finishRemoval( controls, data, quantitySnapshot );
					announceRemoval( quantitySnapshot );
				}
			} ).catch( function ( error ) {
				setItemPending( controls, false );
				announce( ( error && error.message ) ? error.message : ( i18n.error || 'Something went wrong.' ) );
			} );
		}

		/**
		 * Requests removal of one item via remove-item, then removes its
		 * row on success and offers Undo for the snapshot captured
		 * immediately before the request was sent.
		 *
		 * @param {string} itemKey Cart item key.
		 * @return {void}
		 */
		function handleRemove( itemKey ) {
			var controls = getItemControls( itemKey );

			if ( ! controls ) {
				return;
			}

			// Snapshotted before the request goes out — see
			// captureUndoSnapshot() and the Undo Removed Cart Item
			// section above finishRemoval().
			var snapshot = captureUndoSnapshot( itemKey );

			setItemPending( controls, true );

			storeApiRequest( removeItemUrl, itemKey ).then( function ( data ) {
				finishRemoval( controls, data, snapshot );
				announceRemoval( snapshot );
			} ).catch( function ( error ) {
				setItemPending( controls, false );
				announce( ( error && error.message ) ? error.message : ( i18n.error || 'Something went wrong.' ) );
			} );
		}

		/**
		 * Shows/hides the coupon code form and syncs the toggle button's
		 * aria-expanded state, moving focus into the code input when it
		 * opens.
		 *
		 * @param {HTMLElement} toggleBtn The "Have a coupon?" toggle button.
		 * @return {void}
		 */
		function toggleCouponForm( toggleBtn ) {
			var form = panel.querySelector( '[data-manage-cart-coupon-form]' );

			if ( ! form ) {
				return;
			}

			var isExpanded = 'true' === toggleBtn.getAttribute( 'aria-expanded' );
			var nextExpanded = ! isExpanded;

			toggleBtn.setAttribute( 'aria-expanded', String( nextExpanded ) );
			form.hidden = ! nextExpanded;

			if ( nextExpanded ) {
				var input = form.querySelector( '[data-manage-cart-coupon-input]' );
				if ( input ) {
					window.setTimeout( function () {
						input.focus();
					}, 10 );
				}
			}
		}

		/**
		 * Disables/enables the coupon form's own input and apply button
		 * while its request is pending.
		 *
		 * @param {HTMLElement} form    The coupon code form.
		 * @param {boolean}     pending Whether a request is in flight.
		 * @return {void}
		 */
		function setCouponPending( form, pending ) {
			var input = form.querySelector( '[data-manage-cart-coupon-input]' );
			var applyBtn = form.querySelector( '[data-manage-cart-coupon-apply]' );

			if ( input ) {
				input.disabled = pending;
			}

			if ( applyBtn ) {
				applyBtn.disabled = pending;
			}
		}

		/**
		 * Handles a coupon form submission: sends the entered code to
		 * `POST /wc/store/v1/cart/apply-coupon`, then — on success —
		 * rebuilds the entire panel body/footer from that same real Store
		 * API cart response via applyPanelRefresh(), the identical
		 * full-refresh path already used after a successful add-to-cart,
		 * so the item list, footer subtotal, heading count, and floating
		 * trigger badge all update live from one real response with no
		 * page reload. The panel itself is never closed by this. Errors
		 * (invalid/expired code, coupon already applied, etc.) leave the
		 * cart untouched and are announced via the existing aria-live
		 * status region, with the code left in the input so the customer
		 * can correct it.
		 *
		 * @param {HTMLFormElement} form The coupon code form.
		 * @return {void}
		 */
		function handleApplyCoupon( form ) {
			var input = form.querySelector( '[data-manage-cart-coupon-input]' );

			if ( ! input || ! applyCouponUrl || ! window.fetch ) {
				return;
			}

			var code = input.value ? input.value.trim() : '';

			if ( '' === code ) {
				announce( i18n.couponEmpty || 'Please enter a coupon code.' );
				input.focus();
				return;
			}

			setCouponPending( form, true );

			applyCouponRequest( code ).then( function ( data ) {
				setCouponPending( form, false );
				applyPanelRefresh( data );
				announce( i18n.couponApplied || 'Coupon applied.' );
			} ).catch( function ( error ) {
				setCouponPending( form, false );
				announce( ( error && error.message ) ? error.message : ( i18n.couponError || 'Could not apply that coupon. Please try again.' ) );
				input.focus();
			} );
		}

		/**
		 * Handles a click on an applied coupon's Remove button (Phase
		 * 3H-1): sends its code to `POST /wc/store/v1/cart/remove-coupon`,
		 * then — on success — rebuilds the entire panel body/footer from
		 * that same real Store API cart response via applyPanelRefresh(),
		 * the identical full-refresh path handleApplyCoupon() above and
		 * the add-to-cart flow already use, so the applied-coupons list,
		 * footer subtotal, heading count, and floating trigger badge all
		 * update live from one real response with no page reload. The
		 * panel itself is never closed by this. On error the cart is left
		 * untouched, the button is re-enabled, and the message is
		 * announced via the existing aria-live status region.
		 *
		 * @param {HTMLElement} removeBtn The clicked Remove button.
		 * @return {void}
		 */
		function handleRemoveCoupon( removeBtn ) {
			var code = removeBtn.getAttribute( 'data-coupon-code' ) || '';

			if ( '' === code || ! removeCouponUrl || ! window.fetch ) {
				return;
			}

			removeBtn.disabled = true;

			removeCouponRequest( code ).then( function ( data ) {
				applyPanelRefresh( data );
				announce( i18n.couponRemoved || 'Coupon removed.' );
			} ).catch( function ( error ) {
				removeBtn.disabled = false;
				announce( ( error && error.message ) ? error.message : ( i18n.couponRemoveError || 'Could not remove that coupon. Please try again.' ) );
			} );
		}

		if ( panel && couponsEnabled && ( applyCouponUrl || removeCouponUrl ) && window.fetch ) {
			panel.addEventListener( 'click', function ( event ) {
				var target = event.target;

				if ( ! target || ! target.closest ) {
					return;
				}

				var toggleBtn = target.closest( '[data-manage-cart-coupon-toggle]' );
				if ( toggleBtn ) {
					toggleCouponForm( toggleBtn );
					return;
				}

				var removeCouponBtn = target.closest( '[data-manage-cart-coupon-remove]' );
				if ( removeCouponBtn && ! removeCouponBtn.disabled ) {
					handleRemoveCoupon( removeCouponBtn );
				}
			} );

			if ( applyCouponUrl ) {
				panel.addEventListener( 'submit', function ( event ) {
					var form = event.target;

					if ( ! form || ! form.matches || ! form.matches( '[data-manage-cart-coupon-form]' ) ) {
						return;
					}

					event.preventDefault();
					handleApplyCoupon( form );
				} );
			}
		}

		if ( panel && updateItemUrl && removeItemUrl && window.fetch ) {
			panel.addEventListener( 'click', function ( event ) {
				var target = event.target;

				if ( ! target || ! target.closest ) {
					return;
				}

				var decreaseBtn = target.closest( '[data-manage-cart-qty-decrease]' );
				if ( decreaseBtn && ! decreaseBtn.disabled ) {
					var decreaseKey    = decreaseBtn.getAttribute( 'data-cart-item-key' );
					var decreaseInput = getItemControls( decreaseKey ).input;
					if ( decreaseInput ) {
						handleQuantityChange( decreaseKey, readQuantity( decreaseInput ) - 1 );
					}
					return;
				}

				var increaseBtn = target.closest( '[data-manage-cart-qty-increase]' );
				if ( increaseBtn && ! increaseBtn.disabled ) {
					var increaseKey   = increaseBtn.getAttribute( 'data-cart-item-key' );
					var increaseInput = getItemControls( increaseKey ).input;
					if ( increaseInput ) {
						handleQuantityChange( increaseKey, readQuantity( increaseInput ) + 1 );
					}
					return;
				}

				var removeBtn = target.closest( '[data-manage-cart-remove]' );
				if ( removeBtn && ! removeBtn.disabled ) {
					handleRemove( removeBtn.getAttribute( 'data-cart-item-key' ) );
				}
			} );

			panel.addEventListener( 'change', function ( event ) {
				var input = event.target;

				if ( ! input || ! input.matches || ! input.matches( '[data-manage-cart-qty-input]' ) ) {
					return;
				}

				handleQuantityChange( input.getAttribute( 'data-cart-item-key' ), readQuantity( input ) );
			} );
		}
	} );
} )();
