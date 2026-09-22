<?php
/**
 * Floating Cart settings API registration and option management (Phase 3E-1).
 *
 * @package ManageCart
 */

namespace ManageCart;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Floating_Cart_Settings
 *
 * Registers the "Floating Cart → General" settings with the WordPress
 * Settings API, defines defaults, and sanitizes/whitelists everything
 * submitted from that tab. Stores its data in its own option,
 * `manage_cart_floating_cart_settings`, completely separate from both the
 * global `manage_cart_settings` option and the `manage_cart_side_cart_settings`
 * option. These are the first Floating Cart settings; the frontend floating
 * trigger button now reads them (see Cart_Renderer::render_trigger() and
 * Frontend::should_load()) to decide whether it appears at all and, when it
 * does, which screen corner and pixel offsets it uses.
 *
 * Phase 3E-2 adds three more fields to this same "General" tab: "Show item
 * count badge" (default on) and "Hide floating cart when cart is empty"
 * (default off), both read by Cart_Renderer::render_trigger() and the
 * frontend script; and "Allow customers to move floating cart button",
 * moved here from the Side Cart → General tab, where it previously lived
 * under a "Floating Cart" settings-section label even though it saved to
 * the `manage_cart_side_cart_settings` option. get_settings() below
 * transparently migrates any previously saved value for that field the
 * first time it is read from this class, so existing installs keep their
 * saved value and the frontend's existing drag behavior (Phase 3A.2,
 * assets/js/frontend-cart.js) is unaffected. The trigger's existing
 * hide-while-panel-open behavior (Phase 3B-1 Part 4) is also unchanged.
 *
 * Phase 3E-6: this option is no longer saved through the WordPress
 * Settings API/options.php. It is saved exclusively by
 * Side_Cart_Admin::handle_floating_cart_save(), a dedicated admin-post
 * handler that verifies the nonce and `manage_woocommerce` capability
 * itself, calls sanitize() below directly, and updates this option with
 * update_option(). register() still registers the settings section/fields
 * used purely to render the "Floating Cart → General" form.
 *
 * Phase 3G-3A adds a "Floating Cart → Appearance" field group (button
 * background color, cart icon color, count badge background/text colors,
 * button size, and button border radius) to this same option and option
 * shape, mirroring how Side_Cart_Settings' Phase 3G-2A Appearance fields
 * were added alongside its existing General fields. These fields are
 * rendered under a separate Settings API page slug (self::APPEARANCE_PAGE_SLUG)
 * so do_settings_sections() can draw the Appearance tab's fields without
 * also drawing them on the General tab, but they are still part of the
 * single GENERAL_FIELDS/APPEARANCE_FIELDS-aware sanitize() below and are
 * saved via the same Side_Cart_Admin::handle_floating_cart_save() handler.
 *
 * Phase 3G-3B: the real frontend floating trigger button now reads these
 * appearance values too (see Cart_Renderer::render_trigger() and
 * Cart_Renderer::get_trigger_appearance_style_string()), and the Floating
 * Cart → Appearance admin tab gained a live preview that updates as these
 * fields change, before Save Changes is clicked (see
 * Side_Cart_Admin::render_floating_cart_preview() and
 * assets/js/floating-cart-preview.js). Nothing about this class's own
 * fields, defaults, or sanitize() rules changed.
 */
class Floating_Cart_Settings {

	/**
	 * Option name used to store all Floating Cart settings.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'manage_cart_floating_cart_settings';

	/**
	 * Settings API option group.
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'manage_cart_floating_cart_settings_group';

	/**
	 * Settings page slug (also used as the Settings API "page" argument).
	 *
	 * @var string
	 */
	const PAGE_SLUG = MANAGE_CART_FLOATING_CART_SLUG;

	/**
	 * Settings API "page" argument used purely to render the "Floating
	 * Cart → Appearance" tab's fields (Phase 3G-3A), separately from
	 * PAGE_SLUG's "Floating Cart → General" fields, so
	 * do_settings_sections() draws each tab's fields only once, in its own
	 * tab. Both page slugs render fields belonging to the same
	 * `manage_cart_floating_cart_settings` option.
	 *
	 * @var string
	 */
	const APPEARANCE_PAGE_SLUG = self::PAGE_SLUG . '_appearance';

