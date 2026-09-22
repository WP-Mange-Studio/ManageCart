=== ManageCart – Side Cart & Floating Cart for WooCommerce ===
Contributors: wpmanagestudio, freemius
Tags: woocommerce, cart, side cart, mini cart, floating cart
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Foundation plugin for a WooCommerce cart experience: side drawer cart, centered popup cart, mobile bottom-sheet cart, and floating cart button.

== Description ==

ManageCart is a free WooCommerce cart experience plugin that helps customers review and manage their cart without leaving the current page. It provides a customizable cart panel and optional floating cart button, built directly on the WooCommerce Store API.

= Benefits of WooCommerce Side Cart For Your Store =

A smooth cart experience helps customers stay focused on shopping instead of moving between product and cart pages.

* Keep customers on the current page while they review their cart
* Make quantity updates and item removal faster
* Offer a mobile-friendly bottom-sheet cart experience
* Make coupon access simple without cluttering the cart
* Highlight sale savings and low-stock information
* Give shoppers a clear path to checkout

= Why Store Owners Choose ManageCart? =

ManageCart is built for WooCommerce stores that want a modern cart experience with a simple setup process.

* Works directly with WooCommerce
* Uses the WooCommerce Store API for cart updates
* Provides drawer, popup, and mobile bottom-sheet layouts
* Includes a floating cart button with an optional item count
* Lets store owners customize colors, buttons, sizing, and border radius
* Provides separate Cart Display and Settings screens for clear configuration

= Powerful ManageCart Features =

* Side Cart layouts: left drawer, right drawer, or centered popup
* Mobile bottom-sheet cart presentation
* Optional auto-open cart after adding a product
* Custom cart heading, product images, variation details, quantity controls, and remove buttons
* Live quantity updates and item removal without a page reload
* Coupon apply and remove support
* Sale-price savings badge and optional low-stock badge
* Floating cart button with position, offset, size, color, badge, and optional drag-to-reposition
* Appearance controls for the Side Cart and Floating Cart
* Live admin previews before saving changes
* Nonce-protected and capability-checked settings actions

= Optional ManageCart Pro plans =

Everything above is included in the free plugin, with no registration or payment required. Optional paid ManageCart Pro plans, sold through Freemius, add four extra cart features. The paid Pro version is a separate download from your Freemius account; the free plugin itself never unlocks these features and contains none of their code.

* Starter (1 site): Free Shipping Progress, Custom Cart Note, standard email support
* Business (3 sites): everything in Starter, plus Cart Recommendations and Custom Empty Cart Content, priority email support
* Agency (Unlimited Sites): everything in Business, priority email support

= External services =

