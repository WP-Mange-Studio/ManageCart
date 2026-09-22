<?php
/**
 * Settings API registration and option management.
 *
 * @package ManageCart
 */

namespace ManageCart;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 *
 * Registers the Manage Cart settings with the WordPress Settings API,
 * defines defaults, and handles sanitization. All Phase 2A settings are
 * stored in a single option: `manage_cart_settings`.
 */
class Settings {

	/**
	 * Option name used to store all Manage Cart settings.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'manage_cart_settings';

	/**
	 * Settings API option group.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'manage_cart_settings_group';

	/**
	 * Settings page slug (also used as the Settings API "page" argument).
	 *
	 * @var string
	 */
	const PAGE_SLUG = MANAGE_CART_SETTINGS_SLUG;

	/**
	 * Legacy standalone option used by manage_cart_after_uninstall() as the single source of
	 * truth for whether data should be deleted on uninstall. Kept in sync
	 * with the `delete_data_on_uninstall` value inside OPTION_NAME.
	 *
	 * @var string
	 */
	const LEGACY_DELETE_ON_UNINSTALL_OPTION = 'manage_cart_delete_data_on_uninstall';

	/**
	 * Returns the default settings values.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'enabled'                  => true,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * Retrieves the current settings, merged over the defaults so missing
	 * keys are always present.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION_NAME, self::get_defaults() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = wp_parse_args( $stored, self::get_defaults() );

		/**
		 * Filters the merged Manage Cart global settings.
		 *
		 * Reserved for a future ManageCart Pro add-on to read/append its
		 * own setting keys (stored under its own option) alongside the
		 * free plugin's, without forking this method. Free ManageCart
		 * applies no filter callback here itself.
		 *
		 * @param array $settings The merged global settings.
		 */
		return apply_filters( 'manage_cart_settings', $settings );
	}

	/**
	 * Registers the setting, section, and fields with the Settings API.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::get_defaults(),
			)
		);

		add_settings_section(
			'manage_cart_main_section',
			'',
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'manage_cart_enabled',
			__( 'Enable ManageCart', 'manage-cart' ),
			array( __CLASS__, 'render_enabled_field' ),
			self::PAGE_SLUG,
			'manage_cart_main_section'
		);

		add_settings_field(
			'manage_cart_delete_data_on_uninstall',
			__( 'Delete data on uninstall', 'manage-cart' ),
			array( __CLASS__, 'render_delete_data_field' ),
			self::PAGE_SLUG,
			'manage_cart_main_section'
		);
	}

	/**
	 * Sanitizes submitted settings before they are stored, and keeps the
	 * legacy uninstall option synchronized.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$output = array(
			'enabled'                  => ! empty( $input['enabled'] ),
			'delete_data_on_uninstall' => ! empty( $input['delete_data_on_uninstall'] ),
		);

		self::sync_legacy_uninstall_option( $output['delete_data_on_uninstall'] );

		add_settings_error(
			self::OPTION_NAME,
			'manage_cart_settings_saved',
			__( 'ManageCart settings saved.', 'manage-cart' ),
			'success'
		);

		return $output;
	}

	/**
	 * Resets settings to their defaults and returns the defaults used.
	 *
	 * @return array The default settings that were saved.
	 */
	public static function reset_to_defaults() {
		$defaults = self::get_defaults();

		update_option( self::OPTION_NAME, $defaults );
		self::sync_legacy_uninstall_option( $defaults['delete_data_on_uninstall'] );

		return $defaults;
	}

	/**
	 * Keeps the standalone `manage_cart_delete_data_on_uninstall` option
	 * (read directly by manage_cart_after_uninstall(), which runs through
	 * Freemius's `after_uninstall` action) synchronized with the settings array.
	 *
	 * @param bool $should_delete Whether data should be deleted on uninstall.
	 * @return void
	 */
	public static function sync_legacy_uninstall_option( $should_delete ) {
		update_option( self::LEGACY_DELETE_ON_UNINSTALL_OPTION, (bool) $should_delete );
	}

	/**
	 * Renders the "Enable Manage Cart" checkbox field.
	 *
	 * @return void
	 */
	public static function render_enabled_field() {
		$settings = self::get_settings();
		?>
		<label class="manage-cart-toggle">
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled]"
				value="1"
				<?php checked( $settings['enabled'] ); ?>
			/>
			<span><?php esc_html_e( 'Enable ManageCart functionality on this site.', 'manage-cart' ); ?></span>
		</label>
		<?php
	}

	/**
	 * Renders the "Delete data on uninstall" checkbox field, plus a warning.
	 *
	 * @return void
	 */
	public static function render_delete_data_field() {
		$settings = self::get_settings();
		?>
		<label class="manage-cart-toggle">
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[delete_data_on_uninstall]"
				value="1"
				<?php checked( $settings['delete_data_on_uninstall'] ); ?>
			/>
			<span><?php esc_html_e( 'Delete all ManageCart data when the plugin is uninstalled.', 'manage-cart' ); ?></span>
		</label>
		<p class="manage-cart-warning">
			<?php esc_html_e( 'Warning: if enabled, uninstalling ManageCart will permanently delete its stored settings. This cannot be undone.', 'manage-cart' ); ?>
		</p>
		<?php
	}
}