	/**
	 * Field keys belonging to "Floating Cart → General". Used by
	 * Side_Cart_Admin::handle_floating_cart_save() to know which keys the
	 * General form is authoritative for, so a save from the Appearance
	 * form (which does not submit these keys) does not reset them to
	 * defaults.
	 *
	 * @var array
	 */
	const GENERAL_FIELDS = array(
		'enabled',
		'position',
		'horizontal_offset',
		'vertical_offset',
		'show_item_count_badge',
		'hide_when_empty',
		'allow_trigger_drag',
	);

	/**
	 * Field keys belonging to "Floating Cart → Appearance" (Phase 3G-3A).
	 * Used by Side_Cart_Admin::handle_floating_cart_save() to know which
	 * keys the Appearance form is authoritative for, so a save from the
	 * General form (which does not submit these keys) does not reset them
	 * to defaults.
	 *
	 * @var array
	 */
	const APPEARANCE_FIELDS = array(
		'button_bg_color',
		'icon_color',
		'badge_bg_color',
		'badge_text_color',
		'button_size',
		'border_radius',
	);

	/**
	 * Whitelisted "Position" values.
	 *
	 * @var array
	 */
	const POSITION_CHOICES = array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' );

	/**
	 * Minimum allowed offset, in pixels.
	 *
	 * @var int
	 */
	const OFFSET_MIN = 0;

	/**
	 * Maximum allowed offset, in pixels.
	 *
	 * @var int
	 */
	const OFFSET_MAX = 300;

	/**
	 * Minimum allowed "Button size" value, in pixels (Phase 3G-3A).
	 *
	 * @var int
	 */
	const BUTTON_SIZE_MIN = 40;

	/**
	 * Maximum allowed "Button size" value, in pixels (Phase 3G-3A).
	 *
	 * @var int
	 */
	const BUTTON_SIZE_MAX = 80;

	/**
	 * Minimum allowed "Button border radius" value, as a percentage
	 * (Phase 3G-3A).
	 *
	 * @var int
	 */
	const BORDER_RADIUS_MIN = 0;

	/**
	 * Maximum allowed "Button border radius" value, as a percentage
	 * (Phase 3G-3A).
	 *
	 * @var int
	 */
	const BORDER_RADIUS_MAX = 50;

