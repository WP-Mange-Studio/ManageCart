<?php
/**
 * Side Cart settings API registration and option management (Phase 2B).
 *
 * @package ManageCart
 */

namespace ManageCart;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Side_Cart_Settings
 *
 * Registers the Phase 2B "Side Cart" settings architecture with the
 * WordPress Settings API. This is a purely administrative, data-only layer:
 * it defines the shape and defaults of the side cart configuration, and
 * sanitizes/whitelists everything submitted from the Side Cart settings
 * screen. It stores its data in its own option, `manage_cart_side_cart_settings`,
 * completely separate from the Phase 2A `manage_cart_settings` option.
 *
 * No frontend cart, popup, floating button, Store API integration, or React
 * code reads or reacts to these settings yet. That remains reserved for a
 * later phase.
 *
 * Phase 3E-2 moves the "Allow customers to move floating cart button"
 * field out of this option entirely, into Floating_Cart_Settings (Floating
 * Cart → General), where it belongs alongside the rest of the floating
 * trigger's settings. Floating_Cart_Settings::get_settings() migrates any
 * value already saved here the first time it is read, so nothing here
 * needs to do anything further with it; this class no longer defines,
 * registers, sanitizes, or renders that field.
 *
 * Phase 3F-6: this option is no longer saved through the WordPress
 * Settings API/options.php. It is saved exclusively by
 * Side_Cart_Admin::handle_side_cart_save(), a dedicated admin-post handler
 * that verifies the nonce and `manage_woocommerce` capability itself, calls
 * sanitize() below directly, and updates this option with update_option().
 * register() still registers the settings sections/fields used purely to
 * render the "Side Cart → General" form; it no longer calls
 * register_setting(). sanitize() no longer calls add_settings_error() —
 * the caller is responsible for the saved notice (a current-user
 * transient), matching Floating_Cart_Settings::sanitize().
 *
 * Phase 3G-2A adds a "Side Cart → Appearance" field group (panel/header
 * background, header/body text, checkout button colors, and a panel border
 * radius) to this same option and option shape. These fields are rendered
 * under a separate Settings API page slug (self::APPEARANCE_PAGE_SLUG) so
 * do_settings_sections() can draw the Appearance tab's fields without also
 * drawing them on the General tab, but they are still part of the single
 * GENERAL_FIELDS/APPEARANCE_FIELDS-aware sanitize() below and are saved via
 * the same Side_Cart_Admin::handle_side_cart_save() handler. No frontend
 * cart, popup, floating button, or Store API code reads these appearance
 * values yet; that remains reserved for a later phase.
 */
class Side_Cart_Settings {

	/**
	 * Option name used to store all Side Cart settings.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'manage_cart_side_cart_settings';

	/**
	 * Settings API option group.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'manage_cart_side_cart_settings_group';

	/**
	 * Settings page slug (also used as the Settings API "page" argument).
	 *
	 * @var string
	 */
	const PAGE_SLUG = MANAGE_CART_SIDE_CART_SLUG;

	/**
	 * Settings API "page" argument used purely to render the "Side Cart →
	 * Appearance" tab's fields (Phase 3G-2A), separately from PAGE_SLUG's
	 * "Side Cart → General" fields, so do_settings_sections() draws each
	 * tab's fields only once, in its own tab. Both page slugs render fields
	 * belonging to the same `manage_cart_side_cart_settings` option.
	 *
	 * @var string
	 */
	const APPEARANCE_PAGE_SLUG = self::PAGE_SLUG . '_appearance';

	/**
	 * Field keys belonging to "Side Cart → General". Used by
	 * Side_Cart_Admin::handle_side_cart_save() to know which keys the
	 * General form is authoritative for, so a save from the Appearance form
	 * (which does not submit these keys) does not reset them to defaults.
	 *
	 * @var array
	 */
	const GENERAL_FIELDS = array(
		'layout',
		'drawer_side',
		'mobile_layout',
		'desktop_width',
		'auto_open_on_add',
		'show_undo_countdown',
		'heading',
		'show_cart_icon',
		'show_item_count',
		'show_product_image',
		'show_variation_attributes',
		'show_quantity_controls',
		'show_remove_button',
		'show_low_stock_badge',
		'low_stock_threshold',
	);

	/**
	 * Field keys belonging to "Side Cart → Appearance" (Phase 3G-2A). Used
	 * by Side_Cart_Admin::handle_side_cart_save() to know which keys the
	 * Appearance form is authoritative for, so a save from the General form
	 * (which does not submit these keys) does not reset them to defaults.
	 *
	 * @var array
	 */
	const APPEARANCE_FIELDS = array(
		'panel_bg_color',
		'header_bg_color',
		'header_text_color',
		'body_text_color',
		'checkout_button_color',
		'checkout_button_text_color',
		'border_radius',
	);