ManageCart includes the Freemius SDK, which connects the plugin to Freemius (https://freemius.com), a third-party service used for optional account access, support contact, and upgrades to the paid ManageCart Pro edition.

* Nothing is sent to Freemius until a site administrator explicitly opts in on the Freemius screen shown in wp-admin. You can skip it and keep using ManageCart.
* If you opt in, Freemius receives basic account and site information (such as the administrator's name and email address, the site URL, the WordPress, PHP, and plugin versions, and an overview of installed plugins and themes) so it can provide account, support, licensing, and update features. Using the Account, Contact Us, or Upgrade screens, or activating a license, also communicates with Freemius.
* Freemius Terms of Service: https://freemius.com/terms/
* Freemius Privacy Policy: https://freemius.com/privacy/

= Requirements =

* WordPress 6.5 or higher
* PHP 7.4 or higher
* WooCommerce, installed and active

== Installation ==

1. Make sure WooCommerce is installed and active on your site.
2. Upload the `manage-cart` folder to the `/wp-content/plugins/` directory, or install the plugin zip file through the Plugins screen in WordPress.
3. Activate ManageCart through the "Plugins" screen in WordPress.
4. If WooCommerce is not active, ManageCart will show an admin notice and will not run.

== Frequently Asked Questions ==

= How To Install ManageCart? =

Install and activate WooCommerce first. Then upload or install ManageCart from the WordPress Plugins screen and activate it. Open ManageCart → Settings to enable the plugin, then use Cart Display to configure Side Cart and Floating Cart options.

= Do I need a license or an account? =

No. The free plugin works fully without registering, opting in, or paying. A license is only needed if you install the paid ManageCart Pro version to use Pro features; which features are available depends on your plan (Starter, Business, or Agency), and a feature your plan does not include stays off.

= Is ManageCart a free plugin? =

Yes. ManageCart 0.1.1 is a free WooCommerce plugin released under the GPL-2.0-or-later license.

= How do I add ManageCart to my WordPress site? =

Install and activate WooCommerce, then install and activate ManageCart. Open ManageCart → Cart Display, configure your preferred Side Cart or Floating Cart settings, and click Save Changes. The cart experience will then appear on your store's frontend when ManageCart is enabled.

= Does this plugin work without WooCommerce? =

No. ManageCart requires WooCommerce to be installed and active. If WooCommerce is missing or inactive, the plugin displays an admin notice and does not load any further functionality.

= Does this plugin add a cart UI to my site right now? =

Yes. ManageCart renders a fully functional cart panel (drawer, popup, or mobile bottom-sheet, depending on your settings) and, if enabled, a floating cart button — both connected to WooCommerce's Store API for quantity changes, item removal, and coupons. Configure them under "ManageCart → Cart Display", in its "Side Cart" and "Floating Cart" sections.

= Will activating this plugin change any of my data? =

No. Activation only stores the plugin's version number in the WordPress options table. No cart configuration or customer data is created or modified. Settings are stored only once you visit the ManageCart settings page and save or reset.

= Who can access the ManageCart settings page? =

Only users with the `manage_woocommerce` capability (typically Shop Managers and Administrators) can view or change ManageCart settings.

= Does uninstalling this plugin delete my data? =

By default, no. ManageCart only removes its own options if "Delete data on uninstall" is enabled on the ManageCart settings page (or the underlying `manage_cart_delete_data_on_uninstall` option is otherwise set to a truthy value). Otherwise, uninstalling the plugin leaves its stored data in place.

= What does Reset Settings do? =

Reset Settings restores "Enable ManageCart" and "Delete data on uninstall" to their default values (enabled, and not deleted on uninstall, respectively). It requires the `manage_woocommerce` capability and a valid nonce, and shows a success notice once complete.

= What is the Side Cart section? =

"Cart Display → Side Cart" is where you configure the side cart's layout, position, width, auto-open behavior, heading, item display, and appearance. It is stored in its own option (`manage_cart_side_cart_settings`) and does not affect the global ManageCart settings. These settings are read directly by the real frontend cart; the section also includes a live admin preview so you can check your choices before saving.

= Does the Side Cart preview load real cart data? =

No. The preview on the Cart Display screen is static, illustrative admin markup built from your saved settings. It does not query WooCommerce, does not use the Store API, and does not run any JavaScript.

= Who can access the Cart Display screen? =

The same as the global settings page: only users with the `manage_woocommerce` capability (typically Shop Managers and Administrators).

= What does the ManageCart sidebar menu contain? =

Clicking the top-level "ManageCart" item opens the Cart Display screen with Side Cart active. Its submenu lists Cart Display, Contact Us, Support Forum, and Upgrade. Settings and Cart Display are also reachable using the tabs in the app header at the top of the screen, and every screen enforces the same `manage_woocommerce` permission check.

= Is the Side Cart preview connected to my real cart? =

No. The preview on the Cart Display screen's Side Cart section is built entirely from static, illustrative admin data. It updates instantly as you edit the fields on that page so you can see the effect of your choices, but it never queries WooCommerce, never uses the Store API, and never runs on the frontend.

== Changelog ==

= 0.1.1 =
* New: an edition pill ("Free" or "Pro") shown next to the version number in the ManageCart admin header, so it's clear at a glance which edition is active. The Free edition always shows "Free"; "Pro" appears only in the separate ManageCart Pro edition while its license or trial is active. Admin-only — this never appears on the storefront, adds no new option, and the pill itself makes no license or remote check.
* Fixed: the Floating Cart's saved button background color, icon color, and badge colors could be overridden by the active theme — most noticeably Astra, whose own default button color could show through instead of the color chosen in Appearance → Cart Display → Floating Cart. Twenty Twenty-Five and Hello Elementor were also affected in some color combinations. The Floating Cart's own selectors are now specific enough to reliably win against these themes' base button styles, using the same CSS custom properties as before — no visual change for a theme that already rendered these colors correctly, and the separate Menu Cart Trigger's default appearance is unaffected.
* New: a public `[manage_cart_trigger]` shortcode — a compact, accessible cart icon/button for headers, nav menus, Gutenberg, Elementor, and templates. It shows the current cart item count as a badge and opens the existing Side Cart panel; it does not add a second drawer, overlay, Store API route, AJAX handler, or cart state of its own. Follows the same "Enable Manage Cart" / "Enable Floating Cart" display rules as the floating cart button, and returns nothing where that button already wouldn't load. The Cart and Checkout pages themselves are unchanged.
* Fixed: on Appearance → Menus (classic themes such as Hello Elementor), clicking "Add to Menu" on the "ManageCart Cart Trigger" meta box did nothing. It now adds the item to Menu Structure immediately, the same one click away as any other meta box on that screen, where it can be renamed and reordered before Save Menu. No new REST route, AJAX handler, or external dependency was added; the box is now wired up entirely by WordPress's own existing nav-menu admin script.
* New: a secondary "Continue Shopping" button next to "View Cart" in the cart footer, linking to your real WooCommerce Shop page. Automatically hidden if no Shop page is configured. Checkout remains the primary button, and the buttons stack gracefully on narrow screens.
* New: a polished, original ManageCart basket icon in the floating cart button, the Side Cart panel header, and the empty-cart state, replacing the previous generic cart glyph.
* New: a "Product savings" total savings row, shown in the Side Cart footer above the Subtotal whenever the cart's combined sale discount (regular price minus sale price, per line, times quantity) is greater than zero. Uses standard WooCommerce currency formatting and stays in sync after quantity changes, item removal, coupon apply/remove, and add-to-cart, without any new setting to configure.
* New: a "Coupon discount" row in the Side Cart footer, shown below Subtotal only when a coupon is applied — the combined discount across every applied coupon, using WooCommerce's own `get_coupon_discount_amount()` for each coupon and respecting the store's "display prices including tax" setting the same way Subtotal already does.
* New: a prominent "Total" row at the bottom of the Side Cart footer, using WooCommerce's own real final cart total after discounts, tax, fees, and calculated shipping. The footer now reads Product savings, Subtotal, Coupon discount, Total, in that order, and stays correct after quantity changes, item removal, coupon apply/remove, and add-to-cart, without any new setting to configure.
* Improved: the per-item remove control now uses a clearer, more compact trash icon with an accessible "Remove {product name}" label, a matching native tooltip, and the same violet-palette default state with a red-tinted hover/focus state as before.
* New: a small set of developer actions and filters (e.g. `manage_cart_panel_body_start`/`_end`, `manage_cart_before_panel_actions`, `manage_cart_after_cart_item`, `manage_cart_should_load`, and settings/appearance-style filters) for future extensions. Nothing hooks into them by default, so this adds no visible change on its own.
* Fixed: the per-item "Save X%" sale badge could show a double percent sign (e.g. "Save 26%%") after a coupon apply/remove, quantity update, add-to-cart refresh, or item removal, because the JavaScript-rendered badge didn't treat a translatable `%%` as an escaped literal percent the way PHP's `printf()` does. It now always matches the server-rendered badge exactly, in every language.
* Fixed: the applied-coupon code / amount / Remove button list in the Side Cart footer could show a stale discount amount for a percentage coupon after a quantity change or item removal, even though the cart-wide Coupon discount and Total rows below it already updated correctly. It now refreshes from the same Store API response as those rows, without disturbing the "Have a coupon?" toggle's open/closed state.
* New: optional paid ManageCart Pro plans (Starter, Business, Agency) with an "Upgrade to Pro" tab and Go Pro link in the free plugin. Pro features live only in the separate Pro download; the free plugin, its stored data, and the cart itself are unchanged.
* New: the Freemius SDK, for optional account access, support contact, and upgrades to the paid ManageCart Pro edition. Nothing is sent to Freemius until an administrator opts in. The cart, checkout, existing endpoints, and your saved settings are unchanged.
* All cart item prices, Store API endpoints, and checkout behavior are otherwise unchanged.

= 0.1.0 =
* Initial release: a WooCommerce cart experience plugin adding a customizable Side Cart panel (drawer, centered popup, or mobile bottom-sheet) and an optional Floating Cart button, both built on the WooCommerce Store API for live quantity changes, item removal, and coupon apply/remove with no page reload.
* Side Cart: configurable desktop width, auto-open after add-to-cart, customizable heading, independently toggleable per-item image/variation/quantity/remove controls, regular/sale price display with a "Save X%" badge, an optional low-stock badge with a configurable threshold, and Appearance settings (colors, sizing, border radius) applied live to the real frontend cart.
* Floating Cart button: enable/disable, corner position (top/bottom, left/right) with pixel offsets, an item count badge with an option to hide the button when the cart is empty, optional customer drag-to-reposition (stored per-browser), and its own Appearance settings.
* Admin: a single top-level "ManageCart" icon in the wp-admin sidebar (`manage_woocommerce` capability, no submenu flyout) opening a shared screen with "Settings" and "Cart Display" tabs, a live unsaved admin preview, nonce-protected Save/Reset actions on every settings screen, and a "Settings" link next to Deactivate on the Plugins screen.
* Foundation: WooCommerce HPOS and Cart/Checkout Blocks compatibility declarations, PHP/WooCommerce dependency checks with admin notices, and opt-in data cleanup on uninstall.

== Upgrade Notice ==

= 0.1.1 =
Adds a "Continue Shopping" footer button, a new original basket icon, and Product savings/Coupon discount/Total rows in the cart footer, plus a clearer remove icon. No settings changes required.

= 0.1.0 =
Initial release.

== License ==

ManageCart is licensed under the GPL-2.0-or-later. See https://www.gnu.org/licenses/gpl-2.0.html for the full license text, or the bundled LICENSE file.

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License, version 2, as published by the Free Software Foundation.