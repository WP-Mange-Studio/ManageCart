/**
 * Manage Cart — shared app header tab switching (Phase 2E).
 *
 * Loaded only on the single top-level "Manage Cart" admin screen, which
 * renders both the Side Cart panel and the Settings panel in the DOM at
 * the same time. Clicking a header tab never navigates or reloads the
 * page: it only toggles the `hidden` attribute on the two existing panels,
 * updates the tab buttons' active styling/aria-selected/tabindex, updates
 * the browser URL with history.replaceState, and keeps each Settings API
 * form's hidden `_wp_http_referer` field in sync with the currently active
 * tab, so pressing that form's own Save Changes button returns to
 * whichever tab was active at the time. Because both panels stay in the
 * DOM the whole time (only `hidden` changes), any unsaved Side Cart field
 * values and its live preview are preserved automatically when switching
 * away and back — nothing here re-renders either panel or makes a network
 * request.
 *
 * Phase 3E-5 removes the single shared header "Save Changes" button this
 * file used to manage entirely (previous phases repointed its `form`
 * attribute, then submitted the currently-visible form directly via
 * `requestSubmit()`). Each settings form now renders its own real Save
 * Changes submit button inside itself (see Admin and Side_Cart_Admin), so
 * this script's only remaining responsibilities are tab navigation and
 * keeping every form's `_wp_http_referer` field pointed at the tab the
 * merchant is actually looking at.
 *
 * Phase 3F-2 generalizes the one-time "notice" URL cleanup (previously
 * `stripSettingsUpdatedFromUrl()`, handling only the Settings API's own
 * `settings-updated` parameter) into `stripOneTimeNoticeParamsFromUrl()`,
 * which also strips this plugin's own custom one-time notice flags —
 * `manage_cart_reset` and `manage_cart_side_cart_reset` — so every
 * "saved"/"reset" notice on this screen is shown exactly once and never
 * reappears from a plain reload or a back/forward history restore.
 *
 * Phase 3F-3 removes `manage_cart_floating_cart_saved` from this list:
 * the Floating Cart → General save notice is no longer driven by a URL
 * flag at all. It is now shown from a one-time, current-user transient
 * that the server deletes the instant it is displayed (see
 * Side_Cart_Admin::render_floating_cart_general_tab()), so there is no
 * corresponding URL parameter left for this script to strip.
 *
 * Phase 3F-4: switching to the Settings tab now also removes the
 * Floating Cart → General "settings saved" notice from the DOM entirely
 * (see removeFloatingCartSavedNotice()), rather than relying solely on
 * the `hidden` attribute/CSS to keep it out of view. The notice is
 * identified by its `data-manage-cart-notice="floating-cart-saved"`
 * attribute (see Side_Cart_Admin::render_floating_cart_general_tab()).
 * assets/js/side-cart-section-tabs.js does the same when switching to
 * the Side Cart section or to either section's Style tab, so the notice
 * can only ever be seen while Floating Cart → General is the visibly
 * active tab, and can never resurface by switching back.
 *
 * Phase 3F-7 generalizes this to the Side Cart → General "settings
 * saved" notice (`data-manage-cart-notice="side-cart-saved"`, see
 * Side_Cart_Admin::render_side_cart_general_tab()) as well: switching
 * to the global Settings tab now removes *both* one-time saved notices
 * from the DOM, since neither the Side Cart nor the Floating Cart panel
 * is visible once Settings is active. assets/js/side-cart-section-tabs.js
 * removes whichever of the two notices belongs to the section being
 * navigated away from, and removes both when switching to either
 * section's Style tab, so each notice can only ever be seen while its
 * own General tab is the visibly active tab.
 */
