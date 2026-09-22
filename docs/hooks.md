# ManageCart Developer Hooks

ManageCart (free) fires a small set of `manage_cart_*` actions and filters
purely so a separate add-on can extend the cart panel later, without
forking core files. None of these hooks have a callback attached by the
free plugin itself, so their presence has no effect on the free plugin's
own output or behavior.

## Settings filters

| Hook | Type | Fired in |
|---|---|---|
| `manage_cart_settings` | filter | `Settings::get_settings()` |
| `manage_cart_side_cart_settings` | filter | `Side_Cart_Settings::get_settings()` |
| `manage_cart_floating_cart_settings` | filter | `Floating_Cart_Settings::get_settings()` |

Each receives the fully merged settings array (defaults + saved option
values) and must return an array. Use these to read or append additional
setting keys stored under a separate option, without modifying the free
plugin's own option handling.

## Display conditions

| Hook | Type | Fired in |
|---|---|---|
| `manage_cart_should_load` | filter | `Frontend::should_load()` |

Receives `true` (the free plugin's own requirements — WooCommerce active,
not admin/AJAX/REST, the global and Floating Cart "Enable" settings — have
already passed) and must return a boolean. A callback can only turn the
cart *off* for a given request (e.g. a page/device/role display
condition); it cannot force the cart on when a core requirement isn't met.

## Full-replacement filters

| Hook | Type | Fired in |
|---|---|---|
| `manage_cart_empty_state_content` | filter | `Cart_Renderer::render_empty_state()` |

Receives an empty string and must return either an empty string (render
the default empty-cart markup below) or a complete, already-escaped HTML
string to output in its place. Unlike the action hooks below, a callback
here can fully replace the default heading/description/button instead of
only appending after them. No callback is attached by the free plugin
itself.

## Frontend markup

| Hook | Type | Fired in | Typical use |
|---|---|---|---|
| `manage_cart_root_start` / `manage_cart_root_end` | action | `Frontend::render()` | Elements alongside the trigger/panel inside `#manage-cart-root`. |
| `manage_cart_after_trigger` | action | `Cart_Renderer::render_trigger()` | Menu-cart icon/badge variants. |
| `manage_cart_panel_body_start` / `manage_cart_panel_body_end` | action | `Cart_Renderer::render_panel()` | Free-shipping progress bar (start); cart upsells/cross-sells (end). |
| `manage_cart_footer_top` | action | `Cart_Renderer::render_footer()` | Promo messaging or a footer-based free-shipping bar. |
| `manage_cart_before_panel_actions` | action | `Cart_Renderer::render_footer()` | Custom cart notes field. |
| `manage_cart_after_cart_item` | action | `Cart_Renderer::render_cart_item()` | Per-item suggestions (e.g. "frequently bought together"). |
| `manage_cart_empty_state_after` | action | `Cart_Renderer::render_empty_state()` | Recommended/recently viewed products when the cart is empty. |
| `manage_cart_empty_state_content` | filter | `Cart_Renderer::render_empty_state()` | Full replacement of the empty-cart markup (see "Full-replacement filters" above). |

## Admin markup

