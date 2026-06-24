<?php
/**
 * BB Custom Dark Mode — Frontend Output
 *
 * Dynamic CSS injection, anti-flash script, toggle shortcode, and
 * frontend asset enqueuing.
 *
 * @package BB_Custom_Dark_Mode
 * @since   3.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all frontend rendering: CSS, JS, anti-flash, and shortcode.
 */
class BBCustomDarkMode_Frontend {

	/**
	 * Plugin option name.
	 *
	 * @since 3.8.0
	 * @var   string
	 */
	private $option_name = 'bb_dark_mode_settings';

	/**
	 * Script handle for the frontend JS.
	 *
	 * @since 3.8.0
	 * @var   string
	 */
	private $script_handle = 'bb-dark-mode-frontend';

	/**
	 * Register hooks.
	 *
	 * @since 3.8.0
	 */
	public function __construct() {
		add_action( 'wp_head', array( $this, 'inject_anti_flash_script' ), 1 );
		add_action( 'wp_head', array( $this, 'inject_dynamic_css' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_shortcode( 'bb_dark_mode_toggle', array( $this, 'toggle_shortcode' ) );
	}

	// -------------------------------------------------------------------------
	// Anti-flash head script
	// -------------------------------------------------------------------------

	/**
	 * Injected at wp_head priority 1 — before any content or styles render.
	 *
	 * Reads localStorage and prefers-color-scheme and applies body.dark-mode
	 * immediately, so the browser never paints a light-mode frame first.
	 * Kept intentionally tiny — no jQuery, no dependencies.
	 *
	 * @since 3.8.0
	 */
	public function inject_anti_flash_script() {
		$saved = (array) get_option( $this->option_name, array() );
		$sync  = ! empty( $saved['system_sync'] ) ? 'true' : 'false';
		?>
		<script>
		(function() {
			try {
				var stored  = localStorage.getItem('bb_pref_theme'); // 'dark' | 'light' | null
				var sysDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
				var sync    = <?php echo $sync; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded 'true'/'false' ?>;

				var shouldBeDark = (stored === 'dark') ||
								   (stored === null && sync && sysDark);

				if (shouldBeDark) {
					document.documentElement.classList.add('dark-mode-pending');
					document.addEventListener('DOMContentLoaded', function() {
						document.documentElement.classList.remove('dark-mode-pending');
						document.body.classList.add('dark-mode');
					});
				}
			} catch(e) {}
		})();
		</script>
		<style>
		html.dark-mode-pending body { visibility: hidden; }
		</style>
		<?php
	}

	// -------------------------------------------------------------------------
	// Front-end dynamic CSS
	// -------------------------------------------------------------------------

	/**
	 * Output the dynamic <style> block for dark-mode colour overrides and
	 * toggle-button styling.
	 *
	 * @since 3.8.0
	 */
	public function inject_dynamic_css() {
		if ( is_admin() ) {
			return;
		}

		$saved = (array) get_option( $this->option_name, array() );

		// Exclusions — post type.
		if ( in_array( get_post_type(), (array) ( isset( $saved['excluded_types'] ) ? $saved['excluded_types'] : array() ), true ) ) {
			return;
		}

		// Exclusions — IDs (strict int comparison).
		$excluded_ids = array_filter(
			array_map( 'intval', array_map( 'trim', explode( ',', isset( $saved['excluded_ids'] ) ? $saved['excluded_ids'] : '' ) ) )
		);
		if ( ! empty( $excluded_ids ) && in_array( (int) get_the_ID(), $excluded_ids, true ) ) {
			return;
		}

		$btn = array_merge(
			array(
				'size'         => 44,
				'shape'        => 'round',
				'bg'           => '',
				'icon'         => '',
				'border'       => '',
				'bg_hover'     => '',
				'icon_hover'   => '',
				'border_hover' => '',
			),
			(array) ( isset( $saved['btn'] ) ? $saved['btn'] : array() )
		);

		$btn_size         = $this->sanitize_int( isset( $btn['size'] ) ? $btn['size'] : 44 );
		$radius           = ( ( isset( $btn['shape'] ) ? $btn['shape'] : 'round' ) === 'square' ) ? '4px' : '50%';
		$btn_bg           = ! empty( $btn['bg'] ) ? 'var(--fl-global-' . $this->sanitize_slug( $btn['bg'] ) . ')' : 'transparent';
		$btn_icon         = ! empty( $btn['icon'] ) ? 'var(--fl-global-' . $this->sanitize_slug( $btn['icon'] ) . ')' : 'currentColor';
		$btn_border       = ! empty( $btn['border'] ) ? '1px solid var(--fl-global-' . $this->sanitize_slug( $btn['border'] ) . ')' : 'none';
		$btn_bg_hover     = ! empty( $btn['bg_hover'] ) ? 'var(--fl-global-' . $this->sanitize_slug( $btn['bg_hover'] ) . ')' : $btn_bg;
		$btn_icon_hover   = ! empty( $btn['icon_hover'] ) ? 'var(--fl-global-' . $this->sanitize_slug( $btn['icon_hover'] ) . ')' : $btn_icon;
		$btn_border_hover = ! empty( $btn['border_hover'] ) ? '1px solid var(--fl-global-' . $this->sanitize_slug( $btn['border_hover'] ) . ')' : $btn_border;

		// Build the style block safely — all values sanitized before reaching here.
		$css = '<style id="bb-pro-dm-styles">' . "\n";

		// Light body background.
		if ( ! empty( $saved['bg']['light'] ) ) {
			$slug = $this->sanitize_slug( $saved['bg']['light'] );
			$css .= "body, .fl-page-content { background-color: var(--fl-global-{$slug}) !important; }\n";
		}

		// Dark mode overrides.
		$css .= "body.dark-mode {\n";

		if ( ! empty( $saved['bg']['dark'] ) ) {
			$slug = $this->sanitize_slug( $saved['bg']['dark'] );
			$css .= "  background-color: var(--fl-global-{$slug}) !important;\n";
		}

		foreach ( (array) ( isset( $saved['pairs'] ) ? $saved['pairs'] : array() ) as $pair ) {
			$light = $this->sanitize_slug( isset( $pair['light'] ) ? $pair['light'] : '' );
			$dark  = $this->sanitize_slug( isset( $pair['dark'] ) ? $pair['dark'] : '' );
			if ( '' !== $light && '' !== $dark ) {
				$css .= "  --fl-global-{$light}: var(--fl-global-{$dark}) !important;\n";
			}
		}

		foreach ( (array) ( isset( $saved['vars'] ) ? $saved['vars'] : array() ) as $v ) {
			$custom = isset( $v['custom'] ) ? $v['custom'] : '';
			$dark   = $this->sanitize_slug( isset( $v['dark'] ) ? $v['dark'] : '' );
			// Re-sanitize the stored custom variable name at output time.
			if ( '' !== $custom && '' !== $dark && 0 === strpos( $custom, '--' ) ) {
				$safe_var = '--' . preg_replace( '/[^a-zA-Z0-9\-_]/', '', substr( $custom, 2 ) );
				$css     .= "  {$safe_var}: var(--fl-global-{$dark}) !important;\n";
			}
		}

		$css .= "}\n";

		// Dark mode page-content background.
		if ( ! empty( $saved['bg']['dark'] ) ) {
			$slug = $this->sanitize_slug( $saved['bg']['dark'] );
			$css .= "body.dark-mode .fl-page-content { background-color: var(--fl-global-{$slug}) !important; }\n";
		}

		// Toggle button styles.
		$icon_half = intval( $btn_size / 2 );
		$css      .= "
.bb-dm-toggle {
	cursor: pointer;
	transition: all 0.4s ease;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	border-radius: {$radius};
	width: {$btn_size}px;
	height: {$btn_size}px;
	background: {$btn_bg};
	border: {$btn_border};
	color: {$btn_icon};
	padding: 0;
	outline: none;
	box-shadow: none;
	appearance: none;
	-webkit-appearance: none;
	-webkit-tap-highlight-color: transparent;
}
.bb-dm-toggle:focus          { outline: none; box-shadow: none; }
.bb-dm-toggle:focus:not(:focus-visible) { outline: none; box-shadow: none; }
.bb-dm-toggle:focus-visible  { outline: 3px solid #007cba; outline-offset: 3px; box-shadow: none; }
.bb-dm-toggle:active         { outline: none; box-shadow: none; }
.bb-dm-toggle:hover {
	background: {$btn_bg_hover};
	border: {$btn_border_hover};
	color: {$btn_icon_hover};
}
.bb-dm-toggle svg { width: {$icon_half}px; height: {$icon_half}px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.bb-dm-toggle .moon-icon { display: none; }
body.dark-mode .bb-dm-toggle .sun-icon  { display: none; }
body.dark-mode .bb-dm-toggle .moon-icon { display: block; }
";

		$css .= '</style>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		// All values have been individually sanitized above; we output the block as-is.
		echo $css;
	}

	// -------------------------------------------------------------------------
	// Front-end JS
	// -------------------------------------------------------------------------

	/**
	 * Enqueue frontend toggle script and localize settings.
	 *
	 * @since 3.8.0
	 */
	public function enqueue_assets() {
		$saved = (array) get_option( $this->option_name, array() );
		$sync  = isset( $saved['system_sync'] ) ? 1 : 0;

		wp_enqueue_script(
			$this->script_handle,
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/frontend.js',
			array( 'jquery' ),
			'3.8.0',
			true
		);

		// Pass PHP settings to JS safely.
		wp_localize_script(
			$this->script_handle,
			'bbDarkModeConfig',
			array(
				'systemSync' => $sync,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	/**
	 * Render the dark-mode toggle button via [bb_dark_mode_toggle] shortcode.
	 *
	 * @since  3.8.0
	 * @return string HTML button.
	 */
	public function toggle_shortcode() {
		ob_start();
		?>
		<button class="bb-dm-toggle"
				onclick="bbDarkModeToggle(this)"
				aria-label="Toggle Dark Mode"
				aria-pressed="false"
				type="button">
			<svg class="sun-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
				<circle cx="12" cy="12" r="5"></circle>
				<line x1="12" y1="1"     x2="12" y2="3"></line>
				<line x1="12" y1="21"    x2="12" y2="23"></line>
				<line x1="4.22" y1="4.22"   x2="5.64"  y2="5.64"></line>
				<line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
				<line x1="1"  y1="12"    x2="3"  y2="12"></line>
				<line x1="21" y1="12"    x2="23" y2="12"></line>
				<line x1="4.22" y1="19.78" x2="5.64"  y2="18.36"></line>
				<line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
			</svg>
			<svg class="moon-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
				<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
			</svg>
		</button>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Restrict a string to characters safe for CSS variable names and HTML attrs.
	 *
	 * @since  3.8.0
	 * @param  string $value Raw value.
	 * @return string
	 */
	private function sanitize_slug( $value ) {
		return preg_replace( '/[^a-zA-Z0-9\-_]/', '', $value );
	}

	/**
	 * Validate and return a positive integer, falling back to $default.
	 *
	 * @since  3.8.0
	 * @param  mixed $value   Value to sanitize.
	 * @param  int   $default Fallback.
	 * @param  int   $min     Minimum allowed.
	 * @param  int   $max     Maximum allowed.
	 * @return int
	 */
	private function sanitize_int( $value, $default = 44, $min = 10, $max = 200 ) {
		$int = (int) $value;
		return ( $int >= $min && $int <= $max ) ? $int : $default;
	}
}