( function () {
	'use strict';

	/**
	 * Removes every one-time "notice" query parameter this admin screen
	 * uses from the browser URL via history.replaceState(), without a
	 * page reload:
	 *
	 * - `settings-updated` — set by the Settings API/options.php after a
	 *   Global Settings or Side Cart → General save; that save's notice
	 *   is pulled from the `settings_errors` transient and rendered by
	 *   the time this script runs.
	 * - `manage_cart_reset` / `manage_cart_side_cart_reset` — set by this
	 *   plugin's own Reset Settings / Reset Side Cart Settings /
	 *   Reset Floating Cart Settings admin-post handlers.
	 *
	 * Each of these has already done its one job — telling this exact
	 * page load which notice to render — by the time this script runs.
	 * Stripping them now means a later plain browser reload (or a restore
	 * from back/forward history) requests the page without any of them,
	 * so no leftover or stale "saved"/"reset" notice can be shown again
	 * from a page that merely re-displays the same URL.
	 *
	 * The Floating Cart → General save notice is intentionally not in
	 * this list (Phase 3F-3): it no longer has a URL flag at all, since
	 * it is now shown from a one-time, current-user transient that the
	 * server deletes the instant it is rendered.
	 *
	 * @return void
	 */
	function stripOneTimeNoticeParamsFromUrl() {
		if ( ! window.history || ! window.history.replaceState ) {
			return;
		}

		var oneTimeParams = [
			'settings-updated',
			'manage_cart_reset',
			'manage_cart_side_cart_reset'
		];

		try {
			var url = new URL( window.location.href );
			var changed = false;

			oneTimeParams.forEach( function ( param ) {
				if ( url.searchParams.has( param ) ) {
					url.searchParams.delete( param );
					changed = true;
				}
			} );

			if ( ! changed ) {
				return;
			}

			window.history.replaceState( null, '', url.toString() );
		} catch ( e ) {
			// Silently skip if the URL API is unavailable.
		}
	}

	/**
	 * Updates the hidden `_wp_http_referer` field inside every Settings
	 * API form on the page (Global Settings, Side Cart → General, and
	 * Floating Cart → General — every form carries the shared
	 * `manage-cart-savable-form` class, see Admin and Side_Cart_Admin) so
	 * its value includes the given tab in the `tab` query parameter.
	 * `settings_fields()` renders this hidden field automatically in each
	 * form; WordPress uses its value to decide which page to return to
	 * after a successful save, so this keeps each form's own Save Changes
	 * button landing back on whichever top-level tab was active when it
	 * was clicked, regardless of which tab the page originally loaded on.
	 *
	 * @param {string} relativeUrl Path + query string (no origin) to
	 *                             write into each referer field.
	 * @return void
	 */
	function updateRefererFields( relativeUrl ) {
		var forms = document.querySelectorAll( '.manage-cart-savable-form' );

		for ( var i = 0; i < forms.length; i++ ) {
			var refererField = forms[ i ].querySelector( 'input[name="_wp_http_referer"]' );
			if ( refererField ) {
				refererField.value = relativeUrl;
			}
		}
	}

	/**
	 * Removes the one-time "settings saved" notice identified by the
	 * given `data-manage-cart-notice` value from the DOM entirely, if
	 * present (Phase 3F-4, generalized in Phase 3F-7 to cover both
	 * `"side-cart-saved"` and `"floating-cart-saved"`; see
	 * Side_Cart_Admin::render_side_cart_general_tab() and
	 * ::render_floating_cart_general_tab()). Actually removing the
	 * element — rather than only relying on an ancestor's `hidden`
	 * attribute/CSS — guarantees it cannot be made visible again by any
	 * later navigation back to that tab within the same page load; the
	 * one-time, current-user transient behind it has already done its
	 * one job by the time this element exists in the DOM.
	 *
	 * Shared by this file and assets/js/side-cart-section-tabs.js, each
	 * of which calls it whenever the merchant navigates away from the
	 * General tab that notice belongs to.
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
	 * present. Used whenever navigation makes neither General tab
	 * visible any longer — e.g. switching to the global Settings tab.
	 *
	 * @return void
	 */
	function removeBothSavedNotices() {
		removeSavedNotice( 'side-cart-saved' );
		removeSavedNotice( 'floating-cart-saved' );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		stripOneTimeNoticeParamsFromUrl();

		var tabs = Array.prototype.slice.call(
			document.querySelectorAll( '#manage-cart-app-header-tabs [data-manage-cart-tab]' )
		);

		if ( ! tabs.length ) {
			return;
		}

		/**
		 * Returns the panel element for a given tab key, or null.
		 *
		 * @param {string} tabKey 'side-cart' or 'settings'.
		 * @return {Element|null}
		 */
		function getPanel( tabKey ) {
			return document.getElementById( 'manage-cart-panel-' + tabKey );
		}

		/**
		 * Activates the given tab: shows its panel, hides the other panel,
		 * updates tab styling/aria/tabindex, keeps every savable form's
		 * `_wp_http_referer` field in sync with this tab, and optionally
		 * records the change in the URL without reloading.
		 *
		 * @param {string}  tabKey        'side-cart' or 'settings'.
		 * @param {boolean} updateHistory Whether to update the URL via
		 *                                history.replaceState.
		 * @return void
		 */
		function activateTab( tabKey, updateHistory ) {
			var targetPanel = getPanel( tabKey );
			if ( ! targetPanel ) {
				return;
			}

			for ( var i = 0; i < tabs.length; i++ ) {
				var tab = tabs[ i ];
				var isActive = tab.getAttribute( 'data-manage-cart-tab' ) === tabKey;

				tab.classList.toggle( 'is-active', isActive );
				tab.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
				tab.setAttribute( 'tabindex', isActive ? '0' : '-1' );
			}

			// Generalized over every registered tab (Cart Display,
			// Settings, and — Free edition only — Upgrade to Pro), so
			// this loop works unchanged regardless of how many tabs are
			// present: each tab's own panel is shown only when its key
			// matches the one being activated, every other tab's panel
			// is hidden.
			for ( var i = 0; i < tabs.length; i++ ) {
				var otherPanel = getPanel( tabs[ i ].getAttribute( 'data-manage-cart-tab' ) );
				if ( otherPanel ) {
					otherPanel.hidden = ( otherPanel !== targetPanel );
				}
			}

			if ( 'settings' === tabKey ) {
				removeBothSavedNotices();
			}

			try {
				var url = new URL( window.location.href );
				url.searchParams.set( 'tab', tabKey );

				updateRefererFields( url.pathname + url.search );

				if ( updateHistory && window.history && window.history.replaceState ) {
					window.history.replaceState( null, '', url.toString() );
				}
			} catch ( e ) {
				// Silently skip the URL/referer update if the URL API is
				// unavailable; the tab switch itself still works.
			}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				activateTab( tab.getAttribute( 'data-manage-cart-tab' ), true );
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
				activateTab( nextTab.getAttribute( 'data-manage-cart-tab' ), true );
				nextTab.focus();
			} );
		} );
	} );
} )();
