<?php
/**
 * BB Custom Dark Mode — Admin Interface
 *
 * Settings page, registration, export/import, and AJAX handler for the
 * global-colour manager.
 *
 * @package BB_Custom_Dark_Mode
 * @since   3.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All admin-facing functionality.
 */
class BBCustomDarkMode_Admin {

	/**
	 * Option name used for the plugin's own settings.
	 *
	 * @since 3.8.0
	 * @var   string
	 */
	private $option_name = 'bb_dark_mode_settings';

	/**
	 * Nonce action name for export.
	 *
	 * @since 3.8.0
	 * @var   string
	 */
	private $export_nonce = 'bb_dm_export_nonce';

	/**
	 * Nonce action name for import.
	 *
	 * @since 3.8.0
	 * @var   string
	 */
	private $import_nonce = 'bb_dm_import_nonce';

	/**
	 * Reference to the colours helper.
	 *
	 * @since 3.8.0
	 * @var   BBCustomDarkMode_Colors
	 */
	private $colors;

	/**
	 * Constructor.
	 *
	 * @since 3.8.0
	 * @param BBCustomDarkMode_Colors $colors_instance Colour-manager instance.
	 */
	public function __construct( $colors_instance ) {
		$this->colors = $colors_instance;

		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_export_import' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// AJAX handler for colour CRUD — both logged-in and no_priv for safety.
		add_action( 'wp_ajax_bb_dm_manage_color', array( $this, 'ajax_manage_color' ) );
	}

	// -------------------------------------------------------------------------
	// Settings Registration & Sanitization
	// -------------------------------------------------------------------------

