/**
 * Manage Cart — Side Cart panel section/tab switching (Phase 3D).
 *
 * Loaded only on the single shared "Manage Cart" admin screen, on top of
 * the existing app-header-tabs.js (which switches between the top-level
 * "Side Cart" and "Settings" panels). This script handles the navigation
 * added *inside* the Side Cart panel itself: the "Side Cart" / "Floating
 * Cart" section tablist, and each section's own "General" / "Style" tabs.
 *
 * All switching is purely client-side: every section and tab stays in the
 * DOM the whole time (only the `hidden` attribute changes), so unsaved
 * field values and the live preview are preserved automatically when
 * moving between sections/tabs, exactly like the existing top-level tabs.
 * Nothing here submits a form, changes the URL, or reloads the page.
 *
 * Phase 3E-1 added a real settings form to the Floating Cart → General tab.
 * Phase 3E-5 gave that form (and every other General-tab form) its own
 * real Save Changes submit button rendered inside itself, so switching
 * between the "Side Cart" / "Floating Cart" sections no longer needs to
 * repoint any shared button — each section's General tab simply submits
 * its own form.
 *
 * Phase 3F-4: switching to the "Side Cart" section, or to either
 * section's "Style" tab, removes the Floating Cart → General "settings
 * saved" notice from the DOM entirely (see removeFloatingCartSavedNotice()),
 * so it can only ever be seen while Floating Cart → General is the
 * visibly active tab, and can never resurface by navigating back to it.
 *
 * Phase 3F-7 generalizes this to the Side Cart → General "settings
 * saved" notice as well (`data-manage-cart-notice="side-cart-saved"`,
 * see Side_Cart_Admin::render_side_cart_general_tab()): switching to
 * the "Floating Cart" section now removes the Side Cart notice, and
 * switching to either section's "Style" tab now removes *both* saved
 * notices, since neither section's General tab is visible once its
 * Style tab is active. Each notice can therefore only ever be seen
 * while its own section's General tab is the visibly active tab.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		initSectionTabs();
		initStyleTabGroups();
	} );

	/**
	 * Removes the one-time "settings saved" notice identified by the
	 * given `data-manage-cart-notice` value from the DOM entirely, if
	 * present (Phase 3F-4, generalized in Phase 3F-7 to cover both
	 * `"side-cart-saved"` and `"floating-cart-saved"`; see
	 * Side_Cart_Admin::render_side_cart_general_tab() and
	 * ::render_floating_cart_general_tab()). Kept as a local copy of the
	 * same-named helper in assets/js/app-header-tabs.js so this file
	 * works standalone regardless of script load order.
	 *
	 * @param {string} noticeKey 'side-cart-saved' or 'floating-cart-saved'.
	 * @return void
	 */
	function removeSavedNotice( noticeKey ) {
		var notice = document.querySelector( '[data-manage-cart-notice="' + noticeKey + '"]' );

		if ( notice && notice.parentNode ) {
			notice.parentNode.removeChild( notice );
		}
	}

	/**
	 * Removes both one-time "settings saved" notices (Side Cart →
	 * General and Floating Cart → General) from the DOM entirely, if
	 * present.
	 *
	 * @return void
	 */
	function removeBothSavedNotices() {
		removeSavedNotice( 'side-cart-saved' );
		removeSavedNotice( 'floating-cart-saved' );
	}

	/**
	 * Wires up the top-level "Side Cart" / "Floating Cart" section
	 * tablist (#manage-cart-section-nav), toggling the matching
	 * `.manage-cart-section-panel` elements.
	 *
	 * @return void
	 */
	function initSectionTabs() {
		var nav = document.getElementById( 'manage-cart-section-nav' );
		if ( ! nav ) {
			return;
		}

		var tabs = Array.prototype.slice.call(
			nav.querySelectorAll( '[data-manage-cart-section]' )
		);

		if ( ! tabs.length ) {
			return;
		}

		/**
		 * Activates the given section: shows its panel, hides the other
		 * one, and updates the tabs' active styling/aria/tabindex. Each
		 * section's General tab has its own real Save Changes submit
		 * button inside its own form (Phase 3E-5), so there is no shared
		 * button to repoint here anymore. Phase 3F-4: activating any
		 * section other than "floating-cart" also removes the Floating
		 * Cart → General "settings saved" notice from the DOM (see
		 * removeSavedNotice()). Phase 3F-7: activating "floating-cart"
		 * likewise removes the Side Cart → General "settings saved"
		 * notice, since that section's General tab is no longer visible.
		 *
		 * @param {string} sectionKey 'side-cart' or 'floating-cart'.
		 * @return void
		 */
		function activateSection( sectionKey ) {
			var targetPanel = document.getElementById( 'manage-cart-section-panel-' + sectionKey );
			if ( ! targetPanel ) {
				return;
			}

			tabs.forEach( function ( tab ) {
				var tabKey = tab.getAttribute( 'data-manage-cart-section' );
				var isActive = ( tabKey === sectionKey );

				tab.classList.toggle( 'is-active', isActive );
				tab.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
				tab.setAttribute( 'tabindex', isActive ? '0' : '-1' );

				var panel = document.getElementById( 'manage-cart-section-panel-' + tabKey );
				if ( panel ) {
					panel.hidden = ! isActive;
				}
			} );

			if ( 'floating-cart' === sectionKey ) {
				removeSavedNotice( 'side-cart-saved' );
			} else {
				removeSavedNotice( 'floating-cart-saved' );
			}
		}

		bindTabGroup( tabs, function ( tab ) {
			activateSection( tab.getAttribute( 'data-manage-cart-section' ) );
		} );
	}

	/**
	 * Wires up every "General" / "Style" tab group on the page
	 * (`.manage-cart-style-nav`). Each group is scoped to its own
	 * enclosing `.manage-cart-section-panel`, so the Side Cart and
	 * Floating Cart sections keep independent General/Style selections.
	 *
	 * @return void
	 */
	function initStyleTabGroups() {
		var navs = Array.prototype.slice.call(
			document.querySelectorAll( '.manage-cart-style-nav' )
		);

		navs.forEach( initStyleTabGroup );
	}

	/**
	 * Wires up a single "General" / "Style" tab group.
	 *
	 * @param {Element} nav The `.manage-cart-style-nav` tablist element.
	 * @return void
	 */
	function initStyleTabGroup( nav ) {
		var tabs = Array.prototype.slice.call(
			nav.querySelectorAll( '[data-manage-cart-style-tab]' )
		);

		if ( ! tabs.length ) {
			return;
		}

		var scope = nav.closest( '.manage-cart-section-panel' ) || document;

		/**
		 * Activates the given tab within this group's scope: shows its
		 * `[data-manage-cart-style-panel]` panel, hides the other one, and
		 * updates the tabs' active styling/aria/tabindex. Phase 3F-4: when
		 * this group belongs to the Floating Cart section and the tab
		 * being activated is not "General", also removes the Floating
		 * Cart → General "settings saved" notice from the DOM (see
		 * removeSavedNotice()).
		 *
		 * Phase 3F-7 generalizes this: switching to this group's "Style"
		 * tab (in either section) removes *both* saved notices, not just
		 * the Floating Cart one — since a section's own "General" tab is
		 * the only place either notice is shown, and it is no longer
		 * visible once that section's "Style" tab is active.
		 *
		 * @param {string} tabKey 'general' or 'style'.
		 * @return void
		 */
		function activateTab( tabKey ) {
			var targetPanel = scope.querySelector( '[data-manage-cart-style-panel="' + tabKey + '"]' );
			if ( ! targetPanel ) {
				return;
			}

			tabs.forEach( function ( tab ) {
				var key = tab.getAttribute( 'data-manage-cart-style-tab' );
				var isActive = ( key === tabKey );

				tab.classList.toggle( 'is-active', isActive );
				tab.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
				tab.setAttribute( 'tabindex', isActive ? '0' : '-1' );

				var panel = scope.querySelector( '[data-manage-cart-style-panel="' + key + '"]' );
				if ( panel ) {
					panel.hidden = ! isActive;
				}
			} );

			if ( 'general' !== tabKey ) {
				removeBothSavedNotices();
			}
		}

		bindTabGroup( tabs, function ( tab ) {
			activateTab( tab.getAttribute( 'data-manage-cart-style-tab' ) );
		} );
	}

	/**
	 * Attaches click and left/right-arrow-key handling to a list of tab
	 * buttons, calling `onActivate` with whichever tab should become
	 * active. Shared by both the section tablist and every General/Style
	 * tab group.
	 *
	 * @param {Element[]} tabs       Ordered list of tab button elements.
	 * @param {Function}  onActivate Called with the tab element to activate.
	 * @return void
	 */
	function bindTabGroup( tabs, onActivate ) {
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				onActivate( tab );
			} );

			tab.addEventListener( 'keydown', function ( event ) {
				if ( 'ArrowLeft' !== event.key && 'ArrowRight' !== event.key ) {
					return;
				}

				event.preventDefault();

				var currentIndex = tabs.indexOf( tab );
				var nextIndex;

				if ( 'ArrowLeft' === event.key ) {
					nextIndex = ( currentIndex - 1 + tabs.length ) % tabs.length;
				} else {
					nextIndex = ( currentIndex + 1 ) % tabs.length;
				}

				var nextTab = tabs[ nextIndex ];
				onActivate( nextTab );
				nextTab.focus();
			} );
		} );
	}
} )();
