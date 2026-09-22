/**
 * Manage Cart — Side Cart Live Preview "Preview state" switch (Phase 3I-1).
 *
 * Loaded only on "Manage Cart → Side Cart", alongside
 * assets/js/side-cart-preview.js. Lets the admin flip the existing,
 * shared right-side Live Preview between its normal "Cart items" mock
 * content and a clean "Empty cart" view — a local, unsaved display
 * toggle only. Nothing here is saved to any option or transient, no
 * request of any kind is made, and the real, customer-facing storefront
 * cart is never touched.
 *
 * ## How this avoids ever creating a second preview
 *
 * This script renders nothing of its own. It only adds or removes one
 * modifier class, `manage-cart-preview-card--empty-state`, on the
 * existing `#manage-cart-preview-card` element, and assets/css/side-cart-
 * admin.css does the rest: while that class is present, the mock cart
 * items list, the footer (totals/coupon/Checkout), and the item-count
 * badge are hidden and `#manage-cart-preview-empty` — the plain default
 * empty-cart view rendered by Side_Cart_Admin::render_preview() — is
 * shown in their place. The same CSS also hides any element a ManageCart
 * Pro live-preview integration (Custom Cart Note, Cart Recommendations)
 * has inserted into the panel, purely as a visual override.
 *
 * ## Why the modifier lives on the card, not the panel or stage
 *
 * assets/js/side-cart-preview.js fully rewrites `#manage-cart-preview-
 * panel`'s and `#manage-cart-preview-stage`'s `className` on every
 * settings-field-driven update pass (Cart layout, colors, border
 * radius, etc.). Putting the empty-state modifier on either of those
 * elements would mean a single settings edit, made while "Empty cart"
 * is selected, could silently wipe the modifier and snap the preview
 * back to "Cart items" without the switch itself changing. The outer
 * `#manage-cart-preview-card` wrapper is never touched by that script
 * (or by any ManageCart Pro preview script), so anchoring the modifier
 * there keeps the chosen preview state stable across every other field
 * edit, exactly as it should be.
 *
 * ## Why switching back to "Cart items" always restores correctly
 *
 * Because entering "Empty cart" only ever layers a CSS display
 * override on top of whatever each element's own `hidden` attribute
 * (or, for a Pro integration, its own enabled/disabled state) already
 * is — this script never reads, writes, or otherwise touches that
 * underlying state — removing the modifier class on switching back to
 * "Cart items" simply lets that already-correct state show through
 * again. There is nothing to reconcile and nothing that can end up
 * stale. The same reasoning is what keeps repeated switching from ever
 * duplicating a Cart Note or Cart Recommendations block: this script
 * never inserts, clones, or removes any node those scripts own.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var STATE_ITEMS = 'items';
		var STATE_EMPTY = 'empty';
		var EMPTY_STATE_CLASS = 'manage-cart-preview-card--empty-state';

		var card = document.getElementById( 'manage-cart-preview-card' );
		var itemsButton = document.getElementById( 'manage-cart-preview-state-btn-items' );
		var emptyButton = document.getElementById( 'manage-cart-preview-state-btn-empty' );
		var emptyView = document.getElementById( 'manage-cart-preview-empty' );

		// If the card, either switch button, or the empty-cart view is
		// missing (for example, a future page reusing part of this
		// markup without all of it), do nothing rather than risk a
		// script error on the page.
		if ( ! card || ! itemsButton || ! emptyButton || ! emptyView ) {
			return;
		}

		var currentState = STATE_ITEMS;

		/**
		 * Applies the given preview state: toggles the single CSS
		 * modifier class that drives everything else (see file docblock
		 * above), and updates the two switch buttons' pressed/active
		 * state to match.
		 *
		 * @param {string} state One of STATE_ITEMS or STATE_EMPTY.
		 * @return {void}
		 */
		function applyState( state ) {
			var isEmpty = ( STATE_EMPTY === state );

			card.classList.toggle( EMPTY_STATE_CLASS, isEmpty );

			itemsButton.classList.toggle( 'is-active', ! isEmpty );
			itemsButton.setAttribute( 'aria-pressed', isEmpty ? 'false' : 'true' );

			emptyButton.classList.toggle( 'is-active', isEmpty );
			emptyButton.setAttribute( 'aria-pressed', isEmpty ? 'true' : 'false' );

			// The mock empty-cart view is purely illustrative, exactly
			// like the rest of this preview (see aria-hidden="true" on
			// #manage-cart-preview-card itself); this only keeps
			// assistive tech from reading its content while it is not
			// the visible state.
			emptyView.setAttribute( 'aria-hidden', isEmpty ? 'false' : 'true' );

			currentState = state;
		}

		itemsButton.addEventListener( 'click', function () {
			if ( STATE_ITEMS === currentState ) {
				return;
			}

			applyState( STATE_ITEMS );
		} );

		emptyButton.addEventListener( 'click', function () {
			if ( STATE_EMPTY === currentState ) {
				return;
			}

			applyState( STATE_EMPTY );
		} );

		// Initial state is "Cart items", already reflected by the
		// server-rendered markup (the "Cart items" button already
		// carries "is-active"/aria-pressed="true", and the empty view
		// starts without the modifier class present), so applyState()
		// is deliberately NOT called here — doing so would be a no-op
		// at best and risks fighting the initial aria-hidden state for
		// no benefit.
	} );
}() );