	/**
	 * Whitelisted "Cart layout" values.
	 *
	 * @var array
	 */
	const LAYOUT_CHOICES = array( 'drawer', 'popup' );

	/**
	 * Whitelisted "Drawer position" values.
	 *
	 * @var array
	 */
	const DRAWER_SIDE_CHOICES = array( 'right', 'left' );

	/**
	 * Whitelisted "Mobile presentation" values. Phase 2B ships a single
	 * fixed/default option; the field is presented as locked in the UI.
	 *
	 * @var array
	 */
	const MOBILE_LAYOUT_CHOICES = array( 'bottom_sheet' );

	/**
	 * Minimum allowed desktop width, in pixels.
	 *
	 * @var int
	 */
	const DESKTOP_WIDTH_MIN = 280;

	/**
	 * Maximum allowed desktop width, in pixels.
	 *
	 * @var int
	 */
	const DESKTOP_WIDTH_MAX = 560;

	/**
	 * Minimum allowed "Low stock threshold" value.
	 *
	 * @var int
	 */
	const LOW_STOCK_THRESHOLD_MIN = 1;

	/**
	 * Maximum allowed "Low stock threshold" value.
	 *
	 * @var int
	 */
	const LOW_STOCK_THRESHOLD_MAX = 1000;

	/**
	 * Minimum allowed "Border radius" value, in pixels (Phase 3G-2A).
	 *
	 * @var int
	 */
	const BORDER_RADIUS_MIN = 0;

	/**
	 * Maximum allowed "Border radius" value, in pixels (Phase 3G-2A).
	 *
	 * @var int
	 */
	const BORDER_RADIUS_MAX = 32;

