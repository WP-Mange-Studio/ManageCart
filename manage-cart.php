<?php

/**
 * Plugin Name:       ManageCart – Side Cart & Floating Cart for WooCommerce
 * Plugin URI:        https://wpmanagestudio.com/manage-cart/
 * Description:       Side drawer, centered popup, and mobile bottom-sheet cart plus a floating cart button for WooCommerce. Optional paid plans add Free Shipping Progress, Cart Recommendations, a Custom Cart Note, and Custom Empty Cart Content. Requires WooCommerce.
 * Version:           0.1.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            WPManageStudio
 * Author URI:        https://wpmanagestudio.com/
 * License:            GPL-2.0-or-later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:        manage-cart
 * Domain Path:        /languages
 * Requires Plugins:   woocommerce
 *
 * Freemius single-codebase packaging: this is the canonical source. Freemius
 * builds the Free edition by omitting the premium-only folder.
 *
 *
 * @package ManageCart
 */
// Prevent direct file access.
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
if ( function_exists( 'manage_cart_fs' ) ) {
    // Free <-> Premium switch: the other generated edition (folder
    // `manage-cart` vs `manage-cart-premium`) already initialized the SDK in
    // this request. Only tell the SDK about this edition's main file; declare
    // nothing else, so both editions can safely be present during an upgrade.
    // This is the official Freemius snippet for one codebase: `true` here and
    // `'is_premium' => true` below are the canonical (Premium) values, which
    // Freemius flips to `false` when it generates the Free build.
    manage_cart_fs()->set_basename( false, __FILE__ );
} else {
    /**
     * DO NOT REMOVE THIS IF, IT IS ESSENTIAL FOR THE
     * `function_exists` CALL ABOVE TO PROPERLY WORK.
     */
    if ( !function_exists( 'manage_cart_fs' ) ) {
        // Create a helper function for easy SDK access.
        function manage_cart_fs() {
            global $manage_cart_fs;
            if ( !isset( $manage_cart_fs ) ) {
                // Include Freemius SDK.
                require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';
                $manage_cart_fs = fs_dynamic_init( array(
                    'id'               => '39742',
                    'slug'             => 'manage-cart',
                    'premium_slug'     => 'manage-cart-premium',
                    'type'             => 'plugin',
                    'public_key'       => 'pk_c4a3733951c5fbdf2fc56e0d51b69',
                    'is_premium'       => false,
                    'premium_suffix'   => '(Pro)',
                    'has_addons'       => false,
                    'has_paid_plans'   => true,
                    'is_org_compliant' => true,
                    'menu'             => array(
                        'slug'       => 'manage-cart-settings',
                        'first-path' => 'admin.php?page=manage-cart-settings',
                        'account'    => false,
                        'contact'    => true,
                        'support'    => true,
                        'pricing'    => true,
                    ),
                    'is_live'          => true,
                ) );
            }
            return $manage_cart_fs;
        }

        // Init Freemius.
        manage_cart_fs();
        // Signal that SDK was initiated.
        do_action( 'manage_cart_fs_loaded' );
    }
    // ... ManageCart main file logic ...
    // Core plugin constants.
    if ( !defined( 'MANAGE_CART_VERSION' ) ) {
        define( 'MANAGE_CART_VERSION', '0.1.1' );
    }
    if ( !defined( 'MANAGE_CART_PLUGIN_FILE' ) ) {
        define( 'MANAGE_CART_PLUGIN_FILE', __FILE__ );
    }
    if ( !defined( 'MANAGE_CART_PLUGIN_DIR' ) ) {
        define( 'MANAGE_CART_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
    }
    if ( !defined( 'MANAGE_CART_PLUGIN_URL' ) ) {
        define( 'MANAGE_CART_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
    }
    if ( !defined( 'MANAGE_CART_PLUGIN_BASENAME' ) ) {
        define( 'MANAGE_CART_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
    }
    /**
     * Absolute path to this plugin's blocks/ directory (trailing slash),
     * where every native Gutenberg block this plugin registers keeps its
     * own `{block-slug}/block.json`. Defined once, centrally, right
     * alongside MANAGE_CART_PLUGIN_DIR it's derived from, rather than each
     * block-registering class concatenating that path itself — so there is
     * exactly one place a block's on-disk location can ever be wrong. See
     * Menu_Cart_Trigger::register_block() in
     * includes/class-menu-cart-trigger.php for the block this currently
     * backs (blocks/cart-trigger/).
     */
    if ( !defined( 'MANAGE_CART_BLOCKS_DIR' ) ) {
        define( 'MANAGE_CART_BLOCKS_DIR', MANAGE_CART_PLUGIN_DIR . 'blocks/' );
    }
    if ( !defined( 'MANAGE_CART_SETTINGS_SLUG' ) ) {
        define( 'MANAGE_CART_SETTINGS_SLUG', 'manage-cart-settings' );
    }
    if ( !defined( 'MANAGE_CART_SIDE_CART_SLUG' ) ) {
        define( 'MANAGE_CART_SIDE_CART_SLUG', 'manage-cart-side-cart' );
    }
    if ( !defined( 'MANAGE_CART_FLOATING_CART_SLUG' ) ) {
        define( 'MANAGE_CART_FLOATING_CART_SLUG', 'manage-cart-floating-cart' );
    }
    // Explicit class loading (no autoloader by design).
    require_once MANAGE_CART_PLUGIN_DIR . 'includes/class-dependency-checker.php';
    require_once MANAGE_CART_PLUGIN_DIR . 'includes/class-compatibility.php';
    require_once MANAGE_CART_PLUGIN_DIR . 'includes/class-assets.php';
    require_once MANAGE_CART_PLUGIN_DIR . 'includes/class-settings.php';
    require_once MANAGE_CART_PLUGIN_DIR . 'includes/class-app-header.php';
    require_once MANAGE_CART_PLUGIN_DIR . 'includes/class-admin.php';
    require_once MANAGE_CART_PLUGIN_DIR . 'includes/class-side-cart-settings.php';
    require_once MANAGE_CART_PLUGIN_DIR . 'includes/class-floating-cart-settings.php';
    require_once MANAGE_CART_PLUGIN_DIR . 'includes/class-side-cart-admin.php';
    require_once MANAGE_CART_PLUGIN_DIR . 'includes/class-cart-renderer.php';
    require_once MANAGE_CART_PLUGIN_DIR . 'includes/class-frontend.php';
    require_once MANAGE_CART_PLUGIN_DIR . 'includes/class-single-product-auto-open.php';
    require_once MANAGE_CART_PLUGIN_DIR . 'includes/class-menu-cart-trigger.php';
    require_once MANAGE_CART_PLUGIN_DIR . 'includes/class-plugin.php';
    require_once MANAGE_CART_PLUGIN_DIR . 'includes/class-activator.php';
    require_once MANAGE_CART_PLUGIN_DIR . 'includes/class-deactivator.php';
    // Free build only: the informational "Upgrade to Pro" tab. It is loaded
    // only when the running build is not the Premium one, which keeps the tab
    // off in Premium at runtime.
    if ( !manage_cart_fs()->is_premium() && file_exists( MANAGE_CART_PLUGIN_DIR . 'includes/class-upgrade-tab.php' ) ) {
        require_once MANAGE_CART_PLUGIN_DIR . 'includes/class-upgrade-tab.php';
    }
    /**
     * Activation hook callback.
     *
     * @return void
     */
    function manage_cart_activate() {
        \ManageCart\Activator::activate();
    }

    register_activation_hook( __FILE__, 'manage_cart_activate' );
    /**
     * Deactivation hook callback.
     *
     * @return void
     */
    function manage_cart_deactivate() {
        \ManageCart\Deactivator::deactivate();
    }

    register_deactivation_hook( __FILE__, 'manage_cart_deactivate' );
    // Free <-> Premium switch: when the other edition was already loaded during activation,
    // WordPress does not fire this file's activation hook, so the SDK's code-type-change
    // actions keep the stored version number in step (same idempotent update as activation).
    manage_cart_fs()->add_action( 'after_free_version_reactivation', 'manage_cart_activate' );
    /**
     * Uninstall cleanup, run through the Freemius SDK's `after_uninstall`
     * action (no root uninstall file is shipped).
     *
     * By default ManageCart does NOT delete any of its stored data on
     * uninstall. Data is only removed when the site owner has explicitly
     * opted in via the `manage_cart_delete_data_on_uninstall` option.
     *
     * ManageCart is generated as two editions that share the same stored
     * options: `manage-cart` (Free) and `manage-cart-premium` (Pro). During a
     * Free-to-Pro upgrade the Free edition is deactivated and can then be
     * deleted while the Pro edition keeps using that data, so deleting either
     * edition must never remove data while the other edition is still
     * installed. The edition being uninstalled is identified from this main
     * plugin file's own basename.
     *
     * @return void
     */
    function manage_cart_after_uninstall() {
        $current_edition = plugin_basename( __FILE__ );
        foreach ( array('manage-cart/manage-cart.php', 'manage-cart-premium/manage-cart.php') as $manage_cart_edition ) {
            if ( $current_edition !== $manage_cart_edition && file_exists( WP_PLUGIN_DIR . '/' . $manage_cart_edition ) ) {
                return;
            }
        }
        /**
         * Only remove data if the site owner has explicitly opted in.
         * This option itself is intentionally left in place unless data deletion
         * is confirmed, so we check it first and then decide what to clean up.
         */
        $manage_cart_should_delete_data = get_option( 'manage_cart_delete_data_on_uninstall', false );
        if ( !$manage_cart_should_delete_data ) {
            return;
        }
        // Remove known Manage Cart options for the current site.
        delete_option( 'manage_cart_version' );
        delete_option( 'manage_cart_delete_data_on_uninstall' );
        delete_option( 'manage_cart_settings' );
        delete_option( 'manage_cart_side_cart_settings' );
        delete_option( 'manage_cart_floating_cart_settings' );
        delete_option( 'manage_cart_capabilities_schema' );
        // Dedicated network-wide (multisite) cleanup will be implemented in a future release.
    }

    manage_cart_fs()->add_action( 'after_uninstall', 'manage_cart_after_uninstall' );
    /**
     * Boots the plugin once all plugins have loaded and dependencies are confirmed.
     *
     * @return void
     */
    function manage_cart_boot() {
        $dependency_checker = new \ManageCart\Dependency_Checker();
        if ( !$dependency_checker->are_requirements_met() ) {
            $dependency_checker->register_admin_notices();
            return;
        }
        \ManageCart\Plugin::get_instance()->run();
    }

    add_action( 'plugins_loaded', 'manage_cart_boot' );
    /**
     * Declares compatibility with WooCommerce HPOS (Custom Order Tables) and
     * Cart/Checkout Blocks. Runs during before_woocommerce_init as required by
     * the WooCommerce FeaturesUtil API.
     *
     * @return void
     */
    function manage_cart_declare_woocommerce_compatibility() {
        \ManageCart\Compatibility::declare_compatibility();
    }

    add_action( 'before_woocommerce_init', 'manage_cart_declare_woocommerce_compatibility' );
}