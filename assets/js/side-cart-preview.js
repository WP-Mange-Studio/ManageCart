/**
 * Manage Cart — Side Cart live preview (Phase 2C).
 *
 * Loaded only on "Manage Cart → Side Cart". Listens for changes to the
 * settings fields on this page and updates the preview panel and its text
 * summary instantly, without saving. This script never requests
 * WooCommerce or Store API data: every value it reads comes from the
 * settings form fields already on this admin page, and every value it
 * writes goes back into the static mock preview markup rendered by
 * Side_Cart_Admin::render_preview(). Server-side sanitization and the
 * Save/Reset logic are untouched; this only affects what the admin sees
 * before clicking Save Changes.
 *
 * Phase 3G-2B: also watches the Side Cart → Appearance tab's color and
 * border radius fields (panel/header background, header/body text,
 * checkout button colors, border radius) and, on every change, writes
 * them onto the preview panel as the same `--manage-cart-*` CSS custom
 * properties Cart_Renderer::get_appearance_style_string() writes on the
 * real frontend panel — so the mock preview mirrors the real Side Cart's
 * appearance settings instantly, before Save Changes is clicked, exactly
 * like every other field this script already watches. Because both the
 * General and Appearance subtab panels now live in the DOM alongside one
 * shared preview column (see render_panel_content() in
 * class-side-cart-admin.php), this works no matter which of the two
 * subtabs is currently visible.
 *
 * Cart layout (Drawer vs Popup): both the panel and its containing
 * stage receive a `--drawer`/`--popup` modifier class (mirroring
 * `Side_Cart_Admin::render_preview()`'s server-rendered markup) so the
 * two layouts can look genuinely different in this preview, exactly as
 * they do on the real frontend (see the `.manage-cart-panel--drawer`
 * and `.manage-cart-panel--popup` rules in assets/css/frontend.css):
 * Drawer hugs one edge of the stage and stretches to its full height;
 * Popup is a centered modal/card over its own dark, translucent
 * backdrop, with internal scrolling if its content is taller than the
 * available space. Switching the "Cart layout" field re-applies both
 * classes instantly, before Save.
 *
 * Localized strings and initial values are provided by
 * `ManageCartSideCartPreview` via wp_localize_script().
 *
 * Extension point: after the preview finishes its initial render and
 * after every later update pass, this script dispatches one bubbling
 * `managecart:admin-side-cart-preview-updated` CustomEvent on
 * `document`, with the current preview panel element in
 * `event.detail.panel` (see docs/hooks.md). It is read-only — nothing
 * here listens for it, and no listener can alter what this script
 * rendered.
 *
 * Preview product data: the two static line items no longer describe
 * only "Sample Product A" / "Sample Product B". `ManageCartSideCartPreview.products`
 * (localized alongside the strings above) carries up to two entries —
 * each either a real, published WooCommerce product prepared
 * server-side by `Side_Cart_Admin::get_preview_products()`, or, when
 * the store has fewer than two eligible products, a translated sample
 * entry with the same shape. This script applies each entry's `name`
 * to the matching line item's title and, when available, its
 * `image_url`/`image_alt` to that item's thumbnail — the same data
 * already used for the server-rendered initial markup, just kept in
 * sync here too. `image_url` is always WooCommerce's own local
 * placeholder when a product has no real image, never a remote URL;
 * if no product data is available at all, any inline background-image
 * is cleared so the existing CSS gradient placeholder in
 * side-cart-admin.css shows through instead. This never issues a
 * request of any kind — it only reads the object already provided by
 * wp_localize_script().
 */
