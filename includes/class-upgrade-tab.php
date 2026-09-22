<?php
/**
 * Free-edition-only "Upgrade to Pro" tab content.
 *
 * @package ManageCart
 */

namespace ManageCart;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Upgrade_Tab
 *
 * Renders the informational "Upgrade to Pro" panel shown as a third tab
 * on the shared Manage Cart admin screen, alongside the existing Cart
 * Display and Settings tabs (see App_Header and Admin::render_page()).
 * manage-cart.php loads this class only when the running build is not the
 * Premium one (`! manage_cart_fs()->is_premium()`), so nothing here ever
 * runs once a site is on the Premium build.
 *
 * This is purely descriptive, static admin UI: it makes no remote call,
 * stores no option, and shows no external promotional content on its
 * own. Every call to action links out to Freemius's own
 * `manage_cart_fs()->get_upgrade_url()`, so Freemius stays the single
 * source of truth for where an upgrade goes and for current plan
 * pricing — nothing here hardcodes a checkout URL, a product URL, or a
 * price. External content (the Freemius checkout/pricing page) is only
 * ever reached after the admin intentionally clicks one of these links;
 * nothing on this tab opens automatically, links out on page load, or
 * repeats itself as a nag.
 */
class Upgrade_Tab {

	/**
	 * Renders the inner content of the Upgrade to Pro panel: a heading
	 * and intro, the four real Pro features as cards (each tagged with the
	 * lowest plan that includes it), a Plans comparison
	 * (Starter / Business / Agency), and a short reassurance list of
	 * the Free features that remain fully available either way.
	 *
	 * @return void
	 */
	public static function render_panel_content() {
		$upgrade_url = manage_cart_fs()->get_upgrade_url();
		?>
		<div class="manage-cart-upgrade-wrap">
			<div class="manage-cart-upgrade-hero">
				<h1><?php esc_html_e( 'Unlock more with ManageCart Pro', 'manage-cart' ); ?></h1>
				<p class="manage-cart-upgrade-intro">
					<?php esc_html_e( 'Keep the complete Free cart experience. Pro adds advanced tools for improving cart engagement and conversion.', 'manage-cart' ); ?>
				</p>
				<a
					class="button button-primary manage-cart-upgrade-cta"
					href="<?php echo esc_url( $upgrade_url ); ?>"
				>
					<?php esc_html_e( 'View plans and pricing', 'manage-cart' ); ?>
				</a>
			</div>

			<ul class="manage-cart-upgrade-features">
				<li class="manage-cart-upgrade-feature-card">
					<h2><?php esc_html_e( 'Free Shipping Progress', 'manage-cart' ); ?></h2>
					<span class="manage-cart-upgrade-feature-plan"><?php esc_html_e( 'Starter and above', 'manage-cart' ); ?></span>
					<p><?php esc_html_e( 'Encourage customers to add more items by showing progress toward free shipping.', 'manage-cart' ); ?></p>
				</li>
				<li class="manage-cart-upgrade-feature-card">
					<h2><?php esc_html_e( 'Cart Recommendations', 'manage-cart' ); ?></h2>
					<span class="manage-cart-upgrade-feature-plan"><?php esc_html_e( 'Business and above', 'manage-cart' ); ?></span>
					<p><?php esc_html_e( 'Display relevant product recommendations inside the cart.', 'manage-cart' ); ?></p>
				</li>
				<li class="manage-cart-upgrade-feature-card">
					<h2><?php esc_html_e( 'Custom Cart Note', 'manage-cart' ); ?></h2>
					<span class="manage-cart-upgrade-feature-plan"><?php esc_html_e( 'Starter and above', 'manage-cart' ); ?></span>
					<p><?php esc_html_e( 'Add a helpful message for shoppers directly inside the cart.', 'manage-cart' ); ?></p>
				</li>
				<li class="manage-cart-upgrade-feature-card">
					<h2><?php esc_html_e( 'Custom Empty Cart Content', 'manage-cart' ); ?></h2>
					<span class="manage-cart-upgrade-feature-plan"><?php esc_html_e( 'Business and above', 'manage-cart' ); ?></span>
					<p><?php esc_html_e( 'Create a more useful, branded experience when the cart is empty.', 'manage-cart' ); ?></p>
				</li>
			</ul>

			<div class="manage-cart-upgrade-plans">
				<h2><?php esc_html_e( 'Plans', 'manage-cart' ); ?></h2>
				<ul class="manage-cart-upgrade-plans-list">
					<li class="manage-cart-upgrade-plan-card">
						<span class="manage-cart-upgrade-plan-name"><?php esc_html_e( 'Starter', 'manage-cart' ); ?></span>
						<span class="manage-cart-upgrade-plan-scope"><?php esc_html_e( '1 Site', 'manage-cart' ); ?></span>
						<ul class="manage-cart-upgrade-plan-includes">
							<li><?php esc_html_e( 'Free Shipping Progress', 'manage-cart' ); ?></li>
							<li><?php esc_html_e( 'Custom Cart Note', 'manage-cart' ); ?></li>
							<li><?php esc_html_e( 'Standard email support', 'manage-cart' ); ?></li>
						</ul>
					</li>
					<li class="manage-cart-upgrade-plan-card">
						<span class="manage-cart-upgrade-plan-name"><?php esc_html_e( 'Business', 'manage-cart' ); ?></span>
						<span class="manage-cart-upgrade-plan-scope"><?php esc_html_e( '3 Sites', 'manage-cart' ); ?></span>
						<ul class="manage-cart-upgrade-plan-includes">
							<li><?php esc_html_e( 'Everything in Starter', 'manage-cart' ); ?></li>
							<li><?php esc_html_e( 'Cart Recommendations', 'manage-cart' ); ?></li>
							<li><?php esc_html_e( 'Custom Empty Cart Content', 'manage-cart' ); ?></li>
							<li><?php esc_html_e( 'Priority email support', 'manage-cart' ); ?></li>
						</ul>
					</li>
					<li class="manage-cart-upgrade-plan-card">
						<span class="manage-cart-upgrade-plan-name"><?php esc_html_e( 'Agency', 'manage-cart' ); ?></span>
						<span class="manage-cart-upgrade-plan-scope"><?php esc_html_e( 'Unlimited Sites', 'manage-cart' ); ?></span>
						<ul class="manage-cart-upgrade-plan-includes">
							<li><?php esc_html_e( 'Everything in Business', 'manage-cart' ); ?></li>
							<li><?php esc_html_e( 'Priority email support', 'manage-cart' ); ?></li>
						</ul>
					</li>
				</ul>
				<p class="manage-cart-upgrade-plans-note">
					<?php esc_html_e( 'Each plan includes everything in the plans before it. Choose the plan that fits your store or client sites.', 'manage-cart' ); ?>
				</p>
			</div>

			<div class="manage-cart-upgrade-free-reassurance">
				<h2><?php esc_html_e( 'Your existing Free features remain available:', 'manage-cart' ); ?></h2>
				<ul class="manage-cart-upgrade-free-list">
					<li><?php esc_html_e( 'Side Cart', 'manage-cart' ); ?></li>
					<li><?php esc_html_e( 'Floating Cart', 'manage-cart' ); ?></li>
					<li><?php esc_html_e( 'Quantity controls and remove/undo', 'manage-cart' ); ?></li>
					<li><?php esc_html_e( 'Coupons', 'manage-cart' ); ?></li>
					<li><?php esc_html_e( 'Menu Cart Trigger', 'manage-cart' ); ?></li>
					<li><?php esc_html_e( 'Gutenberg Cart Trigger block', 'manage-cart' ); ?></li>
					<li><?php esc_html_e( 'Theme-compatible cart styling', 'manage-cart' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
}