| Hook | Type | Fired in | Typical use |
|---|---|---|---|
| `manage_cart_after_app_header` | action | `App_Header::render()` | Small contextual line beneath the ManageCart admin header (e.g. the Pro edition's support email). Fires on ManageCart's own admin screen only. No callback is attached by the free plugin itself. |

## JavaScript events

| Event | Direction | Dispatched/listened on | Fired/handled in |
|---|---|---|---|
| `managecart:cart-updated` | Dispatched by ManageCart | `document.body` (bubbles) | `assets/js/frontend-cart.js` |
| `managecart:request-cart-refresh` | Listened for by ManageCart | `document.body` (bubbling events also work) | `assets/js/frontend-cart.js` |
| `managecart:admin-side-cart-preview-updated` | Dispatched by ManageCart (admin only) | `document` (bubbles) | `assets/js/side-cart-preview.js` |

Dispatched once after ManageCart has successfully applied a WooCommerce
Store API cart response **and** completed its own DOM refresh of the
panel. Fires for add-to-cart, quantity updates, item removal, coupon
apply, coupon remove, and the full panel refresh. It does not fire when
a Store API request fails.

`event.detail.cart` is the raw, unmodified Store API cart response, so a
listener can read `totals`, `items`, and `coupons` directly rather than
re-fetching or scraping the DOM. All monetary values there are integers
in the currency's minor unit, alongside the response's own `currency_*`
fields.

Because the event fires *after* the DOM refresh, a listener that adds
its own markup to the cart panel can safely re-add it here: a full panel
refresh rebuilds the footer from scratch and discards anything a
`manage_cart_footer_top` callback rendered.

```js
document.addEventListener( 'managecart:cart-updated', function ( event ) {
	var cart = event.detail.cart;
	// cart.totals.total_items, cart.items, cart.coupons ...
} );
```

This event exists specifically so extensions do not need to wrap
`window.fetch`/`XMLHttpRequest` or run a `MutationObserver` over the
cart panel. Those approaches can interfere with WooCommerce itself,
payment gateways, themes, and analytics, and should not be used.

### `managecart:request-cart-refresh`

The reverse direction: dispatch this generic event (on `document.body`,
or any element whose event bubbles there) to ask ManageCart to refresh
the Side Cart panel from the real, current WooCommerce Store API cart —
typically after your own script has changed the cart through a Store
API request of its own (e.g. its own "Add to cart" button posting to
`/wc/store/v1/cart/add-item`).

```js
document.body.dispatchEvent( new CustomEvent( 'managecart:request-cart-refresh' ) );
```

ManageCart never trusts anything attached to this event — no
`event.detail` is read. On receiving it, ManageCart always re-fetches
the real cart itself (`GET /wc/store/v1/cart`) and applies that
response with its own normal full-panel refresh, the same one used
after a classic or block-based add-to-cart. That refresh in turn fires
`managecart:cart-updated` once it completes, so a listener only needs
to hook that one event to learn the outcome.

Duplicate requests fired in quick succession (e.g. from the same click,
or by more than one script) are throttled: at most one Store API
refresh request is in flight for this event at a time, with a short
cooldown afterward. A failed refresh fails silently and never surfaces
as a broken panel.

This event is also why an add-on wiring up its own "Add to cart"
control never needs to issue its own Store API cart-fetch, wrap
`window.fetch`/`XMLHttpRequest`, or run a `MutationObserver`: it posts
its own add-to-cart request, then simply dispatches this event and lets
ManageCart do the rest.

### `managecart:admin-side-cart-preview-updated`

Admin-only. Dispatched by `assets/js/side-cart-preview.js` on
`document` (bubbling) each time the **Manage Cart → Side Cart** live
preview panel has finished a render pass: once for the initial,
server-rendered state on `DOMContentLoaded`, and once at the end of
every later update triggered by a settings field change.

`event.detail.panel` is the current preview panel element
(`#manage-cart-preview-panel`), so a listener does not need to query
for it or guess at its markup.

```js
document.addEventListener( 'managecart:admin-side-cart-preview-updated', function ( event ) {
	var panel = event.detail.panel;
	// decorate the admin preview panel here
} );
```

This exists so an add-on can decorate the existing admin preview
instead of rendering a second preview of its own, and for the same
reason as the frontend events above: no `MutationObserver` over the
preview, and no polling. It is one-way and read-only — ManageCart
reads no `event.detail` back, and a listener cannot change what the
preview already rendered. The event fires as the last statement of the
pass, so the panel is always fully updated by the time a listener runs.

## Advanced styling filters

| Hook | Type | Fired in |
|---|---|---|
| `manage_cart_panel_appearance_style` | filter | `Cart_Renderer::get_appearance_style_string()` |
| `manage_cart_trigger_appearance_style` | filter | `Cart_Renderer::get_trigger_appearance_style_string()` |

Each receives the built `--manage-cart-*` CSS custom property declaration
string plus the relevant settings array, and must return a string of
additional/replacement declarations. The returned string is placed inside
an already-`esc_attr()`-escaped `style` attribute, so any callback must
return safe, pre-escaped CSS.

## Reusable rendering methods

`Cart_Renderer::render_trigger()`, `Cart_Renderer::render_panel()`, and
`Cart_Renderer::get_cart_item_count()` are `public static` and read live,
current WooCommerce cart data, so a future shortcode or menu-cart add-on
can call them directly instead of duplicating markup.