	/**
	 * Returns the default settings values.
	 *
	 * Phase 3G-2A: the color defaults below match the colors the frontend
	 * cart panel already renders with today (see assets/css/frontend.css —
	 * `--manage-cart-surface`, `--manage-cart-ink`, and the primary button's
	 * gradient start color and white label), and the border radius default
	 * (16) matches the panel's existing rounded-corner treatment. These are
	 * defaults for a not-yet-applied setting only; they change nothing about
	 * how the frontend actually renders.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'layout'            => 'drawer',
			'drawer_side'       => 'right',
			'mobile_layout'     => 'bottom_sheet',
			'desktop_width'     => 420,
			'auto_open_on_add'  => true,
			'show_undo_countdown' => true,
			'heading'           => __( 'Your cart', 'manage-cart' ),
			'show_cart_icon'    => true,
			'show_item_count'   => true,
			'show_product_image'        => true,
			'show_variation_attributes' => true,
			'show_quantity_controls'    => true,
			'show_remove_button'        => true,
			'show_low_stock_badge'      => true,
			'low_stock_threshold'       => 5,
			'panel_bg_color'             => '#ffffff',
			'header_bg_color'            => '#ffffff',
			'header_text_color'         => '#1d2327',
			'body_text_color'           => '#1d2327',
			'checkout_button_color'     => '#7c3aed',
			'checkout_button_text_color' => '#ffffff',
			'border_radius'             => 16,
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
		 * Filters the merged Side Cart settings.
		 *
		 * Reserved for a future ManageCart Pro add-on to read/append its
		 * own setting keys (e.g. free-shipping progress, cart notes,
		 * display conditions) alongside the free plugin's, without
		 * forking this method. Free ManageCart applies no filter callback
		 * here itself.
		 *
		 * @param array $settings The merged Side Cart settings.
		 */
		return apply_filters( 'manage_cart_side_cart_settings', $settings );
	}

	/**
	 * Returns the "Cart layout" select options.
	 *
	 * @return array
	 */
	public static function get_layout_choices() {
		return array(
			'drawer' => __( 'Drawer', 'manage-cart' ),
			'popup'  => __( 'Popup', 'manage-cart' ),
		);
	}

	/**
	 * Returns the "Drawer position" select options.
	 *
	 * @return array
	 */
	public static function get_drawer_side_choices() {
		return array(
			'right' => __( 'Right', 'manage-cart' ),
			'left'  => __( 'Left', 'manage-cart' ),
		);
	}

	/**
	 * Registers the sections and fields used to render the "Side Cart →
	 * General" tab.
	 *
	 * Phase 3F-6: this no longer calls register_setting(). Side Cart →
	 * General is saved exclusively through Side_Cart_Admin's dedicated
	 * admin-post handler (see Side_Cart_Admin::handle_side_cart_save()),
	 * not through options.php/the Settings API, so registering this option
	 * with the Settings API here would be misleading (and was the source
	 * of its saved notice leaking into the global Settings tab).
	 * add_settings_section()/add_settings_field() are still needed: they
	 * are purely a rendering mechanism, used by do_settings_sections() to
	 * draw this tab's fields regardless of which code path saves the
	 * submitted values.
	 *
	 * @return void
	 */
	public static function register() {
		add_settings_section(
			'manage_cart_side_cart_layout_section',
			__( 'Layout', 'manage-cart' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'manage_cart_side_cart_layout',
			__( 'Cart layout', 'manage-cart' ),
			array( __CLASS__, 'render_layout_field' ),
			self::PAGE_SLUG,
			'manage_cart_side_cart_layout_section'
		);

		add_settings_field(
			'manage_cart_side_cart_drawer_side',
			__( 'Drawer position', 'manage-cart' ),
			array( __CLASS__, 'render_drawer_side_field' ),
			self::PAGE_SLUG,
			'manage_cart_side_cart_layout_section'
		);

		add_settings_field(
			'manage_cart_side_cart_mobile_layout',
			__( 'Mobile presentation', 'manage-cart' ),
			array( __CLASS__, 'render_mobile_layout_field' ),
			self::PAGE_SLUG,
			'manage_cart_side_cart_layout_section'
		);

		add_settings_field(
			'manage_cart_side_cart_desktop_width',
			__( 'Desktop width', 'manage-cart' ),
			array( __CLASS__, 'render_desktop_width_field' ),
			self::PAGE_SLUG,
			'manage_cart_side_cart_layout_section'
		);

		add_settings_section(
			'manage_cart_side_cart_behavior_section',
			__( 'Behavior', 'manage-cart' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'manage_cart_side_cart_auto_open_on_add',
			__( 'Auto-open cart after add to cart', 'manage-cart' ),
			array( __CLASS__, 'render_auto_open_field' ),
			self::PAGE_SLUG,
			'manage_cart_side_cart_behavior_section'
		);

		add_settings_field(
			'manage_cart_side_cart_show_undo_countdown',
			__( 'Show Undo countdown', 'manage-cart' ),
			array( __CLASS__, 'render_show_undo_countdown_field' ),
			self::PAGE_SLUG,
			'manage_cart_side_cart_behavior_section'
		);

		add_settings_section(
			'manage_cart_side_cart_heading_section',
			__( 'Heading', 'manage-cart' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'manage_cart_side_cart_heading',
			__( 'Cart heading', 'manage-cart' ),
			array( __CLASS__, 'render_heading_field' ),
			self::PAGE_SLUG,
			'manage_cart_side_cart_heading_section'
		);

		add_settings_field(
			'manage_cart_side_cart_show_cart_icon',
			__( 'Show cart icon in heading', 'manage-cart' ),
			array( __CLASS__, 'render_show_cart_icon_field' ),
			self::PAGE_SLUG,
			'manage_cart_side_cart_heading_section'
		);

		add_settings_field(
			'manage_cart_side_cart_show_item_count',
			__( 'Show item count in heading', 'manage-cart' ),
			array( __CLASS__, 'render_show_item_count_field' ),
			self::PAGE_SLUG,
			'manage_cart_side_cart_heading_section'
		);

		add_settings_section(
			'manage_cart_side_cart_items_section',
			__( 'Cart Items', 'manage-cart' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'manage_cart_side_cart_show_product_image',
			__( 'Show product image', 'manage-cart' ),
			array( __CLASS__, 'render_show_product_image_field' ),
			self::PAGE_SLUG,
			'manage_cart_side_cart_items_section'
		);

		add_settings_field(
			'manage_cart_side_cart_show_variation_attributes',
			__( 'Show variation attributes', 'manage-cart' ),
			array( __CLASS__, 'render_show_variation_attributes_field' ),
			self::PAGE_SLUG,
			'manage_cart_side_cart_items_section'
		);

		add_settings_field(
			'manage_cart_side_cart_show_quantity_controls',
			__( 'Show quantity controls', 'manage-cart' ),
			array( __CLASS__, 'render_show_quantity_controls_field' ),
			self::PAGE_SLUG,
			'manage_cart_side_cart_items_section'
		);

		add_settings_field(
			'manage_cart_side_cart_show_remove_button',
			__( 'Show remove button', 'manage-cart' ),
			array( __CLASS__, 'render_show_remove_button_field' ),
			self::PAGE_SLUG,
			'manage_cart_side_cart_items_section'
		);

		add_settings_field(
			'manage_cart_side_cart_show_low_stock_badge',
			__( 'Show low stock badge', 'manage-cart' ),
			array( __CLASS__, 'render_show_low_stock_badge_field' ),
			self::PAGE_SLUG,
			'manage_cart_side_cart_items_section'
		);

		add_settings_field(
			'manage_cart_side_cart_low_stock_threshold',
			__( 'Low stock threshold', 'manage-cart' ),
			array( __CLASS__, 'render_low_stock_threshold_field' ),
			self::PAGE_SLUG,
			'manage_cart_side_cart_items_section'
		);

		self::register_appearance_fields();
	}

	/**
	 * Registers the sections and fields used to render the "Side Cart →
	 * Appearance" tab (Phase 3G-2A). Registered under APPEARANCE_PAGE_SLUG
	 * (not PAGE_SLUG) so do_settings_sections( self::APPEARANCE_PAGE_SLUG )
	 * draws only these fields, keeping them out of the General tab. Like
	 * the General tab's fields, these are saved by
	 * Side_Cart_Admin::handle_side_cart_save() directly, not the Settings
	 * API/options.php.
	 *
	 * @return void
	 */
	protected static function register_appearance_fields() {
		add_settings_section(
			'manage_cart_side_cart_appearance_colors_section',
			__( 'Colors', 'manage-cart' ),
			'__return_false',
			self::APPEARANCE_PAGE_SLUG
		);

		add_settings_field(
			'manage_cart_side_cart_panel_bg_color',
			__( 'Panel background color', 'manage-cart' ),
			array( __CLASS__, 'render_panel_bg_color_field' ),
			self::APPEARANCE_PAGE_SLUG,
			'manage_cart_side_cart_appearance_colors_section'
		);

		add_settings_field(
			'manage_cart_side_cart_header_bg_color',
			__( 'Header background color', 'manage-cart' ),
			array( __CLASS__, 'render_header_bg_color_field' ),
			self::APPEARANCE_PAGE_SLUG,
			'manage_cart_side_cart_appearance_colors_section'
		);

		add_settings_field(
			'manage_cart_side_cart_header_text_color',
			__( 'Header text color', 'manage-cart' ),
			array( __CLASS__, 'render_header_text_color_field' ),
			self::APPEARANCE_PAGE_SLUG,
			'manage_cart_side_cart_appearance_colors_section'
		);

		add_settings_field(
			'manage_cart_side_cart_body_text_color',
			__( 'Body text color', 'manage-cart' ),
			array( __CLASS__, 'render_body_text_color_field' ),
			self::APPEARANCE_PAGE_SLUG,
			'manage_cart_side_cart_appearance_colors_section'
		);

		add_settings_field(
			'manage_cart_side_cart_checkout_button_color',
			__( 'Checkout button color', 'manage-cart' ),
			array( __CLASS__, 'render_checkout_button_color_field' ),
			self::APPEARANCE_PAGE_SLUG,
			'manage_cart_side_cart_appearance_colors_section'
		);

		add_settings_field(
			'manage_cart_side_cart_checkout_button_text_color',
			__( 'Checkout button text color', 'manage-cart' ),
			array( __CLASS__, 'render_checkout_button_text_color_field' ),
			self::APPEARANCE_PAGE_SLUG,
			'manage_cart_side_cart_appearance_colors_section'
		);

		add_settings_section(
			'manage_cart_side_cart_appearance_shape_section',
			__( 'Shape', 'manage-cart' ),
			'__return_false',
			self::APPEARANCE_PAGE_SLUG
		);

		add_settings_field(
			'manage_cart_side_cart_border_radius',
			__( 'Border radius', 'manage-cart' ),
			array( __CLASS__, 'render_border_radius_field' ),
			self::APPEARANCE_PAGE_SLUG,
			'manage_cart_side_cart_appearance_shape_section'
		);
	}

	/**
	 * Sanitizes submitted settings before they are stored. Every value is
	 * whitelisted or clamped; nothing submitted is trusted verbatim.
	 *
	 * Phase 3F-6: called directly by Side_Cart_Admin's dedicated admin-post
	 * save handler for this option (not by the Settings API — see
	 * register() above). Does not itself save anything or emit a notice;
	 * the caller is responsible for both, matching
	 * Floating_Cart_Settings::sanitize().
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$defaults = self::get_defaults();

		$layout = isset( $input['layout'] ) ? (string) $input['layout'] : $defaults['layout'];
		if ( ! in_array( $layout, self::LAYOUT_CHOICES, true ) ) {
			$layout = $defaults['layout'];
		}

		$drawer_side = isset( $input['drawer_side'] ) ? (string) $input['drawer_side'] : $defaults['drawer_side'];
		if ( ! in_array( $drawer_side, self::DRAWER_SIDE_CHOICES, true ) ) {
			$drawer_side = $defaults['drawer_side'];
		}

		// Mobile presentation is fixed to a single supported value in Phase 2B.
		$mobile_layout = isset( $input['mobile_layout'] ) ? (string) $input['mobile_layout'] : $defaults['mobile_layout'];
		if ( ! in_array( $mobile_layout, self::MOBILE_LAYOUT_CHOICES, true ) ) {
			$mobile_layout = $defaults['mobile_layout'];
		}

		$desktop_width = isset( $input['desktop_width'] ) ? absint( $input['desktop_width'] ) : $defaults['desktop_width'];
		$desktop_width = max( self::DESKTOP_WIDTH_MIN, min( self::DESKTOP_WIDTH_MAX, $desktop_width ) );

		$heading = isset( $input['heading'] ) ? sanitize_text_field( wp_unslash( $input['heading'] ) ) : $defaults['heading'];
		if ( '' === trim( $heading ) ) {
			$heading = $defaults['heading'];
		}

		$low_stock_threshold = isset( $input['low_stock_threshold'] ) ? absint( $input['low_stock_threshold'] ) : $defaults['low_stock_threshold'];
		if ( $low_stock_threshold < self::LOW_STOCK_THRESHOLD_MIN ) {
			$low_stock_threshold = $defaults['low_stock_threshold'];
		}
		$low_stock_threshold = min( self::LOW_STOCK_THRESHOLD_MAX, $low_stock_threshold );

		$panel_bg_color             = self::sanitize_color( isset( $input['panel_bg_color'] ) ? $input['panel_bg_color'] : null, $defaults['panel_bg_color'] );
		$header_bg_color            = self::sanitize_color( isset( $input['header_bg_color'] ) ? $input['header_bg_color'] : null, $defaults['header_bg_color'] );
		$header_text_color          = self::sanitize_color( isset( $input['header_text_color'] ) ? $input['header_text_color'] : null, $defaults['header_text_color'] );
		$body_text_color            = self::sanitize_color( isset( $input['body_text_color'] ) ? $input['body_text_color'] : null, $defaults['body_text_color'] );
		$checkout_button_color      = self::sanitize_color( isset( $input['checkout_button_color'] ) ? $input['checkout_button_color'] : null, $defaults['checkout_button_color'] );
		$checkout_button_text_color = self::sanitize_color( isset( $input['checkout_button_text_color'] ) ? $input['checkout_button_text_color'] : null, $defaults['checkout_button_text_color'] );

		$border_radius = isset( $input['border_radius'] ) ? absint( $input['border_radius'] ) : $defaults['border_radius'];
		$border_radius = max( self::BORDER_RADIUS_MIN, min( self::BORDER_RADIUS_MAX, $border_radius ) );

		$output = array(
			'layout'           => $layout,
			'drawer_side'      => $drawer_side,
			'mobile_layout'    => $mobile_layout,
			'desktop_width'    => $desktop_width,
			'auto_open_on_add' => ! empty( $input['auto_open_on_add'] ),
			'show_undo_countdown' => ! empty( $input['show_undo_countdown'] ),
			'heading'          => $heading,
			'show_cart_icon'   => ! empty( $input['show_cart_icon'] ),
			'show_item_count'  => ! empty( $input['show_item_count'] ),
			'show_product_image'        => ! empty( $input['show_product_image'] ),
			'show_variation_attributes' => ! empty( $input['show_variation_attributes'] ),
			'show_quantity_controls'    => ! empty( $input['show_quantity_controls'] ),
			'show_remove_button'        => ! empty( $input['show_remove_button'] ),
			'show_low_stock_badge'      => ! empty( $input['show_low_stock_badge'] ),
			'low_stock_threshold'       => $low_stock_threshold,
			'panel_bg_color'             => $panel_bg_color,
			'header_bg_color'            => $header_bg_color,
			'header_text_color'         => $header_text_color,
			'body_text_color'           => $body_text_color,
			'checkout_button_color'     => $checkout_button_color,
			'checkout_button_text_color' => $checkout_button_text_color,
			'border_radius'             => $border_radius,
		);

		return $output;
	}

	/**
	 * Sanitizes a single submitted color value (Phase 3G-2A).
	 *
	 * Uses WordPress's own `sanitize_hex_color()`, which accepts only a
	 * 3- or 6-digit `#rrggbb`/`#rgb` hex value (as produced by a native
	 * `<input type="color">`) or an empty string, and returns null for
	 * anything else. Falls back to the provided default whenever the
	 * submitted value is missing, not a string, or fails that check, so a
	 * malformed or malicious submission can never store anything other than
	 * a well-formed hex color.
	 *
	 * @param mixed  $value   Raw submitted value, or null if not submitted.
	 * @param string $default_color Fallback hex color if sanitization fails.
	 * @return string A safe `#rrggbb` (or `#rgb`) hex color.
	 */
	protected static function sanitize_color( $value, $default_color ) {
		if ( ! is_string( $value ) ) {
			return $default_color;
		}

		$value    = sanitize_text_field( wp_unslash( $value ) );
		$sanitized = sanitize_hex_color( $value );

		if ( empty( $sanitized ) ) {
			return $default_color;
		}

		return $sanitized;
	}

	/**
	 * Resets settings to their defaults and returns the defaults used.
	 *
	 * @return array The default settings that were saved.
	 */
	public static function reset_to_defaults() {
		$defaults = self::get_defaults();

		update_option( self::OPTION_NAME, $defaults );

		return $defaults;
	}

	/**
	 * Renders the "Cart layout" select field.
	 *
	 * @return void
	 */
	public static function render_layout_field() {
		$settings = self::get_settings();
		?>
		<select id="manage-cart-side-cart-layout" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[layout]" class="manage-cart-select">
			<?php foreach ( self::get_layout_choices() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['layout'], $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Choose how the side cart appears when it opens.', 'manage-cart' ); ?></p>
		<?php
	}

	/**
	 * Renders the "Drawer position" select field.
	 *
	 * @return void
	 */
	public static function render_drawer_side_field() {
		$settings = self::get_settings();
		?>
		<select id="manage-cart-side-cart-drawer-side" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[drawer_side]" class="manage-cart-select">
			<?php foreach ( self::get_drawer_side_choices() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['drawer_side'], $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Only used when the cart layout is set to Drawer.', 'manage-cart' ); ?></p>
		<?php
	}

	/**
	 * Renders the "Mobile presentation" field. Phase 2B locks this to
	 * "Bottom sheet", shown as a fixed/default value rather than an
	 * editable choice, while still submitting a value via a hidden input.
	 *
	 * @return void
	 */
	public static function render_mobile_layout_field() {
		?>
		<div class="manage-cart-locked-field">
			<span class="manage-cart-locked-value">
				<?php esc_html_e( 'Bottom sheet', 'manage-cart' ); ?>
			</span>
			<span class="manage-cart-locked-badge"><?php esc_html_e( 'Default', 'manage-cart' ); ?></span>
		</div>
		<input type="hidden" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[mobile_layout]" value="bottom_sheet" />
		<p class="description"><?php esc_html_e( 'On mobile, the cart always opens as a bottom sheet in this phase.', 'manage-cart' ); ?></p>
		<?php
	}

	/**
	 * Renders the "Desktop width" number field.
	 *
	 * @return void
	 */
	public static function render_desktop_width_field() {
		$settings = self::get_settings();
		?>
		<input
			type="number"
			id="manage-cart-side-cart-desktop-width"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[desktop_width]"
			value="<?php echo esc_attr( $settings['desktop_width'] ); ?>"
			min="<?php echo esc_attr( self::DESKTOP_WIDTH_MIN ); ?>"
			max="<?php echo esc_attr( self::DESKTOP_WIDTH_MAX ); ?>"
			step="10"
			class="small-text"
		/>
		<span class="manage-cart-unit"><?php esc_html_e( 'px', 'manage-cart' ); ?></span>
		<p class="description">
			<?php
			printf(
				/* translators: 1: minimum width in pixels, 2: maximum width in pixels. */
				esc_html__( 'Width of the cart panel on desktop screens. Allowed range: %1$d-%2$d px.', 'manage-cart' ),
				(int) self::DESKTOP_WIDTH_MIN,
				(int) self::DESKTOP_WIDTH_MAX
			);
			?>
		</p>
		<?php
	}

	/**
	 * Renders the "Auto-open cart after add to cart" toggle field.
	 *
	 * @return void
	 */
	public static function render_auto_open_field() {
		$settings = self::get_settings();
		?>
		<label class="manage-cart-toggle">
			<input
				type="checkbox"
				id="manage-cart-side-cart-auto-open"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[auto_open_on_add]"
				value="1"
				<?php checked( $settings['auto_open_on_add'] ); ?>
			/>
			<span><?php esc_html_e( 'Automatically open the cart whenever a product is added.', 'manage-cart' ); ?></span>
		</label>
		<?php
	}

	/**
	 * Renders the "Show Undo countdown" toggle field (Undo notice
	 * countdown). Purely a display preference for the existing Undo
	 * notice shown after a successful item removal — it does not change
	 * the notice's fixed 6-second expiry either way, only whether the
	 * seconds remaining are shown as compact text (e.g. "Undo (6)")
	 * alongside it.
	 *
	 * @return void
	 */
	public static function render_show_undo_countdown_field() {
		$settings = self::get_settings();
		?>
		<label class="manage-cart-toggle">
			<input
				type="checkbox"
				id="manage-cart-side-cart-show-undo-countdown"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[show_undo_countdown]"
				value="1"
				<?php checked( $settings['show_undo_countdown'] ); ?>
			/>
			<span><?php esc_html_e( 'Show the seconds remaining to undo a removed cart item.', 'manage-cart' ); ?></span>
		</label>
		<?php
	}

	/**
	 * Renders the "Cart heading" text field.
	 *
	 * @return void
	 */
	public static function render_heading_field() {
		$settings = self::get_settings();
		?>
		<input
			type="text"
			id="manage-cart-side-cart-heading"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[heading]"
			value="<?php echo esc_attr( $settings['heading'] ); ?>"
			class="regular-text"
			maxlength="60"
		/>
		<p class="description"><?php esc_html_e( 'Text shown at the top of the cart panel.', 'manage-cart' ); ?></p>
		<?php
	}

	/**
	 * Renders the "Show cart icon in heading" toggle field.
	 *
	 * @return void
	 */
	public static function render_show_cart_icon_field() {
		$settings = self::get_settings();
		?>
		<label class="manage-cart-toggle">
			<input
				type="checkbox"
				id="manage-cart-side-cart-show-cart-icon"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[show_cart_icon]"
				value="1"
				<?php checked( $settings['show_cart_icon'] ); ?>
			/>
			<span><?php esc_html_e( 'Display a cart icon next to the heading text.', 'manage-cart' ); ?></span>
		</label>
		<?php
	}

	/**
	 * Renders the "Show item count in heading" toggle field.
	 *
	 * @return void
	 */
	public static function render_show_item_count_field() {
		$settings = self::get_settings();
		?>
		<label class="manage-cart-toggle">
			<input
				type="checkbox"
				id="manage-cart-side-cart-show-item-count"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[show_item_count]"
				value="1"
				<?php checked( $settings['show_item_count'] ); ?>
			/>
			<span><?php esc_html_e( 'Display the number of items next to the heading text.', 'manage-cart' ); ?></span>
		</label>
		<?php
	}

	/**
	 * Renders the "Show product image" toggle field.
	 *
	 * @return void
	 */
	public static function render_show_product_image_field() {
		$settings = self::get_settings();
		?>
		<label class="manage-cart-toggle">
			<input
				type="checkbox"
				id="manage-cart-side-cart-show-product-image"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[show_product_image]"
				value="1"
				<?php checked( $settings['show_product_image'] ); ?>
			/>
			<span><?php esc_html_e( 'Display each product\'s image next to its line item.', 'manage-cart' ); ?></span>
		</label>
		<?php
	}

	/**
	 * Renders the "Show variation attributes" toggle field.
	 *
	 * @return void
	 */
	public static function render_show_variation_attributes_field() {
		$settings = self::get_settings();
		?>
		<label class="manage-cart-toggle">
			<input
				type="checkbox"
				id="manage-cart-side-cart-show-variation-attributes"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[show_variation_attributes]"
				value="1"
				<?php checked( $settings['show_variation_attributes'] ); ?>
			/>
			<span><?php esc_html_e( 'Display variation attributes (e.g. size, color) below each line item.', 'manage-cart' ); ?></span>
		</label>
		<?php
	}

	/**
	 * Renders the "Show quantity controls" toggle field.
	 *
	 * @return void
	 */
	public static function render_show_quantity_controls_field() {
		$settings = self::get_settings();
		?>
		<label class="manage-cart-toggle">
			<input
				type="checkbox"
				id="manage-cart-side-cart-show-quantity-controls"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[show_quantity_controls]"
				value="1"
				<?php checked( $settings['show_quantity_controls'] ); ?>
			/>
			<span><?php esc_html_e( 'Allow quantity to be increased or decreased for each line item.', 'manage-cart' ); ?></span>
		</label>
		<?php
	}

	/**
	 * Renders the "Show remove button" toggle field.
	 *
	 * @return void
	 */
	public static function render_show_remove_button_field() {
		$settings = self::get_settings();
		?>
		<label class="manage-cart-toggle">
			<input
				type="checkbox"
				id="manage-cart-side-cart-show-remove-button"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[show_remove_button]"
				value="1"
				<?php checked( $settings['show_remove_button'] ); ?>
			/>
			<span><?php esc_html_e( 'Display a remove button for each line item.', 'manage-cart' ); ?></span>
		</label>
		<?php
	}

	/**
	 * Renders the "Show low stock badge" toggle field.
	 *
	 * @return void
	 */
	public static function render_show_low_stock_badge_field() {
		$settings = self::get_settings();
		?>
		<label class="manage-cart-toggle">
			<input
				type="checkbox"
				id="manage-cart-side-cart-show-low-stock-badge"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[show_low_stock_badge]"
				value="1"
				<?php checked( $settings['show_low_stock_badge'] ); ?>
			/>
			<span><?php esc_html_e( 'Display an "Only X left" badge on cart items that are running low on stock.', 'manage-cart' ); ?></span>
		</label>
		<?php
	}

	/**
	 * Renders the "Low stock threshold" number field.
	 *
	 * @return void
	 */
	public static function render_low_stock_threshold_field() {
		$settings = self::get_settings();
		?>
		<input
			type="number"
			id="manage-cart-side-cart-low-stock-threshold"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[low_stock_threshold]"
			value="<?php echo esc_attr( $settings['low_stock_threshold'] ); ?>"
			min="<?php echo esc_attr( self::LOW_STOCK_THRESHOLD_MIN ); ?>"
			max="<?php echo esc_attr( self::LOW_STOCK_THRESHOLD_MAX ); ?>"
			step="1"
			class="small-text"
		/>
		<p class="description"><?php esc_html_e( 'Show the low stock badge when a product\'s remaining stock is at or below this number.', 'manage-cart' ); ?></p>
		<?php
	}

	/**
	 * Renders a single native color input field (Phase 3G-2A). Shared
	 * helper for all "Side Cart → Appearance" color fields below.
	 *
	 * @param string $field_key   Settings array key, e.g. 'panel_bg_color'.
	 * @param string $field_id    HTML element id.
	 * @param string $description Field description shown below the input.
	 * @return void
	 */
	protected static function render_color_field( $field_key, $field_id, $description ) {
		$settings = self::get_settings();
		$value    = isset( $settings[ $field_key ] ) ? $settings[ $field_key ] : '';
		?>
		<input
			type="color"
			id="<?php echo esc_attr( $field_id ); ?>"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[<?php echo esc_attr( $field_key ); ?>]"
			value="<?php echo esc_attr( $value ); ?>"
			class="manage-cart-color-field"
		/>
		<?php if ( $description ) : ?>
			<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders the "Panel background color" field (Phase 3G-2A).
	 *
	 * @return void
	 */
	public static function render_panel_bg_color_field() {
		self::render_color_field(
			'panel_bg_color',
			'manage-cart-side-cart-panel-bg-color',
			__( 'Background color of the cart panel itself.', 'manage-cart' )
		);
	}

	/**
	 * Renders the "Header background color" field (Phase 3G-2A).
	 *
	 * @return void
	 */
	public static function render_header_bg_color_field() {
		self::render_color_field(
			'header_bg_color',
			'manage-cart-side-cart-header-bg-color',
			__( 'Background color of the cart panel\'s header row.', 'manage-cart' )
		);
	}

	/**
	 * Renders the "Header text color" field (Phase 3G-2A).
	 *
	 * @return void
	 */
	public static function render_header_text_color_field() {
		self::render_color_field(
			'header_text_color',
			'manage-cart-side-cart-header-text-color',
			__( 'Color of the heading text and item count in the header.', 'manage-cart' )
		);
	}

	/**
	 * Renders the "Body text color" field (Phase 3G-2A).
	 *
	 * @return void
	 */
	public static function render_body_text_color_field() {
		self::render_color_field(
			'body_text_color',
			'manage-cart-side-cart-body-text-color',
			__( 'Color of product names and other text in the cart items list.', 'manage-cart' )
		);
	}

	/**
	 * Renders the "Checkout button color" field (Phase 3G-2A).
	 *
	 * @return void
	 */
	public static function render_checkout_button_color_field() {
		self::render_color_field(
			'checkout_button_color',
			'manage-cart-side-cart-checkout-button-color',
			__( 'Background color of the Checkout button.', 'manage-cart' )
		);
	}

	/**
	 * Renders the "Checkout button text color" field (Phase 3G-2A).
	 *
	 * @return void
	 */
	public static function render_checkout_button_text_color_field() {
		self::render_color_field(
			'checkout_button_text_color',
			'manage-cart-side-cart-checkout-button-text-color',
			__( 'Color of the label text on the Checkout button.', 'manage-cart' )
		);
	}

	/**
	 * Renders the "Border radius" number field (Phase 3G-2A).
	 *
	 * @return void
	 */
	public static function render_border_radius_field() {
		$settings = self::get_settings();
		?>
		<input
			type="number"
			id="manage-cart-side-cart-border-radius"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[border_radius]"
			value="<?php echo esc_attr( $settings['border_radius'] ); ?>"
			min="<?php echo esc_attr( self::BORDER_RADIUS_MIN ); ?>"
			max="<?php echo esc_attr( self::BORDER_RADIUS_MAX ); ?>"
			step="1"
			class="small-text"
		/>
		<span class="manage-cart-unit"><?php esc_html_e( 'px', 'manage-cart' ); ?></span>
		<p class="description">
			<?php
			printf(
				/* translators: 1: minimum border radius in pixels, 2: maximum border radius in pixels. */
				esc_html__( 'Roundness of the cart panel\'s corners. Allowed range: %1$d-%2$d px.', 'manage-cart' ),
				(int) self::BORDER_RADIUS_MIN,
				(int) self::BORDER_RADIUS_MAX
			);
			?>
		</p>
		<?php
	}
}