( function () {
	'use strict';

	/**
	 * Reads the localized settings object. Bail out quietly if it is
	 * missing so a misconfigured enqueue never throws admin-wide errors.
	 */
	var i18n = window.ManageCartSideCartPreview || null;

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( ! i18n ) {
			return;
		}

		var fields = {
			layout: document.getElementById( 'manage-cart-side-cart-layout' ),
			drawerSide: document.getElementById( 'manage-cart-side-cart-drawer-side' ),
			desktopWidth: document.getElementById( 'manage-cart-side-cart-desktop-width' ),
			autoOpen: document.getElementById( 'manage-cart-side-cart-auto-open' ),
			heading: document.getElementById( 'manage-cart-side-cart-heading' ),
			showCartIcon: document.getElementById( 'manage-cart-side-cart-show-cart-icon' ),
			showItemCount: document.getElementById( 'manage-cart-side-cart-show-item-count' ),
			showProductImage: document.getElementById( 'manage-cart-side-cart-show-product-image' ),
			showVariationAttributes: document.getElementById( 'manage-cart-side-cart-show-variation-attributes' ),
			showQuantityControls: document.getElementById( 'manage-cart-side-cart-show-quantity-controls' ),
			showRemoveButton: document.getElementById( 'manage-cart-side-cart-show-remove-button' ),
			panelBgColor: document.getElementById( 'manage-cart-side-cart-panel-bg-color' ),
			headerBgColor: document.getElementById( 'manage-cart-side-cart-header-bg-color' ),
			headerTextColor: document.getElementById( 'manage-cart-side-cart-header-text-color' ),
			bodyTextColor: document.getElementById( 'manage-cart-side-cart-body-text-color' ),
			checkoutButtonColor: document.getElementById( 'manage-cart-side-cart-checkout-button-color' ),
			checkoutButtonTextColor: document.getElementById( 'manage-cart-side-cart-checkout-button-text-color' ),
			borderRadius: document.getElementById( 'manage-cart-side-cart-border-radius' ),
		};

		var preview = {
			panel: document.getElementById( 'manage-cart-preview-panel' ),
			stage: document.getElementById( 'manage-cart-preview-stage' ),
			icon: document.getElementById( 'manage-cart-preview-icon' ),
			headingText: document.getElementById( 'manage-cart-preview-heading-text' ),
			count: document.getElementById( 'manage-cart-preview-count' ),
			summaryLayout: document.getElementById( 'manage-cart-preview-summary-layout' ),
			summaryPosition: document.getElementById( 'manage-cart-preview-summary-position' ),
			summaryWidth: document.getElementById( 'manage-cart-preview-summary-width' ),
			summaryAutoOpen: document.getElementById( 'manage-cart-preview-summary-autoopen' ),
			unsavedBadge: document.getElementById( 'manage-cart-preview-unsaved-badge' ),
		};

		// If the settings form or the preview markup is not present (for
		// example, a future page reusing this script without both), do
		// nothing rather than risk a script error on the page.
		if ( ! preview.panel || ! fields.layout ) {
			return;
		}

		// These four elements repeat once per sample line item in the
		// preview, so they are collected as NodeLists (scoped to the
		// preview panel) rather than single ids.
		var itemImages = preview.panel.querySelectorAll( '.manage-cart-preview-item-image' );
		var itemTitles = preview.panel.querySelectorAll( '.manage-cart-preview-item-title' );
		var itemVariations = preview.panel.querySelectorAll( '.manage-cart-preview-item-variation' );
		var itemQtyControls = preview.panel.querySelectorAll( '.manage-cart-preview-item-qty' );
		var itemRemoveButtons = preview.panel.querySelectorAll( '.manage-cart-preview-item-remove' );

		// Up to two prepared preview products (real WooCommerce products,
		// or translated sample fallbacks in the same shape), localized by
		// Assets::enqueue_side_cart_preview_script(). Never re-fetched;
		// read once from the config object already on the page.
		var previewProducts = ( i18n && Array.isArray( i18n.products ) ) ? i18n.products : [];

		/**
		 * Applies the prepared preview product names and thumbnails to
		 * the static line items, in place of the previous hardcoded
		 * "Sample Product A" / "Sample Product B" text and empty
		 * thumbnails. Runs independently of which fields changed, since
		 * the underlying product data itself never changes while this
		 * page is open — it only mirrors what
		 * `Side_Cart_Admin::render_preview()` already put on the page,
		 * and keeps it in sync across every preview update pass.
		 *
		 * @return void
		 */
		function applyPreviewProducts() {
			for ( var p = 0; p < itemImages.length; p++ ) {
				var product = previewProducts[ p ] || null;
				var thumb   = itemImages[ p ];
				var title   = itemTitles[ p ];

				if ( title && product && product.name ) {
					title.textContent = product.name;
				}

				if ( ! thumb ) {
					continue;
				}

				if ( product && product.image_url ) {
					// Real product thumbnail (or WooCommerce's own local
					// placeholder when the product has none) — set
					// regardless of the current "Show product image"
					// state; visibility itself is handled separately by
					// toggleAll() below, exactly as before.
					thumb.style.backgroundImage = 'url(' + product.image_url + ')';

					if ( product.image_alt ) {
						thumb.setAttribute( 'title', product.image_alt );
					} else {
						thumb.removeAttribute( 'title' );
					}
				} else {
					// No real product/image data available: fall back to
					// the existing safe local placeholder already defined
					// in side-cart-admin.css (a CSS gradient background,
					// not a remote asset) by clearing any inline
					// background-image.
					thumb.style.backgroundImage = '';
					thumb.removeAttribute( 'title' );
				}
			}
		}

		/**
		 * Sets the `hidden` attribute on every element in a NodeList.
		 *
		 * @param {NodeList} nodeList Elements to toggle.
		 * @param {boolean}  visible  Whether the elements should be shown.
		 * @return void
		 */
		function toggleAll( nodeList, visible ) {
			for ( var n = 0; n < nodeList.length; n++ ) {
				nodeList[ n ].hidden = ! visible;
			}
		}

		var desktopWidthMin = parseInt( fields.desktopWidth.getAttribute( 'min' ), 10 ) || 280;
		var desktopWidthMax = parseInt( fields.desktopWidth.getAttribute( 'max' ), 10 ) || 560;

		/**
		 * Clamps a desktop width value the same way the server does, so the
		 * preview never shows an out-of-range width even before Save.
		 *
		 * @param {number} value Raw parsed width.
		 * @return {number} Clamped width.
		 */
		function clampWidth( value ) {
			if ( isNaN( value ) ) {
				return desktopWidthMin;
			}
			return Math.max( desktopWidthMin, Math.min( desktopWidthMax, value ) );
		}

		var borderRadiusMin = fields.borderRadius ? ( parseInt( fields.borderRadius.getAttribute( 'min' ), 10 ) || 0 ) : 0;
		var borderRadiusMax = fields.borderRadius ? ( parseInt( fields.borderRadius.getAttribute( 'max' ), 10 ) || 32 ) : 32;

		/**
		 * Clamps a border radius value the same way the server does, so
		 * the preview never shows an out-of-range radius even before
		 * Save (Phase 3G-2B).
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
		 * when the field is missing or empty (Phase 3G-2B). Native
		 * `<input type="color">` fields always carry a well-formed
		 * `#rrggbb` value once rendered, so no further validation is
		 * needed here; this mirrors the server's own fallback-to-default
		 * behavior in Side_Cart_Settings::sanitize_color() closely enough
		 * for a purely visual, unsaved preview.
		 *
		 * @param {HTMLInputElement|null} field        The color input.
		 * @param {string}                fallbackColor Default hex color.
		 * @return {string} A hex color.
		 */
		function readColor( field, fallbackColor ) {
			return ( field && field.value ) ? field.value : fallbackColor;
		}

		/**
		 * Name of the public extension-point event this script dispatches
		 * once the admin Side Cart preview has finished rendering or
		 * updating.
		 *
		 * @var string
		 */
		var PREVIEW_UPDATED_EVENT = 'managecart:admin-side-cart-preview-updated';

		/**
		 * Announces that the admin preview panel has just finished its
		 * render/update pass.
		 *
		 * This is a read-only extension point for add-ons (for example
		 * ManageCart Pro) that want to decorate the admin preview after
		 * this script has rebuilt it. It is dispatched on `document` and
		 * bubbles, carries the current preview panel element in
		 * `event.detail.panel`, and is fired once on initial render and
		 * once at the end of every normal preview update.
		 *
		 * It is purely additive: nothing in this file reads the event,
		 * no listener can change what this script already rendered, and
		 * a listener that throws cannot break the preview (a DOM event
		 * listener's exception does not propagate back to the code that
		 * called dispatchEvent). The CustomEvent guard below keeps a
		 * browser without the constructor from throwing here.
		 *
		 * No request of any kind is made: this is a DOM event, not a
		 * fetch/XHR/AJAX/REST call, and nothing is saved or persisted.
		 *
		 * @return void
		 */
		function dispatchPreviewUpdated() {
			if ( 'function' !== typeof window.CustomEvent ) {
				return;
			}

			document.dispatchEvent( new window.CustomEvent( PREVIEW_UPDATED_EVENT, {
				bubbles: true,
				detail: { panel: preview.panel },
			} ) );
		}

		/**
		 * Re-renders the preview panel and its text summary from the
		 * current (unsaved) state of the settings fields.
		 *
		 * @return void
		 */
		function updatePreview() {
			var layout = fields.layout.value;
			var drawerSide = fields.drawerSide ? fields.drawerSide.value : 'right';
			var width = clampWidth( parseInt( fields.desktopWidth.value, 10 ) );
			var autoOpen = !! ( fields.autoOpen && fields.autoOpen.checked );
			var heading = fields.heading ? fields.heading.value : '';
			var showCartIcon = !! ( fields.showCartIcon && fields.showCartIcon.checked );
			var showItemCount = !! ( fields.showItemCount && fields.showItemCount.checked );
			var showProductImage = !! ( fields.showProductImage && fields.showProductImage.checked );
			var showVariationAttributes = !! ( fields.showVariationAttributes && fields.showVariationAttributes.checked );
			var showQuantityControls = !! ( fields.showQuantityControls && fields.showQuantityControls.checked );
			var showRemoveButton = !! ( fields.showRemoveButton && fields.showRemoveButton.checked );
			var isDrawer = ( 'drawer' === layout );

			var panelBgColor = readColor( fields.panelBgColor, '#ffffff' );
			var headerBgColor = readColor( fields.headerBgColor, '#ffffff' );
			var headerTextColor = readColor( fields.headerTextColor, '#1d2327' );
			var bodyTextColor = readColor( fields.bodyTextColor, '#1d2327' );
			var checkoutButtonColor = readColor( fields.checkoutButtonColor, '#7c3aed' );
			var checkoutButtonTextColor = readColor( fields.checkoutButtonTextColor, '#ffffff' );
			var borderRadius = fields.borderRadius ? clampBorderRadius( parseInt( fields.borderRadius.value, 10 ) ) : 16;

			// Panel classes: mirror the same class names the server uses,
			// so the existing CSS (drawer/popup, left/right) applies as-is.
			var panelClasses = [ 'manage-cart-preview-panel', 'manage-cart-preview-panel--' + layout ];
			if ( isDrawer ) {
				panelClasses.push( 'manage-cart-preview-panel--' + drawerSide );
			}
			preview.panel.className = panelClasses.join( ' ' );
			preview.panel.setAttribute( 'data-layout', layout );
			preview.panel.setAttribute( 'data-drawer-side', drawerSide );

			// The stage also gets a layout modifier class (Drawer vs
			// Popup), mirroring the server-rendered markup, so switching
			// "Cart layout" instantly swaps between the edge-hugging
			// drawer stage and the centered, backdropped popup stage —
			// see side-cart-admin.css.
			if ( preview.stage ) {
				preview.stage.className = 'manage-cart-preview-stage manage-cart-preview-stage--' + layout;
			}
			preview.panel.style.setProperty( '--manage-cart-preview-width', width + 'px' );
			preview.panel.style.setProperty( '--manage-cart-panel-bg-color', panelBgColor );
			preview.panel.style.setProperty( '--manage-cart-header-bg-color', headerBgColor );
			preview.panel.style.setProperty( '--manage-cart-header-text-color', headerTextColor );
			preview.panel.style.setProperty( '--manage-cart-body-text-color', bodyTextColor );
			preview.panel.style.setProperty( '--manage-cart-checkout-btn-bg-color', checkoutButtonColor );
			preview.panel.style.setProperty( '--manage-cart-checkout-btn-text-color', checkoutButtonTextColor );
			preview.panel.style.setProperty( '--manage-cart-panel-radius', borderRadius + 'px' );

			if ( preview.headingText ) {
				preview.headingText.textContent = heading;
			}

			if ( preview.icon ) {
				preview.icon.hidden = ! showCartIcon;
			}

			if ( preview.count ) {
				preview.count.hidden = ! showItemCount;
			}

			applyPreviewProducts();
			toggleAll( itemImages, showProductImage );
			toggleAll( itemVariations, showVariationAttributes );
			toggleAll( itemQtyControls, showQuantityControls );
			toggleAll( itemRemoveButtons, showRemoveButton );

			if ( preview.summaryLayout ) {
				var layoutLabel = isDrawer ? i18n.layoutDrawerLabel : i18n.layoutPopupLabel;
				preview.summaryLayout.textContent = i18n.layoutFormat.replace( '%s', layoutLabel );
			}

			if ( preview.summaryPosition ) {
				preview.summaryPosition.hidden = ! isDrawer;
				var positionLabel = ( 'left' === drawerSide ) ? i18n.positionLeftLabel : i18n.positionRightLabel;
				preview.summaryPosition.textContent = i18n.positionFormat.replace( '%s', positionLabel );
			}

			if ( preview.summaryWidth ) {
				preview.summaryWidth.textContent = i18n.widthFormat.replace( '%d', width );
			}

			if ( preview.summaryAutoOpen ) {
				preview.summaryAutoOpen.textContent = autoOpen ? i18n.autoOpenOnLabel : i18n.autoOpenOffLabel;
			}

			if ( preview.unsavedBadge ) {
				preview.unsavedBadge.hidden = false;
			}

			// Last statement in the pass, so listeners always see the
			// fully updated panel and can never observe a half-rendered
			// preview.
			dispatchPreviewUpdated();
		}

		var watchedFields = [
			fields.layout,
			fields.drawerSide,
			fields.desktopWidth,
			fields.autoOpen,
			fields.heading,
			fields.showCartIcon,
			fields.showItemCount,
			fields.showProductImage,
			fields.showVariationAttributes,
			fields.showQuantityControls,
			fields.showRemoveButton,
			fields.panelBgColor,
			fields.headerBgColor,
			fields.headerTextColor,
			fields.bodyTextColor,
			fields.checkoutButtonColor,
			fields.checkoutButtonTextColor,
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

		// Initial render. The panel's starting markup is rendered
		// server-side by Side_Cart_Admin::render_preview(), so
		// updatePreview() is deliberately NOT called here — doing so
		// would reveal the "unsaved changes" badge before the admin has
		// changed anything. applyPreviewProducts() is safe to call
		// directly, though: it only mirrors the same prepared product
		// data the server already rendered and never touches the
		// unsaved-state badge. The event is dispatched directly after,
		// so a listener gets exactly one initial pass describing the
		// state already on screen, and then one more for every later
		// update.
		applyPreviewProducts();
		dispatchPreviewUpdated();
	} );
} )();
