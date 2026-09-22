/**
 * Manage Cart — Floating Cart live preview (Phase 3G-3B; Phase 3H-9 adds
 * live Position/offset sync and admin-preview dragging; Phase 3H-10 adds
 * live "Enable Floating Cart" and "Show item count badge" sync; Phase
 * 3H-11 adds a live "Hide floating cart when cart is empty" status line).
 *
 * Loaded only on the shared "Manage Cart" admin screen, alongside
 * assets/js/side-cart-preview.js. Listens for changes to the Floating
 * Cart → General fields ("Enable Floating Cart", Position, Horizontal
 * offset, Vertical offset, "Show item count badge",
 * "Hide floating cart when cart is empty",
 * "Allow customers to move floating cart button") and the Appearance
 * fields (button background color, icon color, count badge
 * background/text colors, button size, and border radius) and updates the
 * mock preview button instantly, without saving. This script never
 * requests WooCommerce or Store API data: every value it reads comes from
 * the settings form fields already on this admin page, and every value it
 * writes goes back into the static mock preview markup rendered by
 * Side_Cart_Admin::render_floating_cart_preview() — the Appearance values
 * as the same `--manage-cart-trigger-*` CSS custom properties
 * Cart_Renderer::get_trigger_appearance_style_string() writes on the real
 * frontend trigger button, Position/the two offsets as the same
 * `manage-cart-floating-cart-preview-trigger--{position}` modifier class
 * and `--manage-cart-trigger-h-offset`/`--manage-cart-trigger-v-offset`
 * custom properties, "Enable"/"Show item count badge" as the same plain
 * `hidden` attribute that function's initial server-rendered markup
 * already uses on the button and count span respectively, and
 * "Hide floating cart when cart is empty" as which one of two
 * already-translated, already-rendered status `<span>`s is unhidden — so
 * the mock preview mirrors the real Floating Cart button's appearance,
 * placement, and visibility instantly, before Save Changes is clicked.
 * Server-side sanitization and the Save/Reset logic are untouched; this
 * only affects what the admin sees beforehand, and never the storefront:
 * unchecking "Enable Floating Cart" here only hides this mock button —
 * the real, customer-facing trigger keeps whatever was last saved until
 * Save Changes is clicked and the page reloads with the new value. The
 * mock preview cart is always illustrated with items in it, so toggling
 * "Hide floating cart when cart is empty" never hides the mock button
 * itself — only the status line's wording changes, explaining what the
 * real, customer-facing button will do once the cart is actually empty.
 *
 * Because both the General and Appearance subtab panels live in the DOM
 * alongside one shared preview column (see render_panel_content() in
 * class-side-cart-admin.php), this works no matter which of the two
 * subtabs is currently visible.
 *
 * ## Admin-preview dragging (Phase 3H-9)
 *
 * Whenever "Allow customers to move floating cart button" is Yes, the
 * mock button itself becomes draggable by mouse and touch (Pointer
 * Events, same as the real frontend dragging in assets/js/frontend-cart.js)
 * — but strictly as a local, throwaway preview interaction:
 *
 * - Dragging is clamped to stay fully inside
 *   `.manage-cart-floating-cart-preview-viewport` (the mock "page" area),
 *   never the real browser window, and never lets the button go
 *   partway off any edge of it.
 * - Nothing about a drag is ever saved: no option, no transient, no
 *   `window.localStorage` write of any kind (unlike the real frontend
 *   trigger's own drag position, which the customer's browser does
 *   persist locally — see STORAGE_KEY in frontend-cart.js). Dropping the
 *   preview button just leaves it where it was dropped in memory, for
 *   this page view only; reloading the page (or Save Changes, which
 *   reloads it) always shows it back at the real saved Position/offsets.
 * - When the setting is No, dragging is disabled outright (checked
 *   fresh on every pointerdown, so flipping the select instantly turns
 *   dragging on/off with no page reload) and the button is snapped back
 *   to the corner + offsets the Position/offset fields currently show.
 * - Editing Position, Horizontal offset, or Vertical offset always
 *   re-derives the button's placement from those three fields — this
 *   takes priority over, and clears, any in-progress preview drag
 *   position, the same way every other field on this screen is the
 *   single source of truth for what the preview shows.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var fields = {
			enabled: document.getElementById( 'manage-cart-floating-cart-enabled' ),
			buttonBgColor: document.getElementById( 'manage-cart-floating-cart-button-bg-color' ),
			iconColor: document.getElementById( 'manage-cart-floating-cart-icon-color' ),
			badgeBgColor: document.getElementById( 'manage-cart-floating-cart-badge-bg-color' ),
			badgeTextColor: document.getElementById( 'manage-cart-floating-cart-badge-text-color' ),
			buttonSize: document.getElementById( 'manage-cart-floating-cart-button-size' ),
			borderRadius: document.getElementById( 'manage-cart-floating-cart-border-radius' ),
			position: document.getElementById( 'manage-cart-floating-cart-position' ),
			horizontalOffset: document.getElementById( 'manage-cart-floating-cart-horizontal-offset' ),
			verticalOffset: document.getElementById( 'manage-cart-floating-cart-vertical-offset' ),
			showItemCountBadge: document.getElementById( 'manage-cart-floating-cart-show-item-count-badge' ),
			hideWhenEmpty: document.getElementById( 'manage-cart-floating-cart-hide-when-empty' ),
			allowTriggerDrag: document.getElementById( 'manage-cart-floating-cart-allow-trigger-drag' ),
		};

		var preview = {
			trigger: document.getElementById( 'manage-cart-floating-cart-preview-trigger' ),
			count: document.getElementById( 'manage-cart-floating-cart-preview-count' ),
			unsavedBadge: document.getElementById( 'manage-cart-floating-cart-preview-unsaved-badge' ),
			emptyStatusHides: document.getElementById( 'manage-cart-floating-cart-preview-empty-status-hides' ),
			emptyStatusShows: document.getElementById( 'manage-cart-floating-cart-preview-empty-status-shows' ),
		};

		// If the settings fields or the preview markup are not present
		// (for example, a future page reusing this script without both),
		// do nothing rather than risk a script error on the page.
		if ( ! preview.trigger || ! fields.buttonBgColor ) {
			return;
		}

		// The mock "page" area the trigger sits in and gets dragged
		// around inside of (see the class's own markup and styling in
		// class-side-cart-admin.php / side-cart-admin.css). Dragging is
		// simply skipped (below) if this is ever missing.
		var dragContainer = preview.trigger.closest( '.manage-cart-floating-cart-preview-viewport' );

		var buttonSizeMin = fields.buttonSize ? ( parseInt( fields.buttonSize.getAttribute( 'min' ), 10 ) || 40 ) : 40;
		var buttonSizeMax = fields.buttonSize ? ( parseInt( fields.buttonSize.getAttribute( 'max' ), 10 ) || 80 ) : 80;

		/**
		 * Clamps a button size value the same way the server does, so the
		 * preview never shows an out-of-range size even before Save.
		 *
		 * @param {number} value Raw parsed size.
		 * @return {number} Clamped size.
		 */
		function clampButtonSize( value ) {
			if ( isNaN( value ) ) {
				return buttonSizeMin;
			}
			return Math.max( buttonSizeMin, Math.min( buttonSizeMax, value ) );
		}

		var borderRadiusMin = fields.borderRadius ? ( parseInt( fields.borderRadius.getAttribute( 'min' ), 10 ) || 0 ) : 0;
		var borderRadiusMax = fields.borderRadius ? ( parseInt( fields.borderRadius.getAttribute( 'max' ), 10 ) || 50 ) : 50;

		/**
		 * Clamps a border radius value the same way the server does, so
		 * the preview never shows an out-of-range radius even before
		 * Save.
		 *
		 * @param {number} value Raw parsed radius.
		 * @return {number} Clamped radius.
		 */
		function clampBorderRadius( value ) {
			if ( isNaN( value ) ) {
				return borderRadiusMin;
			}
			return Math.max( borderRadiusMin, Math.min( borderRadiusMax, value ) );
		}

		/**
		 * Reads a color field's value, falling back to a safe default
		 * when the field is missing or empty. Native
		 * `<input type="color">` fields always carry a well-formed
		 * `#rrggbb` value once rendered, so no further validation is
		 * needed here; this mirrors the server's own fallback-to-default
		 * behavior in Floating_Cart_Settings::sanitize_color() closely
		 * enough for a purely visual, unsaved preview.
		 *
		 * @param {HTMLInputElement|null} field         The color input.
		 * @param {string}                fallbackColor Default hex color.
		 * @return {string} A hex color.
		 */
		function readColor( field, fallbackColor ) {
			return ( field && field.value ) ? field.value : fallbackColor;
		}

		/**
		 * Valid "Position" values, mirroring
		 * Floating_Cart_Settings::POSITION_CHOICES, in the same order the
		 * corner modifier classes are named.
		 *
		 * @var string[]
		 */
		var POSITION_CHOICES = [ 'bottom-right', 'bottom-left', 'top-right', 'top-left' ];
		var DEFAULT_POSITION = 'bottom-right';

		var offsetMin = fields.horizontalOffset ? ( parseInt( fields.horizontalOffset.getAttribute( 'min' ), 10 ) || 0 ) : 0;
		var offsetMax = fields.horizontalOffset ? ( parseInt( fields.horizontalOffset.getAttribute( 'max' ), 10 ) || 300 ) : 300;

		/**
		 * Clamps a Horizontal/Vertical offset value the same way the
		 * server does (Floating_Cart_Settings::OFFSET_MIN/MAX), so the
		 * preview never shows an out-of-range offset even before Save.
		 *
		 * @param {number} value Raw parsed offset.
		 * @return {number} Clamped offset.
		 */
		function clampOffset( value ) {
			if ( isNaN( value ) ) {
				return offsetMin;
			}
			return Math.max( offsetMin, Math.min( offsetMax, value ) );
		}

		/**
		 * The current "Position" field value, falling back to the same
		 * default corner used elsewhere whenever the field is missing or
		 * holds something unexpected.
		 *
		 * @return {string} One of POSITION_CHOICES.
		 */
		function currentPosition() {
			var value = fields.position ? fields.position.value : DEFAULT_POSITION;
			return ( -1 !== POSITION_CHOICES.indexOf( value ) ) ? value : DEFAULT_POSITION;
		}

		/**
		 * Whether "Allow customers to move floating cart button" is
		 * currently set to Yes. Read fresh every time it's needed (rather
		 * than cached) so flipping the select instantly enables/disables
		 * dragging with no page reload.
		 *
		 * @return {boolean}
		 */
		function isDragAllowed() {
			return !! ( fields.allowTriggerDrag && 'yes' === fields.allowTriggerDrag.value );
		}

		/**
		 * Clears any inline left/top/right/bottom a preview drag left on
		 * the trigger, handing position back to whichever
		 * `manage-cart-floating-cart-preview-trigger--{position}`
		 * modifier class (and its `--manage-cart-trigger-*-offset` custom
		 * properties) is applied below.
		 *
		 * @return void
		 */
		function clearDragPosition() {
			preview.trigger.style.left = '';
			preview.trigger.style.top = '';
			preview.trigger.style.right = '';
			preview.trigger.style.bottom = '';
		}

		/**
		 * Moves the preview trigger to the corner and offsets the
		 * Position/Horizontal offset/Vertical offset fields currently
		 * show — exactly what
		 * Side_Cart_Admin::render_floating_cart_preview() renders on
		 * page load, kept live here so editing those fields (or turning
		 * dragging off) instantly snaps the preview to match, the same
		 * way every other field on this screen updates the preview
		 * instantly before Save. Always wins over any in-progress
		 * preview drag position.
		 *
		 * @return void
		 */
		function applyPositionFromSettings() {
			var position = currentPosition();
			var hOffset   = fields.horizontalOffset ? clampOffset( parseInt( fields.horizontalOffset.value, 10 ) ) : 20;
			var vOffset   = fields.verticalOffset ? clampOffset( parseInt( fields.verticalOffset.value, 10 ) ) : 20;
			var i;

			clearDragPosition();

			for ( i = 0; i < POSITION_CHOICES.length; i++ ) {
				preview.trigger.classList.remove( 'manage-cart-floating-cart-preview-trigger--' + POSITION_CHOICES[ i ] );
			}
			preview.trigger.classList.add( 'manage-cart-floating-cart-preview-trigger--' + position );

			preview.trigger.style.setProperty( '--manage-cart-trigger-h-offset', hOffset + 'px' );
			preview.trigger.style.setProperty( '--manage-cart-trigger-v-offset', vOffset + 'px' );
		}

		/**
		 * Whether "Enable Floating Cart" is currently checked. Read fresh
		 * every time it's needed (rather than cached), the same pattern as
		 * isDragAllowed() above, so toggling the checkbox always reflects
		 * its current state with no page reload.
		 *
		 * @return {boolean}
		 */
		function isFloatingCartEnabled() {
			return !! ( fields.enabled && fields.enabled.checked );
		}

		/**
		 * Shows or hides the mock preview button to match the current
		 * (unsaved) state of the "Enable Floating Cart" checkbox, using
		 * the same plain `hidden` attribute
		 * Side_Cart_Admin::render_floating_cart_preview() already sets
		 * server-side from the saved setting (see
		 * `.manage-cart-floating-cart-preview-trigger[hidden]` in
		 * side-cart-admin.css). This only ever hides/shows this admin
		 * mock button — it never touches the real, customer-facing
		 * trigger, any option, or any storefront markup.
		 *
		 * @return void
		 */
		function applyEnabledFromFields() {
			preview.trigger.hidden = ! isFloatingCartEnabled();
		}

		/**
		 * Shows or hides the mock count badge to match the current
		 * (unsaved) state of the "Show item count badge" checkbox, using
		 * the same plain `hidden` attribute the server-rendered markup
		 * already sets from the saved setting (see
		 * `.manage-cart-floating-cart-preview-count[hidden]` in
		 * side-cart-admin.css).
		 *
		 * @return void
		 */
		function applyBadgeVisibilityFromFields() {
			if ( ! preview.count ) {
				return;
			}
			preview.count.hidden = !! ( fields.showItemCountBadge && ! fields.showItemCountBadge.checked );
		}

		/**
		 * Requirement (Phase 3H-11): "Hide floating cart when cart is
		 * empty" is preview-only explanatory text, not a change to the
		 * mock button's own visibility — the mock preview cart always has
		 * (illustrative) items in it, so this setting never actually
		 * hides the mock button. Only one of the two pre-rendered,
		 * already-translated status `<span>`s is ever unhidden, matching
		 * whichever state the checkbox currently shows; the other is
		 * always hidden. This introduces no new saved setting and never
		 * touches the real, customer-facing trigger or its own
		 * `hide_when_empty` behavior (Cart_Renderer::render_trigger()).
		 *
		 * @return void
		 */
		function applyEmptyStatusFromFields() {
			var hideWhenEmpty = !! ( fields.hideWhenEmpty && fields.hideWhenEmpty.checked );

			if ( preview.emptyStatusHides ) {
				preview.emptyStatusHides.hidden = ! hideWhenEmpty;
			}
			if ( preview.emptyStatusShows ) {
				preview.emptyStatusShows.hidden = hideWhenEmpty;
			}
		}

		/**
		 * Requirement 1/4: toggling "Enable Floating Cart" or "Show item
		 * count badge" updates the preview instantly, and — like every
		 * other field this script watches — flags the preview as unsaved.
		 * Also re-applies the empty-cart status line so a single handler
		 * covers every General-tab checkbox this script watches.
		 *
		 * @return void
		 */
		function handleVisibilityFieldsChange() {
			applyEnabledFromFields();
			applyBadgeVisibilityFromFields();
			applyEmptyStatusFromFields();

			if ( preview.unsavedBadge ) {
				preview.unsavedBadge.hidden = false;
			}
		}

		var visibilityFields = [ fields.enabled, fields.showItemCountBadge, fields.hideWhenEmpty ];

		for ( var v = 0; v < visibilityFields.length; v++ ) {
			var visibilityField = visibilityFields[ v ];
			if ( ! visibilityField ) {
				continue;
			}
			visibilityField.addEventListener( 'change', handleVisibilityFieldsChange );
			visibilityField.addEventListener( 'input', handleVisibilityFieldsChange );
		}

		/**
		 * Admin-preview-only dragging (Pointer Events: mouse + touch +
		 * pen). See this file's docblock for exactly what this does and
		 * does not persist. Entirely skipped, leaving the button as a
		 * plain non-draggable mock, if Pointer Events or the drag
		 * container aren't available.
		 */
		var supportsPointerEvents = !! window.PointerEvent;
		var isDragging            = false;
		var dragPointerId         = null;
		var dragStartX            = 0;
		var dragStartY            = 0;
		var triggerStartLeft      = 0;
		var triggerStartTop       = 0;

		function getTriggerSize() {
			var rect = preview.trigger.getBoundingClientRect();
			return {
				width: rect.width || preview.trigger.offsetWidth || 58,
				height: rect.height || preview.trigger.offsetHeight || 58
			};
		}

		/**
		 * Clamps a candidate left/top (both relative to the drag
		 * container's own top-left corner) so the button always stays
		 * fully inside the preview browser/stage boundary — never
		 * partway off any edge of it, and never smaller than its actual
		 * rendered size (which already has its own readable minimum via
		 * the Appearance → Button size field).
		 *
		 * @param {number} left Candidate left, relative to dragContainer.
		 * @param {number} top  Candidate top, relative to dragContainer.
		 * @return {{left: number, top: number}} Clamped position.
		 */
		function clampToContainer( left, top ) {
			var containerRect = dragContainer.getBoundingClientRect();
			var size          = getTriggerSize();
			var maxLeft       = Math.max( 0, containerRect.width - size.width );
			var maxTop        = Math.max( 0, containerRect.height - size.height );

			return {
				left: Math.min( Math.max( left, 0 ), maxLeft ),
				top:  Math.min( Math.max( top, 0 ), maxTop )
			};
		}

		function applyDragPosition( left, top ) {
			preview.trigger.style.left   = left + 'px';
			preview.trigger.style.top    = top + 'px';
			preview.trigger.style.right  = 'auto';
			preview.trigger.style.bottom = 'auto';
		}

		function endDrag() {
			if ( ! isDragging ) {
				return;
			}

			isDragging = false;
			preview.trigger.classList.remove( 'manage-cart-floating-cart-preview-trigger--dragging' );

			if ( null !== dragPointerId ) {
				try {
					preview.trigger.releasePointerCapture( dragPointerId );
				} catch ( error ) {
					// Capture may already be released; ignore.
				}
			}

			document.removeEventListener( 'pointermove', onPointerMove );
			document.removeEventListener( 'pointerup', onPointerUp );
			document.removeEventListener( 'pointercancel', onPointerCancel );

			dragPointerId = null;
		}

		function onPointerMove( event ) {
			if ( ! isDragging || event.pointerId !== dragPointerId ) {
				return;
			}

			// Prevent touch-scrolling/selection while dragging.
			event.preventDefault();

			var deltaX = event.clientX - dragStartX;
			var deltaY = event.clientY - dragStartY;
			var next   = clampToContainer( triggerStartLeft + deltaX, triggerStartTop + deltaY );

			applyDragPosition( next.left, next.top );
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
			// Read fresh every time: a page load's worth of listeners
			// stays attached, but only actually starts a drag while the
			// setting is Yes at the moment of the press.
			if ( ! isDragAllowed() ) {
				return;
			}

			// Ignore non-primary mouse buttons (right-click, middle-click).
			if ( 'mouse' === event.pointerType && 0 !== event.button ) {
				return;
			}

			var containerRect = dragContainer.getBoundingClientRect();
			var triggerRect   = preview.trigger.getBoundingClientRect();

			isDragging       = true;
			dragPointerId    = event.pointerId;
			dragStartX       = event.clientX;
			dragStartY       = event.clientY;
			triggerStartLeft = triggerRect.left - containerRect.left;
			triggerStartTop  = triggerRect.top - containerRect.top;

			preview.trigger.classList.add( 'manage-cart-floating-cart-preview-trigger--dragging' );

			try {
				preview.trigger.setPointerCapture( dragPointerId );
			} catch ( error ) {
				// Pointer capture is best-effort; continue without it if unsupported.
			}

			document.addEventListener( 'pointermove', onPointerMove );
			document.addEventListener( 'pointerup', onPointerUp );
			document.addEventListener( 'pointercancel', onPointerCancel );

			event.preventDefault();
		}

		if ( supportsPointerEvents && dragContainer ) {
			preview.trigger.addEventListener( 'pointerdown', onPointerDown );
		}

		/**
		 * Toggles the CSS hook that shows a grab cursor and enables
		 * `touch-action: none` on the trigger (see
		 * `.manage-cart-floating-cart-preview-trigger--draggable` in
		 * side-cart-admin.css) to match whether "Allow customers to move
		 * floating cart button" is currently Yes. When turning dragging
		 * off, also cancels any drag already in progress and snaps the
		 * button back to its configured Position/offsets — requirement
		 * 4: dragging disabled means the button returns to its
		 * configured place, not wherever it was last dropped.
		 *
		 * @return void
		 */
		function updateDraggableState() {
			var allowed = isDragAllowed();

			preview.trigger.classList.toggle( 'manage-cart-floating-cart-preview-trigger--draggable', allowed );

			if ( ! allowed ) {
				endDrag();
				applyPositionFromSettings();
			}
		}

		/**
		 * Applies the current (unsaved) state of the Appearance settings
		 * fields — including Button size and Button border radius — to
		 * the preview trigger's `--manage-cart-trigger-*` custom
		 * properties. This is the single source of truth the button's
		 * `border-radius: var(--manage-cart-trigger-radius, 50%)` and
		 * `width`/`height: var(--manage-cart-trigger-size, 58px)` (see
		 * side-cart-admin.css) read from, so every call re-renders the
		 * button from scratch: 0% always yields square corners, 50%
		 * always yields a full circle when size keeps width and height
		 * equal, and any value between renders a visibly intermediate
		 * rounding — for whatever size is currently set.
		 *
		 * Split out from updatePreview() so it can also be called once on
		 * page load (see bottom of this file) to guarantee the preview
		 * matches whatever the two fields actually show at that moment —
		 * without flipping on the "Unsaved" badge for a load that hasn't
		 * changed anything. A browser restoring a typed-but-unsaved
		 * number-field value after a plain reload (common in WordPress
		 * admin) is exactly the case this initial sync guards against:
		 * without it, the button would keep showing the last
		 * server-rendered (saved) radius/size until the admin's next
		 * edit, even though the fields already show something else.
		 *
		 * @return void
		 */
		function applyAppearanceFromFields() {
			var buttonBgColor = readColor( fields.buttonBgColor, '#7c3aed' );
			var iconColor = readColor( fields.iconColor, '#ffffff' );
			var badgeBgColor = readColor( fields.badgeBgColor, '#1d2327' );
			var badgeTextColor = readColor( fields.badgeTextColor, '#ffffff' );
			var buttonSize = fields.buttonSize ? clampButtonSize( parseInt( fields.buttonSize.value, 10 ) ) : 58;
			var borderRadius = fields.borderRadius ? clampBorderRadius( parseInt( fields.borderRadius.value, 10 ) ) : 50;

			preview.trigger.style.setProperty( '--manage-cart-trigger-bg-color', buttonBgColor );
			preview.trigger.style.setProperty( '--manage-cart-trigger-icon-color', iconColor );
			preview.trigger.style.setProperty( '--manage-cart-trigger-badge-bg-color', badgeBgColor );
			preview.trigger.style.setProperty( '--manage-cart-trigger-badge-text-color', badgeTextColor );
			preview.trigger.style.setProperty( '--manage-cart-trigger-size', buttonSize + 'px' );
			preview.trigger.style.setProperty( '--manage-cart-trigger-radius', borderRadius + '%' );
		}

		/**
		 * Re-renders the preview trigger button from the current
		 * (unsaved) state of the Appearance settings fields, and flags
		 * the preview as unsaved. Bound to every watched Appearance
		 * field's `input`/`change` events below.
		 *
		 * @return void
		 */
		function updatePreview() {
			applyAppearanceFromFields();

			if ( preview.unsavedBadge ) {
				preview.unsavedBadge.hidden = false;
			}
		}

		var watchedFields = [
			fields.buttonBgColor,
			fields.iconColor,
			fields.badgeBgColor,
			fields.badgeTextColor,
			fields.buttonSize,
			fields.borderRadius,
		];

		for ( var i = 0; i < watchedFields.length; i++ ) {
			var field = watchedFields[ i ];
			if ( ! field ) {
				continue;
			}
			field.addEventListener( 'input', updatePreview );
			field.addEventListener( 'change', updatePreview );
		}

		/**
		 * Requirement 5: editing Position or either offset field
		 * repositions the preview instantly, and — like every other
		 * field this script watches — flags the preview as unsaved.
		 *
		 * @return void
		 */
		function handlePositionFieldsChange() {
			applyPositionFromSettings();

			if ( preview.unsavedBadge ) {
				preview.unsavedBadge.hidden = false;
			}
		}

		var positionFields = [ fields.position, fields.horizontalOffset, fields.verticalOffset ];

		for ( var p = 0; p < positionFields.length; p++ ) {
			var positionField = positionFields[ p ];
			if ( ! positionField ) {
				continue;
			}
			positionField.addEventListener( 'input', handlePositionFieldsChange );
			positionField.addEventListener( 'change', handlePositionFieldsChange );
		}

		if ( fields.allowTriggerDrag ) {
			fields.allowTriggerDrag.addEventListener( 'change', function () {
				updateDraggableState();

				if ( preview.unsavedBadge ) {
					preview.unsavedBadge.hidden = false;
				}
			} );
		}

		// Initial state: re-derives the button's appearance (bg/icon/badge
		// colors, size, and border radius) from whatever the Appearance
		// fields actually show right now via applyAppearanceFromFields()
		// (not updatePreview(), so this never reveals the "Unsaved" badge
		// before the admin has changed anything — mirrors
		// assets/js/side-cart-preview.js's own initial render comment).
		// This is normally a no-op, since PHP already rendered the
		// button's starting inline style from the same saved settings
		// these fields start with — but it also covers a browser
		// restoring a typed-but-unsaved field value after a plain reload,
		// so the preview can never be left showing a stale border radius
		// or size the fields have already moved on from.
		// applyPositionFromSettings() is deliberately NOT called here for
		// the analogous reason on the Position/offset side — the
		// server-rendered corner class/offsets are already correct; only
		// the draggable cursor/touch-action hook needs syncing up front.
		//
		// applyEnabledFromFields() and applyBadgeVisibilityFromFields() are
		// called here for the same reason as applyAppearanceFromFields()
		// above: the server already rendered the correct starting
		// `hidden` attributes from the saved settings, but a browser
		// restoring a typed-but-unsaved checkbox state after a plain
		// reload could otherwise leave the mock button showing/hiding the
		// wrong thing until the admin's next edit. applyEmptyStatusFromFields()
		// is the same idea for the "Hide floating cart when cart is
		// empty" status line's two spans.
		applyAppearanceFromFields();
		applyEnabledFromFields();
		applyBadgeVisibilityFromFields();
		applyEmptyStatusFromFields();
		updateDraggableState();
	} );
} )();