	/**
	 * Add the options page under Settings.
	 *
	 * @since 3.8.0
	 */
	public function add_settings_page() {
		add_options_page(
			'BB Dark Mode',
			'BB Dark Mode',
			'manage_options',
			'bb-dark-mode',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register the plugin setting, attaching the sanitizer.
	 *
	 * @since 3.8.0
	 */
	public function register_settings() {
		register_setting(
			'bb_dark_mode_group',
			$this->option_name,
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitize every field before it is stored in the database.
	 *
	 * This is the primary defence layer — called automatically by the
	 * Settings API on save, and manually on import.
	 *
	 * @since  3.8.0
	 * @param  mixed $raw Raw input (typically an array from $_POST).
	 * @return array      Sanitized settings ready for update_option().
	 */
	public function sanitize_settings( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();

		// -- Background mapping -------------------------------------------
		$clean['bg']['light'] = $this->sanitize_slug( isset( $raw['bg']['light'] ) ? $raw['bg']['light'] : '' );
		$clean['bg']['dark']  = $this->sanitize_slug( isset( $raw['bg']['dark'] ) ? $raw['bg']['dark'] : '' );

		// -- Global colour pairs ------------------------------------------
		$clean['pairs'] = array();
		foreach ( (array) ( isset( $raw['pairs'] ) ? $raw['pairs'] : array() ) as $pair ) {
			$clean['pairs'][] = array(
				'light' => $this->sanitize_slug( isset( $pair['light'] ) ? $pair['light'] : '' ),
				'dark'  => $this->sanitize_slug( isset( $pair['dark'] ) ? $pair['dark'] : '' ),
			);
		}
		if ( empty( $clean['pairs'] ) ) {
			$clean['pairs'] = array(
				array(
					'light' => '',
					'dark'  => '',
				),
			);
		}

		// -- CSS variable bridges -----------------------------------------
		$clean['vars'] = array();
		foreach ( (array) ( isset( $raw['vars'] ) ? $raw['vars'] : array() ) as $v ) {
			$raw_var = isset( $v['custom'] ) ? trim( $v['custom'] ) : '';
			// Ensure it starts with -- and only contains safe characters.
			if ( '' !== $raw_var ) {
				$raw_var = ( 0 === strpos( $raw_var, '--' ) ) ? $raw_var : '--' . $raw_var;
				$raw_var = '--' . preg_replace( '/[^a-zA-Z0-9\-_]/', '', substr( $raw_var, 2 ) );
			}
			$clean['vars'][] = array(
				'custom' => $raw_var,
				'dark'   => $this->sanitize_slug( isset( $v['dark'] ) ? $v['dark'] : '' ),
			);
		}
		if ( empty( $clean['vars'] ) ) {
			$clean['vars'] = array(
				array(
					'custom' => '',
					'dark'   => '',
				),
			);
		}

		// -- System preference sync ---------------------------------------
		$clean['system_sync'] = isset( $raw['system_sync'] ) ? 1 : 0;

		// -- Exclusions ---------------------------------------------------
		$allowed_pt              = array_keys( get_post_types( array( 'public' => true ) ) );
		$clean['excluded_types'] = array_filter(
			(array) ( isset( $raw['excluded_types'] ) ? $raw['excluded_types'] : array() ),
			function ( $pt ) use ( $allowed_pt ) {
				return in_array( $pt, $allowed_pt, true );
			}
		);

		// IDs: accept only comma-separated integers.
		$raw_ids               = isset( $raw['excluded_ids'] ) ? $raw['excluded_ids'] : '';
		$id_parts              = array_map( 'trim', explode( ',', $raw_ids ) );
		$clean['excluded_ids'] = implode(
			', ',
			array_filter( array_map( 'intval', $id_parts ) )
		);

		// -- Button styling -----------------------------------------------
		$allowed_shapes = array( 'round', 'square' );
		$clean['btn']   = array(
			'size'         => $this->sanitize_int( isset( $raw['btn']['size'] ) ? $raw['btn']['size'] : 44 ),
			'shape'        => in_array( isset( $raw['btn']['shape'] ) ? $raw['btn']['shape'] : '', $allowed_shapes, true )
								? $raw['btn']['shape']
								: 'round',
			'bg'           => $this->sanitize_slug( isset( $raw['btn']['bg'] ) ? $raw['btn']['bg'] : '' ),
			'icon'         => $this->sanitize_slug( isset( $raw['btn']['icon'] ) ? $raw['btn']['icon'] : '' ),
			'border'       => $this->sanitize_slug( isset( $raw['btn']['border'] ) ? $raw['btn']['border'] : '' ),
			'bg_hover'     => $this->sanitize_slug( isset( $raw['btn']['bg_hover'] ) ? $raw['btn']['bg_hover'] : '' ),
			'icon_hover'   => $this->sanitize_slug( isset( $raw['btn']['icon_hover'] ) ? $raw['btn']['icon_hover'] : '' ),
			'border_hover' => $this->sanitize_slug( isset( $raw['btn']['border_hover'] ) ? $raw['btn']['border_hover'] : '' ),
		);

		return $clean;
	}

	// -------------------------------------------------------------------------
	// Admin Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueue CSS and JS on the plugin's settings page only.
	 *
	 * @since 3.8.0
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'settings_page_bb-dark-mode' !== $hook ) {
			return;
		}

		// Stylesheet.
		wp_enqueue_style(
			'bb-dark-mode-admin',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/admin.css',
			array( 'wp-color-picker' ),
			'3.9.0'
		);

		// WordPress color picker styles (Iris).
		wp_enqueue_style( 'wp-color-picker' );

		// WordPress color picker script (Iris).
		wp_enqueue_script( 'wp-color-picker' );

		// Alpha-channel extension for wp-color-picker.
		wp_enqueue_script(
			'bb-dark-mode-color-picker-alpha',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/wp-color-picker-alpha.min.js',
			array( 'wp-color-picker' ),
			'3.9.0',
			true
		);

		// Admin script.
		wp_enqueue_script(
			'bb-dark-mode-admin',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/admin.js',
			array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker', 'bb-dark-mode-color-picker-alpha' ),
			'3.9.0',
			true
		);

		wp_localize_script(
			'bb-dark-mode-admin',
			'bbDarkModeAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'bb_dm_color_nonce' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Settings Page Render
	// -------------------------------------------------------------------------

	/**
	 * Output the full settings page HTML.
	 *
	 * @since 3.8.0
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'bb-dark-mode' ) );
		}

		$colors     = $this->colors->get_bb_colors();
		$saved      = (array) get_option( $this->option_name, array() );
		$pairs      = isset( $saved['pairs'] ) ? (array) $saved['pairs'] : array( array( 'light' => '', 'dark' => '' ) );
		$vars       = isset( $saved['vars'] ) ? (array) $saved['vars'] : array( array( 'custom' => '', 'dark' => '' ) );
		$bg         = isset( $saved['bg'] ) ? $saved['bg'] : array( 'light' => '', 'dark' => '' );
		$btn        = array_merge(
			array(
				'size'         => '44',
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
		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		// Export nonce URL.
		$export_url = wp_nonce_url(
			admin_url( 'options-general.php?page=bb-dark-mode&bb_dm_export=1' ),
			'bb_dm_export_action',
			$this->export_nonce
		);

		// Helper to output a colour dropdown with saved value.
		$render_select = function ( $name, $selected_slug, $default_label, $show_colors = true ) use ( $colors ) {
			?>
			<select name="<?php echo esc_attr( $name ); ?>" class="bb-color-select">
				<option value="" data-color="">
					<?php echo esc_html( $default_label ); ?>
				</option>
				<?php if ( $show_colors ) : ?>
					<option value="" data-color="">&hellip;</option>
					<?php foreach ( $colors as $c ) : ?>
						<option value="<?php echo esc_attr( $c['slug'] ); ?>" data-color="<?php echo esc_attr( $c['color'] ); ?>"
							<?php selected( $c['slug'], $selected_slug ); ?>>
							<?php echo esc_html( $c['name'] ); ?>
						</option>
					<?php endforeach; ?>
				<?php endif; ?>
			</select>
			<?php
		};
		?>
		<div class="wrap bbdm-wrap">
			<h1>BB Custom Dark Mode Pro</h1>

			<?php if ( empty( $colors ) ) : ?>
				<div class="bb-dm-notice">
					<strong>Notice:</strong> No Beaver Builder global colours were detected.
					Colour mapping dropdowns will be empty. Ensure Beaver Builder is active
					and global colours are configured under <em>Global Styles</em>.
				</div>
			<?php endif; ?>

			<!-- Horizontal Tab Navigation -->
			<div class="bbdm-tabs-nav">
				<button type="button" class="bbdm-tab-link active" data-tab="tab-global-colours">BB Global Colours</button>
				<button type="button" class="bbdm-tab-link" data-tab="tab-colour-mapping">Colour Mapping</button>
				<button type="button" class="bbdm-tab-link" data-tab="tab-settings-styling">Settings & Styling</button>
			</div>

			<form method="post" action="options.php" style="margin:0;padding:0;">
				<?php settings_fields( 'bb_dark_mode_group' ); ?>

				<!-- ── TAB 1: BB GLOBAL COLOURS ── -->
				<div id="tab-global-colours" class="bbdm-tab-panel active">
					<div class="bb-dm-card bbdm-section">
						<h2>Global Colours Manager</h2>
						<p class="description">
							Add, edit, or delete global colours directly from this page.
							Changes are synced immediately with Beaver Builder's Global Styles.
						</p>

						<!-- Add colour form -->
						<div class="bb-dm-color-form">
							<div class="form-field form-field-name">
								<label for="bb-dm-new-color-name">Name</label>
								<input type="text" id="bb-dm-new-color-name" placeholder="e.g. Primary Blue" class="regular-text">
							</div>
							<div class="form-field form-field-color">
								<label for="bb-dm-new-color-hex">Colour</label>
								<input type="text" id="bb-dm-new-color-hex" value="#ffffff" class="bb-dm-iris-picker" data-alpha-enabled="true" data-default-color="#ffffff">
								<p class="description">Click the swatch to open the colour picker, or type any CSS colour value (hex, rgb, rgba, hsl).</p>
							</div>
							<div class="form-field form-field-slug">
								<label for="bb-dm-new-color-slug">Slug <em>(optional)</em></label>
								<input type="text" id="bb-dm-new-color-slug" placeholder="auto-generated" class="regular-text">
							</div>
							<div class="form-field form-field-actions">
								<label class="invisible-label">&nbsp;</label>
								<div class="actions-row">
									<button type="button" id="bb-dm-add-color-btn" class="button button-primary">
										Add Global Colour
									</button>
									<span id="bb-dm-color-spinner" class="spinner bb-dm-spinner"></span>
								</div>
							</div>
						</div>

						<!-- Existing colours table -->
						<table class="bb-dm-colors-table">
							<thead>
								<tr>
									<th>Swatch</th>
									<th>Name</th>
									<th>Slug</th>
									<th>Hex</th>
									<th>Actions</th>
								</tr>
							</thead>
							<tbody id="bb-dm-colors-tbody">
								<?php if ( empty( $colors ) ) : ?>
									<tr class="no-items">
										<td colspan="5">No global colours yet. Add one above.</td>
									</tr>
								<?php else : ?>
									<?php foreach ( $colors as $c ) : ?>
										<tr data-slug="<?php echo esc_attr( $c['slug'] ); ?>">
											<td>
												<span class="color-swatch" style="background-color:<?php echo esc_attr( $c['color'] ); ?>"></span>
											</td>
											<td class="col-name"><?php echo esc_html( $c['name'] ); ?></td>
											<td class="col-slug"><code><?php echo esc_html( $c['slug'] ); ?></code></td>
											<td class="col-hex"><?php echo esc_html( $c['color'] ); ?></td>
											<td class="row-actions">
												<button type="button" class="bb-dm-edit-color" data-slug="<?php echo esc_attr( $c['slug'] ); ?>">Edit</button> |
												<button type="button" class="bb-dm-delete-color" data-slug="<?php echo esc_attr( $c['slug'] ); ?>">Delete</button>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php endif; ?>
							</tbody>
						</table>
					</div>
				</div>

				<!-- ── TAB 2: COLOUR MAPPING ── -->
				<div id="tab-colour-mapping" class="bbdm-tab-panel">
					<!-- Site Background Mapping -->
					<div class="bb-dm-card bbdm-section">
						<h2>Site Background Mapping</h2>
						<div class="bg-map-box">
							<div>
								<span class="bb-swatch"></span>
								<?php
								$render_select(
									$this->option_name . '[bg][light]',
									isset( $bg['light'] ) ? $bg['light'] : '',
									'Light BG…'
								);
								?>
							</div>
							<span>&rarr;</span>
							<div>
								<span class="bb-swatch"></span>
								<?php
								$render_select(
									$this->option_name . '[bg][dark]',
									isset( $bg['dark'] ) ? $bg['dark'] : '',
									'Dark BG…'
								);
								?>
							</div>
						</div>
					</div>

					<!-- Global Colour Mapping -->
					<div class="bb-dm-card bbdm-section">
						<h2>Global Colour Mapping</h2>
						<div id="bb-repeater" class="color-container">
							<?php foreach ( $pairs as $i => $pair ) : ?>
								<div class="bb-dm-row is-sortable">
									<span class="bb-dm-drag-handle" title="Drag to reorder" aria-hidden="true">&#8597;</span>
									<div>
										<span class="bb-swatch"></span>
										<?php
										$render_select(
											$this->option_name . '[pairs][' . intval( $i ) . '][light]',
											isset( $pair['light'] ) ? $pair['light'] : '',
											'Select Light…'
										);
										?>
									</div>
									<span>&rarr;</span>
									<div>
										<span class="bb-swatch"></span>
										<?php
										$render_select(
											$this->option_name . '[pairs][' . intval( $i ) . '][dark]',
											isset( $pair['dark'] ) ? $pair['dark'] : '',
											'Select Dark…'
										);
										?>
									</div>
									<button type="button" class="bb-dm-remove">Remove</button>
								</div>
							<?php endforeach; ?>
						</div>
						<button type="button" class="button add-row-btn" data-target="bb-repeater">+ Add Pair</button>
					</div>

					<!-- Manual CSS Variable Bridge -->
					<div class="bb-dm-card bbdm-section">
						<h2>Manual CSS Variable Bridge</h2>
						<div id="var-repeater" class="color-container">
							<?php foreach ( $vars as $i => $v ) : ?>
								<div class="bb-dm-row">
									<input type="text"
										name="<?php echo esc_attr( $this->option_name ); ?>[vars][<?php echo intval( $i ); ?>][custom]"
										value="<?php echo esc_attr( isset( $v['custom'] ) ? $v['custom'] : '' ); ?>"
										placeholder="--variable-name"
										class="regular-text">
									<span>&rarr;</span>
									<div>
										<span class="bb-swatch"></span>
										<?php
										$render_select(
											$this->option_name . '[vars][' . intval( $i ) . '][dark]',
											isset( $v['dark'] ) ? $v['dark'] : '',
											'Target Dark…'
										);
										?>
									</div>
									<button type="button" class="bb-dm-remove">Remove</button>
								</div>
							<?php endforeach; ?>
						</div>
						<button type="button" class="button add-row-btn" data-target="var-repeater">+ Add Bridge</button>
					</div>
				</div>

				<!-- ── TAB 3: SETTINGS & STYLING ── -->
				<div id="tab-settings-styling" class="bbdm-tab-panel">
					<!-- Settings & Exclusions -->
					<div class="bb-dm-card bbdm-section">
						<h2>Settings & Exclusions</h2>
						<label style="display:block;margin-bottom:15px;">
							<input type="checkbox"
								name="<?php echo esc_attr( $this->option_name ); ?>[system_sync]"
								value="1"
								<?php checked( isset( $saved['system_sync'] ) ? $saved['system_sync'] : 0, 1 ); ?>>
							Enable System Preference Sync
						</label>
						<table class="form-table">
							<tr>
								<th>Exclude Post Types</th>
								<td>
									<?php foreach ( $post_types as $pt ) : ?>
										<label style="display:block;margin-bottom:5px;">
											<input type="checkbox"
												name="<?php echo esc_attr( $this->option_name ); ?>[excluded_types][]"
												value="<?php echo esc_attr( $pt->name ); ?>"
												<?php checked( in_array( $pt->name, (array) ( isset( $saved['excluded_types'] ) ? $saved['excluded_types'] : array() ), true ), true ); ?>>
											<?php echo esc_html( $pt->label ); ?>
										</label>
									<?php endforeach; ?>
								</td>
							</tr>
							<tr>
								<th>Exclude by IDs</th>
								<td>
									<input type="text"
										name="<?php echo esc_attr( $this->option_name ); ?>[excluded_ids]"
										value="<?php echo esc_attr( isset( $saved['excluded_ids'] ) ? $saved['excluded_ids'] : '' ); ?>"
										placeholder="e.g. 12, 45"
										class="regular-text">
								</td>
							</tr>
						</table>
					</div>

					<!-- Toggle Button Styling -->
					<div class="bb-dm-card bbdm-section">
						<h2>Toggle Button Styling</h2>
						<table class="form-table">
							<tr>
								<th>Shape</th>
								<td>
									<select name="<?php echo esc_attr( $this->option_name ); ?>[btn][shape]">
										<option value="round"  <?php selected( $btn['shape'], 'round' ); ?>>Round</option>
										<option value="square" <?php selected( $btn['shape'], 'square' ); ?>>Square</option>
									</select>
								</td>
							</tr>
							<tr>
								<th>Size (px)</th>
								<td>
									<input type="number"
										name="<?php echo esc_attr( $this->option_name ); ?>[btn][size]"
										value="<?php echo esc_attr( $btn['size'] ); ?>"
										min="10" max="200"
										class="small-text">
								</td>
							</tr>
							<tr>
								<th>Background</th>
								<td>
									<span class="bb-swatch"></span>
									<?php
									$render_select(
										$this->option_name . '[btn][bg]',
										$btn['bg'],
										'Transparent'
									);
									?>
								</td>
							</tr>
							<tr>
								<th>Icon Colour</th>
								<td>
									<span class="bb-swatch"></span>
									<?php
									$render_select(
										$this->option_name . '[btn][icon]',
										$btn['icon'],
										'Default'
									);
									?>
								</td>
							</tr>
							<tr>
								<th>Border Colour</th>
								<td>
									<span class="bb-swatch"></span>
									<?php
									$render_select(
										$this->option_name . '[btn][border]',
										$btn['border'],
										'None'
									);
									?>
								</td>
							</tr>
							<tr><td colspan="2"><hr style="border:none;border-top:1px solid #ccd0d4;margin:4px 0;"></td></tr>
							<tr>
								<th>Background <em>(hover)</em></th>
								<td>
									<span class="bb-swatch"></span>
									<?php
									$render_select(
										$this->option_name . '[btn][bg_hover]',
										$btn['bg_hover'],
										'Transparent'
									);
									?>
								</td>
							</tr>
							<tr>
								<th>Icon Colour <em>(hover)</em></th>
								<td>
									<span class="bb-swatch"></span>
									<?php
									$render_select(
										$this->option_name . '[btn][icon_hover]',
										$btn['icon_hover'],
										'Default'
									);
									?>
								</td>
							</tr>
							<tr>
								<th>Border Colour <em>(hover)</em></th>
								<td>
									<span class="bb-swatch"></span>
									<?php
									$render_select(
										$this->option_name . '[btn][border_hover]',
										$btn['border_hover'],
										'None'
									);
									?>
								</td>
							</tr>
						</table>
					</div>
				</div>

				<div class="bbdm-settings-submit-wrapper">
					<?php submit_button(); ?>
				</div>
			</form>

			<!-- Export / Import — below the tab group -->
			<div class="bb-dm-card">
				<h2>Export / Import Settings</h2>
				<p>
					<a href="<?php echo esc_url( $export_url ); ?>" class="button">
						Export Settings (JSON)
					</a>
				</p>
				<form method="post" enctype="multipart/form-data" action="">
					<?php wp_nonce_field( 'bb_dm_import_action', $this->import_nonce ); ?>
					<input type="file" name="bb_dm_import_file" accept=".json" required>
					<button type="submit" name="bb_dm_import_submit" class="button" style="margin-left:8px;">
						Import Settings
					</button>
				</form>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Export / Import
	// -------------------------------------------------------------------------

	/**
	 * Handle JSON export and import of plugin settings.
	 *
	 * @since 3.8.0
	 */
	public function handle_export_import() {

		// --- Export -------------------------------------------------------
		if ( isset( $_GET['bb_dm_export'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Unauthorized.', 'bb-dark-mode' ) );
			}
			if ( ! isset( $_GET[ $this->export_nonce ] ) ||
				! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET[ $this->export_nonce ] ) ), 'bb_dm_export_action' )
			) {
				wp_die( esc_html__( 'Security check failed.', 'bb-dark-mode' ) );
			}

			$data = get_option( $this->option_name, array() );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="bb-dm-settings.json"' );
			header( 'Cache-Control: no-cache, must-revalidate' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo wp_json_encode( $data, JSON_PRETTY_PRINT );
			exit;
		}

		// --- Import -------------------------------------------------------
		if ( isset( $_POST['bb_dm_import_submit'] ) ) {
			if ( ! isset( $_GET['page'] ) || 'bb-dark-mode' !== $_GET['page'] ) {
				return;
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Unauthorized.', 'bb-dark-mode' ) );
			}
			if ( ! isset( $_POST[ $this->import_nonce ] ) ||
				! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $this->import_nonce ] ) ), 'bb_dm_import_action' )
			) {
				wp_die( esc_html__( 'Security check failed.', 'bb-dark-mode' ) );
			}

			$file = isset( $_FILES['bb_dm_import_file'] ) ? $_FILES['bb_dm_import_file'] : null;

			if ( ! $file || UPLOAD_ERR_OK !== $file['error'] || empty( $file['tmp_name'] ) ) {
				add_settings_error( $this->option_name, 'import_error', 'Import failed: upload error.', 'error' );
				return;
			}

			$ext = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );
			if ( 'json' !== $ext ) {
				add_settings_error( $this->option_name, 'import_type', 'Import failed: only .json files are accepted.', 'error' );
				return;
			}

