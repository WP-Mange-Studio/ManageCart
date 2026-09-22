<?php
/**
 * Dependency and environment requirement checks.
 *
 * @package ManageCart
 */

namespace ManageCart;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Dependency_Checker
 *
 * Verifies that the current environment (PHP version and required plugins)
 * meets Manage Cart's minimum requirements before the plugin boots.
 */
class Dependency_Checker {

	/**
	 * Minimum required PHP version.
	 *
	 * @var string
	 */
	const MINIMUM_PHP_VERSION = '7.4';

	/**
	 * Collected human-readable error messages for failed checks.
	 *
	 * @var array
	 */
	protected $errors = array();

	/**
	 * Determines whether all Manage Cart requirements are met.
	 *
	 * @return bool True if requirements are met, false otherwise.
	 */
	public function are_requirements_met() {
		$this->errors = array();

		if ( ! $this->is_php_version_sufficient() ) {
			$this->errors[] = sprintf(
				/* translators: 1: Required PHP version, 2: Current PHP version. */
				esc_html__( 'ManageCart requires PHP %1$s or higher. Your site is currently running PHP %2$s.', 'manage-cart' ),
				self::MINIMUM_PHP_VERSION,
				PHP_VERSION
			);
		}

		if ( ! $this->is_woocommerce_active() ) {
			$this->errors[] = esc_html__( 'ManageCart requires WooCommerce to be installed and active.', 'manage-cart' );
		}

		return empty( $this->errors );
	}

	/**
	 * Checks whether the current PHP version meets the minimum requirement.
	 *
	 * @return bool
	 */
	protected function is_php_version_sufficient() {
		return version_compare( PHP_VERSION, self::MINIMUM_PHP_VERSION, '>=' );
	}

	/**
	 * Checks whether WooCommerce is installed and active.
	 *
	 * @return bool
	 */
	protected function is_woocommerce_active() {
		if ( class_exists( 'WooCommerce' ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( 'woocommerce/woocommerce.php' );
	}

	/**
	 * Returns the collected requirement error messages.
	 *
	 * @return array
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * Registers an admin notice hook that outputs any collected errors.
	 *
	 * @return void
	 */
	public function register_admin_notices() {
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
	}

	/**
	 * Renders admin notices for any unmet requirements.
	 *
	 * @return void
	 */
	public function render_admin_notices() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( empty( $this->errors ) ) {
			return;
		}

		foreach ( $this->errors as $error ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $error )
			);
		}
	}
}