	/**
	 * Returns the default settings values.
	 *
	 * Phase 3G-3A: the color, size, and border radius defaults below match
	 * the floating trigger button's current fixed frontend styling (see
	 * assets/css/frontend.css — `.manage-cart-trigger`'s gradient start
	 * color for the button background, its white icon/label color, and
	 * `.manage-cart-trigger-count`'s dark background with white text),
	 * with a 58px button size and fully round (50%) corners matching the
	 * trigger's existing fixed 58px circular button. These are defaults
	 * for a not-yet-applied setting only; they change nothing about how
	 * the frontend actually renders.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'enabled'               => true,
			'position'              => 'bottom-right',
			'horizontal_offset'     => 24,
			'vertical_offset'       => 24,
			'show_item_count_badge' => true,
			'hide_when_empty'       => false,
			'allow_trigger_drag'    => false,
			'button_bg_color'       => '#7c3aed',
			'icon_color'            => '#ffffff',
			'badge_bg_color'        => '#1d2327',
			'badge_text_color'      => '#ffffff',
			'button_size'           => 58,
			'border_radius'         => 50,
		);
	}

	/**
	 * Retrieves the current settings, merged over the defaults so missing
	 * keys are always present.
	 *
	 * Phase 3E-2 moved "Allow customers to move floating cart button" here
	 * from the Side Cart settings option. The very first time this is
	 * called after upgrading, the stored Floating Cart option will not yet
	 * have an `allow_trigger_drag` key at all (it is not simply falling
	 * back to a default): in that one case only, the value is migrated
	 * from the legacy `manage_cart_side_cart_settings` option (see
	 * get_legacy_allow_trigger_drag()) and the merged result is saved back
	 * immediately, so every subsequent read — and the Settings API's own
	 * handling of this option — sees a normal, already-migrated value.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$needs_migration = ! array_key_exists( 'allow_trigger_drag', $stored );

		if ( $needs_migration ) {
			$stored['allow_trigger_drag'] = self::get_legacy_allow_trigger_drag();
		}

		$settings = wp_parse_args( $stored, self::get_defaults() );

		if ( $needs_migration ) {
			update_option( self::OPTION_NAME, $settings );
		}

		/**
		 * Filters the merged Floating Cart settings.
		 *
		 * Reserved for a future ManageCart Pro add-on to read/append its
		 * own setting keys (e.g. menu-cart, advanced styling) alongside
		 * the free plugin's, without forking this method. Free ManageCart
		 * applies no filter callback here itself.
		 *
		 * @param array $settings The merged Floating Cart settings.
		 */
		return apply_filters( 'manage_cart_floating_cart_settings', $settings );
	}

	/**
	 * Reads the "Allow customers to move floating cart button" value
	 * previously saved under the Side Cart settings option, for the
	 * one-time migration in get_settings() above. Falls back to this
	 * class's own default when the legacy option does not exist or never
	 * had the field (e.g. a brand-new install).
	 *
	 * @return bool
	 */
	protected static function get_legacy_allow_trigger_drag() {
		$legacy = get_option( Side_Cart_Settings::OPTION_NAME );

		if ( is_array( $legacy ) && array_key_exists( 'allow_trigger_drag', $legacy ) ) {
			return ! empty( $legacy['allow_trigger_drag'] );
		}

		$defaults = self::get_defaults();

		return $defaults['allow_trigger_drag'];
	}

	/**
	 * Returns the "Position" select options.
	 *
	 * @return array
	 */
	public static function get_position_choices() {
		return array(
			'bottom-right' => __( 'Bottom right', 'manage-cart' ),
			'bottom-left'  => __( 'Bottom left', 'manage-cart' ),
			'top-right'    => __( 'Top right', 'manage-cart' ),
			'top-left'     => __( 'Top left', 'manage-cart' ),
		);
	}

	/**
	 * Returns the "Allow customers to move floating cart button" select
	 * options.
	 *
	 * @return array
	 */
	public static function get_allow_trigger_drag_choices() {
		return array(
			'no'  => __( 'No', 'manage-cart' ),
			'yes' => __( 'Yes', 'manage-cart' ),
		);
	}

	/**
	 * Registers the section and fields used to render the "Floating Cart →
	 * General" tab.
	 *
	 * Phase 3E-6: this no longer calls register_setting(). Floating Cart →
	 * General is saved exclusively through Side_Cart_Admin's dedicated
	 * admin-post handler (see Side_Cart_Admin::handle_floating_cart_save()),
	 * not through options.php/the Settings API, so registering this option
	 * with the Settings API here would be misleading (and, previously, was
	 * the source of the repeated "Allow customers to move floating cart
	 * button" save failures). add_settings_section()/add_settings_field()
	 * are still needed: they are purely a rendering mechanism, used by
	 * do_settings_sections() to draw this tab's fields regardless of which
	 * code path saves the submitted values.
	 *
	 * @return void
	 */
	public static function register() {
		add_settings_section(
			'manage_cart_floating_cart_general_section',
			__( 'General', 'manage-cart' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'manage_cart_floating_cart_enabled',
			__( 'Enable Floating Cart', 'manage-cart' ),
			array( __CLASS__, 'render_enabled_field' ),
			self::PAGE_SLUG,
			'manage_cart_floating_cart_general_section'
		);

		add_settings_field(
			'manage_cart_floating_cart_position',
			__( 'Position', 'manage-cart' ),
			array( __CLASS__, 'render_position_field' ),
			self::PAGE_SLUG,
			'manage_cart_floating_cart_general_section'
		);

		add_settings_field(
			'manage_cart_floating_cart_horizontal_offset',
			__( 'Horizontal offset', 'manage-cart' ),
			array( __CLASS__, 'render_horizontal_offset_field' ),
			self::PAGE_SLUG,
			'manage_cart_floating_cart_general_section'
		);

		add_settings_field(
			'manage_cart_floating_cart_vertical_offset',
			__( 'Vertical offset', 'manage-cart' ),
			array( __CLASS__, 'render_vertical_offset_field' ),
			self::PAGE_SLUG,
			'manage_cart_floating_cart_general_section'
		);

		add_settings_field(
			'manage_cart_floating_cart_show_item_count_badge',
			__( 'Show item count badge', 'manage-cart' ),
			array( __CLASS__, 'render_show_item_count_badge_field' ),
			self::PAGE_SLUG,
			'manage_cart_floating_cart_general_section'
		);

		add_settings_field(
			'manage_cart_floating_cart_hide_when_empty',
			__( 'Hide floating cart when cart is empty', 'manage-cart' ),
			array( __CLASS__, 'render_hide_when_empty_field' ),
			self::PAGE_SLUG,
			'manage_cart_floating_cart_general_section'
		);

		add_settings_field(
			'manage_cart_floating_cart_allow_trigger_drag',
			__( 'Allow customers to move floating cart button', 'manage-cart' ),
			array( __CLASS__, 'render_allow_trigger_drag_field' ),
			self::PAGE_SLUG,
			'manage_cart_floating_cart_general_section'
		);

		self::register_appearance_fields();
	}

	/**
	 * Registers the sections and fields used to render the "Floating Cart
	 * → Appearance" tab (Phase 3G-3A). Registered under
	 * APPEARANCE_PAGE_SLUG (not PAGE_SLUG) so
	 * do_settings_sections( self::APPEARANCE_PAGE_SLUG ) draws only these
	 * fields, keeping them out of the General tab. Like the General tab's
	 * fields, these are saved by Side_Cart_Admin::handle_floating_cart_save()
	 * directly, not the Settings API/options.php.
	 *
	 * @return void
	 */
	protected static function register_appearance_fields() {
		add_settings_section(
			'manage_cart_floating_cart_appearance_colors_section',
			__( 'Colors', 'manage-cart' ),
			'__return_false',
			self::APPEARANCE_PAGE_SLUG
		);

		add_settings_field(
			'manage_cart_floating_cart_button_bg_color',
			__( 'Button background color', 'manage-cart' ),
			array( __CLASS__, 'render_button_bg_color_field' ),
			self::APPEARANCE_PAGE_SLUG,
			'manage_cart_floating_cart_appearance_colors_section'
		);

		add_settings_field(
			'manage_cart_floating_cart_icon_color',
			__( 'Cart icon color', 'manage-cart' ),
			array( __CLASS__, 'render_icon_color_field' ),
			self::APPEARANCE_PAGE_SLUG,
			'manage_cart_floating_cart_appearance_colors_section'
		);

		add_settings_field(
			'manage_cart_floating_cart_badge_bg_color',
			__( 'Count badge background color', 'manage-cart' ),
			array( __CLASS__, 'render_badge_bg_color_field' ),
			self::APPEARANCE_PAGE_SLUG,
			'manage_cart_floating_cart_appearance_colors_section'
		);

		add_settings_field(
			'manage_cart_floating_cart_badge_text_color',
			__( 'Count badge text color', 'manage-cart' ),
			array( __CLASS__, 'render_badge_text_color_field' ),
			self::APPEARANCE_PAGE_SLUG,
			'manage_cart_floating_cart_appearance_colors_section'
		);

		add_settings_section(
			'manage_cart_floating_cart_appearance_shape_section',
			__( 'Shape', 'manage-cart' ),
			'__return_false',
			self::APPEARANCE_PAGE_SLUG
		);

		add_settings_field(
			'manage_cart_floating_cart_button_size',
			__( 'Button size', 'manage-cart' ),
			array( __CLASS__, 'render_button_size_field' ),
			self::APPEARANCE_PAGE_SLUG,
			'manage_cart_floating_cart_appearance_shape_section'
		);

		add_settings_field(
			'manage_cart_floating_cart_border_radius',
			__( 'Button border radius', 'manage-cart' ),
			array( __CLASS__, 'render_border_radius_field' ),
			self::APPEARANCE_PAGE_SLUG,
			'manage_cart_floating_cart_appearance_shape_section'
		);
	}

	/**
	 * Sanitizes submitted settings before they are stored. Every value is
	 * whitelisted or clamped; nothing submitted is trusted verbatim.
	 *
	 * Phase 3E-6: called directly by Side_Cart_Admin's dedicated
	 * admin-post save handler for this option (not by the Settings API —
	 * see register() above). Does not itself save anything or emit a
	 * notice; the caller is responsible for both.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$defaults = self::get_defaults();

		$position = isset( $input['position'] ) ? (string) $input['position'] : $defaults['position'];
		if ( ! in_array( $position, self::POSITION_CHOICES, true ) ) {
			$position = $defaults['position'];
		}

		$horizontal_offset = isset( $input['horizontal_offset'] ) ? absint( $input['horizontal_offset'] ) : $defaults['horizontal_offset'];
		$horizontal_offset = max( self::OFFSET_MIN, min( self::OFFSET_MAX, $horizontal_offset ) );

		$vertical_offset = isset( $input['vertical_offset'] ) ? absint( $input['vertical_offset'] ) : $defaults['vertical_offset'];
		$vertical_offset = max( self::OFFSET_MIN, min( self::OFFSET_MAX, $vertical_offset ) );

		// The field submits the string "yes"/"no"; only "yes" enables the setting.
		$allow_trigger_drag_raw = isset( $input['allow_trigger_drag'] ) ? (string) $input['allow_trigger_drag'] : '';
		$allow_trigger_drag     = in_array( $allow_trigger_drag_raw, array_keys( self::get_allow_trigger_drag_choices() ), true )
			? ( 'yes' === $allow_trigger_drag_raw )
			: $defaults['allow_trigger_drag'];

		$button_bg_color   = self::sanitize_color( isset( $input['button_bg_color'] ) ? $input['button_bg_color'] : null, $defaults['button_bg_color'] );
		$icon_color        = self::sanitize_color( isset( $input['icon_color'] ) ? $input['icon_color'] : null, $defaults['icon_color'] );
		$badge_bg_color    = self::sanitize_color( isset( $input['badge_bg_color'] ) ? $input['badge_bg_color'] : null, $defaults['badge_bg_color'] );
		$badge_text_color  = self::sanitize_color( isset( $input['badge_text_color'] ) ? $input['badge_text_color'] : null, $defaults['badge_text_color'] );

		$button_size = isset( $input['button_size'] ) ? absint( $input['button_size'] ) : $defaults['button_size'];
		$button_size = max( self::BUTTON_SIZE_MIN, min( self::BUTTON_SIZE_MAX, $button_size ) );

		$border_radius = isset( $input['border_radius'] ) ? absint( $input['border_radius'] ) : $defaults['border_radius'];
		$border_radius = max( self::BORDER_RADIUS_MIN, min( self::BORDER_RADIUS_MAX, $border_radius ) );

		$output = array(
			'enabled'               => ! empty( $input['enabled'] ),
			'position'              => $position,
			'horizontal_offset'     => $horizontal_offset,
			'vertical_offset'       => $vertical_offset,
			'show_item_count_badge' => ! empty( $input['show_item_count_badge'] ),
			'hide_when_empty'       => ! empty( $input['hide_when_empty'] ),
			'allow_trigger_drag'    => $allow_trigger_drag,
			'button_bg_color'       => $button_bg_color,
			'icon_color'            => $icon_color,
			'badge_bg_color'        => $badge_bg_color,
			'badge_text_color'      => $badge_text_color,
			'button_size'           => $button_size,
			'border_radius'         => $border_radius,
		);

		return $output;
	}

	/**
	 * Sanitizes a single submitted color value (Phase 3G-3A).
	 *
	 * Uses WordPress's own `sanitize_hex_color()`, which accepts only a
	 * 3- or 6-digit `#rrggbb`/`#rgb` hex value (as produced by a native
	 * `<input type="color">`) or an empty string, and returns null for
	 * anything else. Falls back to the provided default whenever the
	 * submitted value is missing, not a string, or fails that check, so a
	 * malformed or malicious submission can never store anything other
	 * than a well-formed hex color. Mirrors
	 * Side_Cart_Settings::sanitize_color().
	 *
	 * @param mixed  $value         Raw submitted value, or null if not submitted.
	 * @param string $default_color Fallback hex color if sanitization fails.
	 * @return string A safe `#rrggbb` (or `#rgb`) hex color.
	 */
	protected static function sanitize_color( $value, $default_color ) {
		if ( ! is_string( $value ) ) {
			return $default_color;
		}

		$value     = sanitize_text_field( wp_unslash( $value ) );
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
	 * Renders the "Enable Floating Cart" checkbox field.
	 *
	 * @return void
	 */
	public static function render_enabled_field() {
		$settings = self::get_settings();
		?>
		<label class="manage-cart-toggle">
			<input
				type="checkbox"
				id="manage-cart-floating-cart-enabled"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled]"
				value="1"
				<?php checked( $settings['enabled'] ); ?>
			/>
			<span><?php esc_html_e( 'Show the floating cart button on the front end.', 'manage-cart' ); ?></span>
		</label>
		<?php
	}

	/**
	 * Renders the "Position" select field.
	 *
	 * @return void
	 */
	public static function render_position_field() {
		$settings = self::get_settings();
		?>
		<select id="manage-cart-floating-cart-position" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[position]" class="manage-cart-select">
			<?php foreach ( self::get_position_choices() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['position'], $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Which corner of the screen the floating cart button appears in.', 'manage-cart' ); ?></p>
		<?php
	}

	/**
	 * Renders the "Horizontal offset" number field.
	 *
	 * @return void
	 */
	public static function render_horizontal_offset_field() {
		$settings = self::get_settings();
		?>
		<input
			type="number"
			id="manage-cart-floating-cart-horizontal-offset"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[horizontal_offset]"
			value="<?php echo esc_attr( $settings['horizontal_offset'] ); ?>"
			min="<?php echo esc_attr( self::OFFSET_MIN ); ?>"
			max="<?php echo esc_attr( self::OFFSET_MAX ); ?>"
			step="1"
			class="small-text"
		/>
		<span class="manage-cart-unit"><?php esc_html_e( 'px', 'manage-cart' ); ?></span>
		<p class="description">
			<?php
			printf(
				/* translators: 1: minimum offset in pixels, 2: maximum offset in pixels. */
				esc_html__( 'Distance from the left or right edge of the screen. Allowed range: %1$d-%2$d px.', 'manage-cart' ),
				(int) self::OFFSET_MIN,
				(int) self::OFFSET_MAX
			);
			?>
		</p>
		<?php
	}

	/**
	 * Renders the "Vertical offset" number field.
	 *
	 * @return void
	 */
	public static function render_vertical_offset_field() {
		$settings = self::get_settings();
		?>
		<input
			type="number"
			id="manage-cart-floating-cart-vertical-offset"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[vertical_offset]"
			value="<?php echo esc_attr( $settings['vertical_offset'] ); ?>"
			min="<?php echo esc_attr( self::OFFSET_MIN ); ?>"
			max="<?php echo esc_attr( self::OFFSET_MAX ); ?>"
			step="1"
			class="small-text"
		/>
		<span class="manage-cart-unit"><?php esc_html_e( 'px', 'manage-cart' ); ?></span>
		<p class="description">
			<?php
			printf(
				/* translators: 1: minimum offset in pixels, 2: maximum offset in pixels. */
				esc_html__( 'Distance from the top or bottom edge of the screen. Allowed range: %1$d-%2$d px.', 'manage-cart' ),
				(int) self::OFFSET_MIN,
				(int) self::OFFSET_MAX
			);
			?>
		</p>
		<?php
	}

	/**
	 * Renders the "Show item count badge" checkbox field.
	 *
	 * @return void
	 */
	public static function render_show_item_count_badge_field() {
		$settings = self::get_settings();
		?>
		<label class="manage-cart-toggle">
			<input
				type="checkbox"
				id="manage-cart-floating-cart-show-item-count-badge"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[show_item_count_badge]"
				value="1"
				<?php checked( $settings['show_item_count_badge'] ); ?>
			/>
			<span><?php esc_html_e( 'Display a badge on the floating cart button showing the number of items in the cart.', 'manage-cart' ); ?></span>
		</label>
		<?php
	}

	/**
	 * Renders the "Hide floating cart when cart is empty" checkbox field.
	 *
	 * @return void
	 */
	public static function render_hide_when_empty_field() {
		$settings = self::get_settings();
		?>
		<label class="manage-cart-toggle">
			<input
				type="checkbox"
				id="manage-cart-floating-cart-hide-when-empty"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[hide_when_empty]"
				value="1"
				<?php checked( $settings['hide_when_empty'] ); ?>
			/>
			<span><?php esc_html_e( 'Hide the floating cart button whenever there are no items in the cart.', 'manage-cart' ); ?></span>
		</label>
		<?php
	}

	/**
	 * Renders the "Allow customers to move floating cart button" select
	 * field. Moved here from Side_Cart_Settings (Phase 3E-2); same markup,
	 * same submitted "yes"/"no" values, and the same frontend dragging
	 * behavior it has always controlled (assets/js/frontend-cart.js).
	 *
	 * @return void
	 */
	public static function render_allow_trigger_drag_field() {
		$settings = self::get_settings();
		$current  = ! empty( $settings['allow_trigger_drag'] ) ? 'yes' : 'no';
		?>
		<select id="manage-cart-floating-cart-allow-trigger-drag" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[allow_trigger_drag]" class="manage-cart-select">
			<?php foreach ( self::get_allow_trigger_drag_choices() as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'When enabled, customers can drag the closed floating cart button to a preferred screen position. Their position is stored only in their own browser.', 'manage-cart' ); ?></p>
		<?php
	}

	/**
	 * Renders a single native color input field (Phase 3G-3A). Shared
	 * helper for all "Floating Cart → Appearance" color fields below.
	 * Mirrors Side_Cart_Settings::render_color_field().
	 *
	 * @param string $field_key   Settings array key, e.g. 'button_bg_color'.
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
	 * Renders the "Button background color" field (Phase 3G-3A).
	 *
	 * @return void
	 */
	public static function render_button_bg_color_field() {
		self::render_color_field(
			'button_bg_color',
			'manage-cart-floating-cart-button-bg-color',
			__( 'Background color of the floating cart button.', 'manage-cart' )
		);
	}

	/**
	 * Renders the "Cart icon color" field (Phase 3G-3A).
	 *
	 * @return void
	 */
	public static function render_icon_color_field() {
		self::render_color_field(
			'icon_color',
			'manage-cart-floating-cart-icon-color',
			__( 'Color of the cart icon inside the floating cart button.', 'manage-cart' )
		);
	}

	/**
	 * Renders the "Count badge background color" field (Phase 3G-3A).
	 *
	 * @return void
	 */
	public static function render_badge_bg_color_field() {
		self::render_color_field(
			'badge_bg_color',
			'manage-cart-floating-cart-badge-bg-color',
			__( 'Background color of the item count badge on the floating cart button.', 'manage-cart' )
		);
	}

	/**
	 * Renders the "Count badge text color" field (Phase 3G-3A).
	 *
	 * @return void
	 */
	public static function render_badge_text_color_field() {
		self::render_color_field(
			'badge_text_color',
			'manage-cart-floating-cart-badge-text-color',
			__( 'Color of the number shown inside the item count badge.', 'manage-cart' )
		);
	}

	/**
	 * Renders the "Button size" number field (Phase 3G-3A).
	 *
	 * @return void
	 */
	public static function render_button_size_field() {
		$settings = self::get_settings();
		?>
		<input
			type="number"
			id="manage-cart-floating-cart-button-size"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[button_size]"
			value="<?php echo esc_attr( $settings['button_size'] ); ?>"
			min="<?php echo esc_attr( self::BUTTON_SIZE_MIN ); ?>"
			max="<?php echo esc_attr( self::BUTTON_SIZE_MAX ); ?>"
			step="1"
			class="small-text"
		/>
		<span class="manage-cart-unit"><?php esc_html_e( 'px', 'manage-cart' ); ?></span>
		<p class="description">
			<?php
			printf(
				/* translators: 1: minimum button size in pixels, 2: maximum button size in pixels. */
				esc_html__( 'Diameter of the floating cart button. Allowed range: %1$d-%2$d px.', 'manage-cart' ),
				(int) self::BUTTON_SIZE_MIN,
				(int) self::BUTTON_SIZE_MAX
			);
			?>
		</p>
		<?php
	}

	/**
	 * Renders the "Button border radius" number field (Phase 3G-3A).
	 *
	 * @return void
	 */
	public static function render_border_radius_field() {
		$settings = self::get_settings();
		?>
		<input
			type="number"
			id="manage-cart-floating-cart-border-radius"
			name="<?php echo esc_attr( self::OPTION_NAME ); ?>[border_radius]"
			value="<?php echo esc_attr( $settings['border_radius'] ); ?>"
			min="<?php echo esc_attr( self::BORDER_RADIUS_MIN ); ?>"
			max="<?php echo esc_attr( self::BORDER_RADIUS_MAX ); ?>"
			step="1"
			class="small-text"
		/>
		<span class="manage-cart-unit">%</span>
		<p class="description">
			<?php
			printf(
				/* translators: 1: minimum border radius percentage, 2: maximum border radius percentage. */
				esc_html__( 'Roundness of the floating cart button\'s corners. Allowed range: %1$d-%2$d%%.', 'manage-cart' ),
				(int) self::BORDER_RADIUS_MIN,
				(int) self::BORDER_RADIUS_MAX
			);
			?>
		</p>
		<?php
	}
}