			if ( $file['size'] > 524288 ) {
				add_settings_error( $this->option_name, 'import_size', 'Import failed: file exceeds 512 KB limit.', 'error' );
				return;
			}

			$raw  = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
			$data = json_decode( $raw, true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
				add_settings_error( $this->option_name, 'import_json', 'Import failed: invalid JSON.', 'error' );
				return;
			}

			update_option( $this->option_name, $this->sanitize_settings( $data ) );
			add_settings_error( $this->option_name, 'import_ok', 'Settings imported successfully.', 'updated' );
		}
	}

	// -------------------------------------------------------------------------
	// AJAX: Global Colour CRUD
	// -------------------------------------------------------------------------

	/**
	 * Handle AJAX requests for colour management.
	 *
	 * Expected POST:
	 * - action = bb_dm_manage_color
	 * - crud   = add | edit | delete
	 * - data   = { name, color, slug, ... }
	 * - nonce  = wp_create_nonce( 'bb_dm_color_nonce' )
	 *
	 * @since 3.8.0
	 */
	public function ajax_manage_color() {
		// Nonce check.
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'bb_dm_color_nonce' ) ) {
			wp_send_json_error(
				array( 'message' => 'Security check failed.' ),
				403
			);
		}

		// Capability check.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => 'You do not have permission to perform this action.' ),
				403
			);
		}

		// Validate CRUD action.
		$crud = isset( $_POST['crud'] ) ? sanitize_text_field( wp_unslash( $_POST['crud'] ) ) : '';
		$data = isset( $_POST['data'] ) ? (array) $_POST['data'] : array();

		if ( ! in_array( $crud, array( 'add', 'edit', 'delete' ), true ) ) {
			wp_send_json_error(
				array( 'message' => 'Invalid action.' ),
				400
			);
		}

		// Sanitize incoming data.
		$name  = isset( $data['name'] ) ? sanitize_text_field( wp_unslash( $data['name'] ) ) : '';
		$color = isset( $data['color'] ) ? sanitize_text_field( wp_unslash( $data['color'] ) ) : '';
		$slug  = isset( $data['slug'] ) ? sanitize_text_field( wp_unslash( $data['slug'] ) ) : '';

		$result = array();

		switch ( $crud ) {
			case 'add':
				if ( '' === $name || '' === $color ) {
					wp_send_json_error(
						array( 'message' => 'Name and colour are required.' ),
						400
					);
				}
				$result = $this->colors->add_color( $name, $color, $slug );
				break;

			case 'edit':
				if ( '' === $slug ) {
					wp_send_json_error(
						array( 'message' => 'Slug is required for editing.' ),
						400
					);
				}
				$updates = array();
				if ( '' !== $name ) {
					$updates['name'] = $name;
				}
				if ( '' !== $color ) {
					$updates['color'] = $color;
				}
				$result = $this->colors->update_color( $slug, $updates );
				break;

			case 'delete':
				if ( '' === $slug ) {
					wp_send_json_error(
						array( 'message' => 'Slug is required for deletion.' ),
						400
					);
				}
				$result = $this->colors->delete_color( $slug );
				break;
		}

		if ( false === $result ) {
			wp_send_json_error(
				array( 'message' => 'Operation failed. The colour may already exist, or the slug was not found.' ),
				400
			);
		}

		wp_send_json_success(
			array(
				'colors'  => $result,
				'message' => 'Colour updated successfully.',
			)
		);
	}

	// -------------------------------------------------------------------------
	// Utility Helpers
	// -------------------------------------------------------------------------

	/**
	 * Restrict a slug to characters safe for CSS variable names and HTML attrs.
	 *
	 * @since  3.8.0
	 * @param  string $value Raw slug.
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
	 * @param  int   $default Fallback value.
	 * @param  int   $min     Minimum allowed.
	 * @param  int   $max     Maximum allowed.
	 * @return int
	 */
	private function sanitize_int( $value, $default = 44, $min = 10, $max = 200 ) {
		$int = (int) $value;
		return ( $int >= $min && $int <= $max ) ? $int : $default;
	}
}