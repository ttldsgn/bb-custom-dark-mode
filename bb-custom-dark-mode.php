<?php
/**
 * Plugin Name: BB Custom Dark Mode (v3.5 - Pro)
 * Description: Pro-grade Dark Mode engine for Beaver Builder. Full mapping, Exclusions, and Strict Accessibility.
 * Version: 3.6.2
 * Author: ttldsgn
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BBCustomDarkMode {

    private $option_name    = 'bb_dark_mode_settings';
    private $export_nonce   = 'bb_dm_export_nonce';
    private $import_nonce   = 'bb_dm_import_nonce';
    private $script_handle  = 'bb-dark-mode-frontend';

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public function __construct() {
        add_action( 'admin_menu',          [ $this, 'add_settings_page' ] );
        add_action( 'admin_init',          [ $this, 'register_settings' ] );
        add_action( 'admin_init',          [ $this, 'handle_export_import' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_head',             [ $this, 'inject_dynamic_css' ], 100 );
        add_action( 'wp_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
        add_shortcode( 'bb_dark_mode_toggle', [ $this, 'toggle_shortcode' ] );
    }

    // -------------------------------------------------------------------------
    // Beaver Builder colour helpers
    // -------------------------------------------------------------------------

    /**
     * Pull the global colour palette from Beaver Builder, if available.
     *
     * @return array[] Array of [ 'name', 'slug', 'color' ] maps.
     */
    private function get_bb_colors(): array {
        $color_list = [];

        if ( ! class_exists( 'FLBuilderGlobalStyles' ) ) {
            return $color_list;
        }

        $settings = FLBuilderGlobalStyles::get_settings();
        $items    = $settings->colors->items
            ?? $settings->colors
            ?? [];

        foreach ( (array) $items as $item ) {
            $data      = (array) $item;
            $name      = $data['name']  ?? $data['label'] ?? '';
            $color_val = $data['color'] ?? $data['hex']   ?? '';

            if ( empty( $name ) ) {
                continue;
            }

            $raw_slug = $data['slug'] ?? sanitize_title( $name );

            $color_list[] = [
                'name'  => esc_html( $name ),
                'slug'  => $this->sanitize_slug( $raw_slug ),
                'color' => esc_attr( $color_val ),
            ];
        }

        return $color_list;
    }

    /**
     * Restrict a slug to characters safe for CSS variable names and HTML attrs.
     */
    private function sanitize_slug( string $value ): string {
        return preg_replace( '/[^a-zA-Z0-9\-_]/', '', $value );
    }

    /**
     * Validate and return a positive integer, falling back to $default.
     */
    private function sanitize_int( $value, int $default = 44, int $min = 10, int $max = 200 ): int {
        $int = (int) $value;
        return ( $int >= $min && $int <= $max ) ? $int : $default;
    }

    // -------------------------------------------------------------------------
    // Settings registration & sanitization
    // -------------------------------------------------------------------------

    public function add_settings_page(): void {
        add_options_page(
            'BB Dark Mode',
            'BB Dark Mode',
            'manage_options',
            'bb-dark-mode',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings(): void {
        register_setting(
            'bb_dark_mode_group',
            $this->option_name,
            [ 'sanitize_callback' => [ $this, 'sanitize_settings' ] ]
        );
    }

    /**
     * Sanitize every field before it is stored in the database.
     * This is the primary defence layer — called automatically by the
     * Settings API on save, and manually on import.
     *
     * @param  mixed $raw Raw input (typically an array from $_POST).
     * @return array      Sanitized settings ready for update_option().
     */
    public function sanitize_settings( $raw ): array {
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $clean = [];

        // -- Background mapping -------------------------------------------
        $clean['bg']['light'] = $this->sanitize_slug( $raw['bg']['light'] ?? '' );
        $clean['bg']['dark']  = $this->sanitize_slug( $raw['bg']['dark']  ?? '' );

        // -- Global colour pairs ------------------------------------------
        $clean['pairs'] = [];
        foreach ( (array) ( $raw['pairs'] ?? [] ) as $pair ) {
            $clean['pairs'][] = [
                'light' => $this->sanitize_slug( $pair['light'] ?? '' ),
                'dark'  => $this->sanitize_slug( $pair['dark']  ?? '' ),
            ];
        }
        if ( empty( $clean['pairs'] ) ) {
            $clean['pairs'] = [ [ 'light' => '', 'dark' => '' ] ];
        }

        // -- CSS variable bridges -----------------------------------------
        $clean['vars'] = [];
        foreach ( (array) ( $raw['vars'] ?? [] ) as $v ) {
            $raw_var = trim( $v['custom'] ?? '' );
            // Ensure it starts with -- and only contains safe characters.
            if ( $raw_var !== '' ) {
                $raw_var = ( strpos( $raw_var, '--' ) === 0 ) ? $raw_var : '--' . $raw_var;
                $raw_var = '--' . preg_replace( '/[^a-zA-Z0-9\-_]/', '', substr( $raw_var, 2 ) );
            }
            $clean['vars'][] = [
                'custom' => $raw_var,
                'dark'   => $this->sanitize_slug( $v['dark'] ?? '' ),
            ];
        }
        if ( empty( $clean['vars'] ) ) {
            $clean['vars'] = [ [ 'custom' => '', 'dark' => '' ] ];
        }

        // -- System preference sync ---------------------------------------
        $clean['system_sync'] = isset( $raw['system_sync'] ) ? 1 : 0;

        // -- Exclusions ---------------------------------------------------
        $allowed_pt             = array_keys( get_post_types( [ 'public' => true ] ) );
        $clean['excluded_types'] = array_filter(
            (array) ( $raw['excluded_types'] ?? [] ),
            fn( $pt ) => in_array( $pt, $allowed_pt, true )
        );

        // IDs: accept only comma-separated integers.
        $raw_ids              = $raw['excluded_ids'] ?? '';
        $id_parts             = array_map( 'trim', explode( ',', $raw_ids ) );
        $clean['excluded_ids'] = implode(
            ', ',
            array_filter( array_map( 'intval', $id_parts ) )
        );

        // -- Button styling -----------------------------------------------
        $allowed_shapes = [ 'round', 'square' ];
        $clean['btn'] = [
            'size'         => $this->sanitize_int( $raw['btn']['size'] ?? 44 ),
            'shape'        => in_array( $raw['btn']['shape'] ?? '', $allowed_shapes, true )
                                  ? $raw['btn']['shape']
                                  : 'round',
            'bg'           => $this->sanitize_slug( $raw['btn']['bg']           ?? '' ),
            'icon'         => $this->sanitize_slug( $raw['btn']['icon']         ?? '' ),
            'border'       => $this->sanitize_slug( $raw['btn']['border']       ?? '' ),
            'bg_hover'     => $this->sanitize_slug( $raw['btn']['bg_hover']     ?? '' ),
            'icon_hover'   => $this->sanitize_slug( $raw['btn']['icon_hover']   ?? '' ),
            'border_hover' => $this->sanitize_slug( $raw['btn']['border_hover'] ?? '' ),
        ];

        return $clean;
    }

    // -------------------------------------------------------------------------
    // Admin assets (properly enqueued — no more raw admin_head injection)
    // -------------------------------------------------------------------------

    public function enqueue_admin_assets( string $hook ): void {
        if ( $hook !== 'settings_page_bb-dark-mode' ) {
            return;
        }

        // Inline styles — scoped to the plugin page only.
        $admin_css = '
            .bb-dm-row { background:#fff; border:1px solid #ccd0d4; padding:15px; margin-bottom:10px; display:flex; align-items:center; gap:15px; border-radius:4px; }
            .bb-swatch  { width:24px; height:24px; border-radius:4px; border:1px solid #ccc; display:inline-block; vertical-align:middle; background:#eee; }
            .bb-dm-remove { color:#d63638; cursor:pointer; text-decoration:underline; font-size:12px; margin-left:auto; }
            .bb-dm-card { background:#f6f7f7; border:1px solid #ccd0d4; padding:20px; border-radius:8px; margin-bottom:20px; }
            .bb-dm-card h2 { margin-top:0; border-bottom:1px solid #ccd0d4; padding-bottom:10px; margin-bottom:20px; }
            .bg-map-box { display:flex; align-items:center; gap:10px; background:#fff; padding:10px; border:1px solid #ccd0d4; border-radius:4px; max-width:fit-content; }
            .bb-dm-notice { background:#fff3cd; border-left:4px solid #ffc107; padding:10px 15px; margin-bottom:15px; border-radius:2px; }
        ';
        wp_add_inline_style( 'wp-admin', $admin_css );

        // Register a dedicated admin script handle in the footer so the DOM
        // is fully available when our code runs (avoids the head-execution
        // timing issue that caused swatches to show grey on page load).
        wp_register_script(
            'bb-dark-mode-admin',
            false,        // no external file
            [ 'jquery' ],
            '3.5',
            true          // footer = true
        );
        wp_enqueue_script( 'bb-dark-mode-admin' );

        $admin_js = '
            (function($) {
                "use strict";

                /**
                 * Given a raw color string, return something the browser can
                 * use as a CSS background-color value.
                 */
                function formatColor(val) {
                    if (!val) return "";
                    val = val.trim();
                    return (val.indexOf("rgb") !== -1 || val.indexOf("#") === 0) ? val : "#" + val;
                }

                /**
                 * Update the nearest .bb-swatch sibling/ancestor for a given
                 * <select>.  The swatch may be:
                 *   (a) a sibling <span> inside the same <td> or <div>, or
                 *   (b) a <span> in a wrapping parent element.
                 * We walk up to the closest block-level container (.bb-dm-row,
                 * td, or .bg-map-box) and find the first .bb-swatch within it
                 * that comes before this select — matching the original logic.
                 */
                function updateSwatch(selectElement) {
                    var $select  = $(selectElement);
                    var colorVal = $select.find(":selected").attr("data-color");
                    var color    = colorVal ? formatColor(colorVal) : "#eeeeee";

                    // Each <select> lives inside its own <div> (or <td>) together
                    // with exactly one .bb-swatch. Go up one level to that immediate
                    // parent so we only touch the swatch paired with THIS dropdown,
                    // never the one belonging to the sibling Light/Dark column.
                    $select.parent().find(".bb-swatch").css("background-color", color);
                }

                $(function() {
                    // Initialise all swatches from saved/selected values on page load.
                    $(".bb-color-select").each(function() { updateSwatch(this); });

                    // Live-update when the user picks a new colour.
                    $(document).on("change", ".bb-color-select", function() { updateSwatch(this); });

                    // Add a new repeater row (clone first row, reset its values).
                    $(".add-row-btn").on("click", function() {
                        var target    = $(this).data("target");
                        var $container = $("#" + target);
                        var $firstRow = $container.find(".bb-dm-row").first();
                        if (!$firstRow.length) return;

                        var $row  = $firstRow.clone();
                        var index = $container.find(".bb-dm-row").length;

                        $row.find("input, select").val("").each(function() {
                            var n = $(this).attr("name");
                            if (n) $(this).attr("name", n.replace(/\[\d+\]/, "[" + index + "]"));
                        });
                        $row.find(".bb-swatch").css("background-color", "#eeeeee");
                        $container.append($row);
                    });

                    // Remove a row — always keep at least one.
                    $(document).on("click", ".bb-dm-remove", function() {
                        var $container = $(this).closest(".color-container");
                        if ($container.find(".bb-dm-row").length > 1) {
                            $(this).closest(".bb-dm-row").remove();
                        }
                    });
                });

            }(jQuery));
        ';
        wp_add_inline_script( 'bb-dark-mode-admin', $admin_js );
    }

    // -------------------------------------------------------------------------
    // Admin settings page render
    // -------------------------------------------------------------------------

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'bb-dark-mode' ) );
        }

        $colors     = $this->get_bb_colors();
        $saved      = (array) get_option( $this->option_name, [] );
        $pairs      = isset( $saved['pairs'] )  ? (array) $saved['pairs']  : [ [ 'light' => '', 'dark' => '' ] ];
        $vars       = isset( $saved['vars'] )   ? (array) $saved['vars']   : [ [ 'custom' => '', 'dark' => '' ] ];
        $bg         = $saved['bg']  ?? [ 'light' => '', 'dark' => '' ];
        $btn        = array_merge(
            [ 'size' => '44', 'shape' => 'round', 'bg' => '', 'icon' => '', 'border' => '', 'bg_hover' => '', 'icon_hover' => '', 'border_hover' => '' ],
            (array) ( $saved['btn'] ?? [] )
        );
        $post_types = get_post_types( [ 'public' => true ], 'objects' );

        // Export nonce URL.
        $export_url = wp_nonce_url(
            admin_url( 'options-general.php?page=bb-dark-mode&bb_dm_export=1' ),
            'bb_dm_export_action',
            $this->export_nonce
        );
        ?>
        <div class="wrap">
            <h1>BB Custom Dark Mode Pro</h1>

            <?php if ( empty( $colors ) ) : ?>
                <div class="bb-dm-notice">
                    <strong>Notice:</strong> No Beaver Builder global colours were detected.
                    Colour mapping dropdowns will be empty. Ensure Beaver Builder is active
                    and global colours are configured under <em>Global Styles</em>.
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'bb_dark_mode_group' ); ?>

                <!-- 1. Site Background Mapping -->
                <div class="bb-dm-card">
                    <h2>1. Site Background Mapping</h2>
                    <div class="bg-map-box">
                        <div>
                            <span class="bb-swatch"></span>
                            <select name="<?php echo esc_attr( $this->option_name ); ?>[bg][light]" class="bb-color-select">
                                <option value="" data-color="">Light BG&hellip;</option>
                                <?php foreach ( $colors as $c ) : ?>
                                    <option value="<?php echo esc_attr( $c['slug'] ); ?>"
                                            data-color="<?php echo esc_attr( $c['color'] ); ?>"
                                            <?php selected( $bg['light'] ?? '', $c['slug'] ); ?>>
                                        <?php echo esc_html( $c['name'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <span>&rarr;</span>
                        <div>
                            <span class="bb-swatch"></span>
                            <select name="<?php echo esc_attr( $this->option_name ); ?>[bg][dark]" class="bb-color-select">
                                <option value="" data-color="">Dark BG&hellip;</option>
                                <?php foreach ( $colors as $c ) : ?>
                                    <option value="<?php echo esc_attr( $c['slug'] ); ?>"
                                            data-color="<?php echo esc_attr( $c['color'] ); ?>"
                                            <?php selected( $bg['dark'] ?? '', $c['slug'] ); ?>>
                                        <?php echo esc_html( $c['name'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- 2. Global Colour Mapping -->
                <div class="bb-dm-card">
                    <h2>2. Global Colour Mapping</h2>
                    <div id="bb-repeater" class="color-container">
                        <?php foreach ( $pairs as $i => $pair ) : ?>
                            <div class="bb-dm-row">
                                <div>
                                    <span class="bb-swatch"></span>
                                    <select name="<?php echo esc_attr( $this->option_name ); ?>[pairs][<?php echo (int) $i; ?>][light]" class="bb-color-select">
                                        <option value="" data-color="">Select Light&hellip;</option>
                                        <?php foreach ( $colors as $c ) : ?>
                                            <option value="<?php echo esc_attr( $c['slug'] ); ?>"
                                                    data-color="<?php echo esc_attr( $c['color'] ); ?>"
                                                    <?php selected( $pair['light'] ?? '', $c['slug'] ); ?>>
                                                <?php echo esc_html( $c['name'] ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <span>&rarr;</span>
                                <div>
                                    <span class="bb-swatch"></span>
                                    <select name="<?php echo esc_attr( $this->option_name ); ?>[pairs][<?php echo (int) $i; ?>][dark]" class="bb-color-select">
                                        <option value="" data-color="">Select Dark&hellip;</option>
                                        <?php foreach ( $colors as $c ) : ?>
                                            <option value="<?php echo esc_attr( $c['slug'] ); ?>"
                                                    data-color="<?php echo esc_attr( $c['color'] ); ?>"
                                                    <?php selected( $pair['dark'] ?? '', $c['slug'] ); ?>>
                                                <?php echo esc_html( $c['name'] ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <span class="bb-dm-remove" role="button" tabindex="0">Remove</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button add-row-btn" data-target="bb-repeater">+ Add Pair</button>
                </div>

                <!-- 3. Manual CSS Variable Bridge -->
                <div class="bb-dm-card">
                    <h2>3. Manual CSS Variable Bridge</h2>
                    <div id="var-repeater" class="color-container">
                        <?php foreach ( $vars as $i => $v ) : ?>
                            <div class="bb-dm-row">
                                <input type="text"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[vars][<?php echo (int) $i; ?>][custom]"
                                       value="<?php echo esc_attr( $v['custom'] ?? '' ); ?>"
                                       placeholder="--variable-name"
                                       class="regular-text">
                                <span>&rarr;</span>
                                <div>
                                    <span class="bb-swatch"></span>
                                    <select name="<?php echo esc_attr( $this->option_name ); ?>[vars][<?php echo (int) $i; ?>][dark]" class="bb-color-select">
                                        <option value="" data-color="">Target Dark&hellip;</option>
                                        <?php foreach ( $colors as $c ) : ?>
                                            <option value="<?php echo esc_attr( $c['slug'] ); ?>"
                                                    data-color="<?php echo esc_attr( $c['color'] ); ?>"
                                                    <?php selected( $v['dark'] ?? '', $c['slug'] ); ?>>
                                                <?php echo esc_html( $c['name'] ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <span class="bb-dm-remove" role="button" tabindex="0">Remove</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button add-row-btn" data-target="var-repeater">+ Add Bridge</button>
                </div>

                <!-- 4. Settings & Exclusions -->
                <div class="bb-dm-card">
                    <h2>4. Settings &amp; Exclusions</h2>
                    <label style="display:block;margin-bottom:15px;">
                        <input type="checkbox"
                               name="<?php echo esc_attr( $this->option_name ); ?>[system_sync]"
                               value="1"
                               <?php checked( $saved['system_sync'] ?? 0, 1 ); ?>>
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
                                               <?php checked( in_array( $pt->name, (array) ( $saved['excluded_types'] ?? [] ), true ), true ); ?>>
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
                                       value="<?php echo esc_attr( $saved['excluded_ids'] ?? '' ); ?>"
                                       placeholder="e.g. 12, 45"
                                       class="regular-text">
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- 5. Toggle Button Styling -->
                <div class="bb-dm-card">
                    <h2>5. Toggle Button Styling</h2>
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
                                <select name="<?php echo esc_attr( $this->option_name ); ?>[btn][bg]" class="bb-color-select">
                                    <option value="" data-color="">Transparent</option>
                                    <?php foreach ( $colors as $c ) : ?>
                                        <option value="<?php echo esc_attr( $c['slug'] ); ?>"
                                                data-color="<?php echo esc_attr( $c['color'] ); ?>"
                                                <?php selected( $btn['bg'], $c['slug'] ); ?>>
                                            <?php echo esc_html( $c['name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Icon Colour</th>
                            <td>
                                <span class="bb-swatch"></span>
                                <select name="<?php echo esc_attr( $this->option_name ); ?>[btn][icon]" class="bb-color-select">
                                    <option value="" data-color="#333">Default</option>
                                    <?php foreach ( $colors as $c ) : ?>
                                        <option value="<?php echo esc_attr( $c['slug'] ); ?>"
                                                data-color="<?php echo esc_attr( $c['color'] ); ?>"
                                                <?php selected( $btn['icon'], $c['slug'] ); ?>>
                                            <?php echo esc_html( $c['name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Border Colour</th>
                            <td>
                                <span class="bb-swatch"></span>
                                <select name="<?php echo esc_attr( $this->option_name ); ?>[btn][border]" class="bb-color-select">
                                    <option value="" data-color="">None</option>
                                    <?php foreach ( $colors as $c ) : ?>
                                        <option value="<?php echo esc_attr( $c['slug'] ); ?>"
                                                data-color="<?php echo esc_attr( $c['color'] ); ?>"
                                                <?php selected( $btn['border'], $c['slug'] ); ?>>
                                            <?php echo esc_html( $c['name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr><td colspan="2"><hr style="border:none;border-top:1px solid #ccd0d4;margin:4px 0;"></td></tr>
                        <tr>
                            <th>Background <em>(hover)</em></th>
                            <td>
                                <span class="bb-swatch"></span>
                                <select name="<?php echo esc_attr( $this->option_name ); ?>[btn][bg_hover]" class="bb-color-select">
                                    <option value="" data-color="">Transparent</option>
                                    <?php foreach ( $colors as $c ) : ?>
                                        <option value="<?php echo esc_attr( $c['slug'] ); ?>"
                                                data-color="<?php echo esc_attr( $c['color'] ); ?>"
                                                <?php selected( $btn['bg_hover'], $c['slug'] ); ?>>
                                            <?php echo esc_html( $c['name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Icon Colour <em>(hover)</em></th>
                            <td>
                                <span class="bb-swatch"></span>
                                <select name="<?php echo esc_attr( $this->option_name ); ?>[btn][icon_hover]" class="bb-color-select">
                                    <option value="" data-color="#333">Default</option>
                                    <?php foreach ( $colors as $c ) : ?>
                                        <option value="<?php echo esc_attr( $c['slug'] ); ?>"
                                                data-color="<?php echo esc_attr( $c['color'] ); ?>"
                                                <?php selected( $btn['icon_hover'], $c['slug'] ); ?>>
                                            <?php echo esc_html( $c['name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Border Colour <em>(hover)</em></th>
                            <td>
                                <span class="bb-swatch"></span>
                                <select name="<?php echo esc_attr( $this->option_name ); ?>[btn][border_hover]" class="bb-color-select">
                                    <option value="" data-color="">None</option>
                                    <?php foreach ( $colors as $c ) : ?>
                                        <option value="<?php echo esc_attr( $c['slug'] ); ?>"
                                                data-color="<?php echo esc_attr( $c['color'] ); ?>"
                                                <?php selected( $btn['border_hover'], $c['slug'] ); ?>>
                                            <?php echo esc_html( $c['name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button(); ?>
            </form>

            <!-- Export / Import — separate from the settings form -->
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
    // Front-end dynamic CSS
    // -------------------------------------------------------------------------

    public function inject_dynamic_css(): void {
        if ( is_admin() ) {
            return;
        }

        $saved = (array) get_option( $this->option_name, [] );

        // Exclusions — post type.
        if ( in_array( get_post_type(), (array) ( $saved['excluded_types'] ?? [] ), true ) ) {
            return;
        }

        // Exclusions — IDs (strict int comparison).
        $excluded_ids = array_filter(
            array_map( 'intval', array_map( 'trim', explode( ',', $saved['excluded_ids'] ?? '' ) ) )
        );
        if ( ! empty( $excluded_ids ) && in_array( (int) get_the_ID(), $excluded_ids, true ) ) {
            return;
        }

        $btn        = array_merge(
            [ 'size' => 44, 'shape' => 'round', 'bg' => '', 'icon' => '', 'border' => '', 'bg_hover' => '', 'icon_hover' => '', 'border_hover' => '' ],
            (array) ( $saved['btn'] ?? [] )
        );
        $btn_size        = $this->sanitize_int( $btn['size'] ?? 44 );
        $radius          = ( ( $btn['shape'] ?? 'round' ) === 'square' ) ? '4px' : '50%';
        $btn_bg          = ! empty( $btn['bg'] )           ? 'var(--fl-global-' . $this->sanitize_slug( $btn['bg'] ) . ')'           : 'transparent';
        $btn_icon        = ! empty( $btn['icon'] )         ? 'var(--fl-global-' . $this->sanitize_slug( $btn['icon'] ) . ')'         : 'currentColor';
        $btn_border      = ! empty( $btn['border'] )       ? '1px solid var(--fl-global-' . $this->sanitize_slug( $btn['border'] ) . ')' : 'none';
        $btn_bg_hover    = ! empty( $btn['bg_hover'] )     ? 'var(--fl-global-' . $this->sanitize_slug( $btn['bg_hover'] ) . ')'     : $btn_bg;
        $btn_icon_hover  = ! empty( $btn['icon_hover'] )   ? 'var(--fl-global-' . $this->sanitize_slug( $btn['icon_hover'] ) . ')'   : $btn_icon;
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

        foreach ( (array) ( $saved['pairs'] ?? [] ) as $pair ) {
            $light = $this->sanitize_slug( $pair['light'] ?? '' );
            $dark  = $this->sanitize_slug( $pair['dark']  ?? '' );
            if ( $light !== '' && $dark !== '' ) {
                $css .= "  --fl-global-{$light}: var(--fl-global-{$dark}) !important;\n";
            }
        }

        foreach ( (array) ( $saved['vars'] ?? [] ) as $v ) {
            $custom = $v['custom'] ?? '';
            $dark   = $this->sanitize_slug( $v['dark'] ?? '' );
            // Re-sanitize the stored custom variable name at output time.
            if ( $custom !== '' && $dark !== '' && strpos( $custom, '--' ) === 0 ) {
                $safe_var = '--' . preg_replace( '/[^a-zA-Z0-9\-_]/', '', substr( $custom, 2 ) );
                $css .= "  {$safe_var}: var(--fl-global-{$dark}) !important;\n";
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
        $css .= "
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
    // Front-end JS (properly enqueued + wp_localize_script)
    // -------------------------------------------------------------------------

    public function enqueue_assets(): void {
        $saved = (array) get_option( $this->option_name, [] );
        $sync  = isset( $saved['system_sync'] ) ? 1 : 0;

        // Register an empty script handle to attach our inline script to.
        wp_register_script(
            $this->script_handle,
            false,   // no external file
            [ 'jquery' ],
            '3.5',
            true     // footer
        );
        wp_enqueue_script( $this->script_handle );

        // Pass PHP settings to JS safely — no interpolation into JS strings.
        wp_localize_script(
            $this->script_handle,
            'bbDarkModeConfig',
            [ 'systemSync' => $sync ]
        );

        wp_add_inline_script(
            $this->script_handle,
            '(function($) {
                "use strict";

                var config = window.bbDarkModeConfig || { systemSync: 0 };

                /**
                 * Toggle dark mode on/off, persist preference, update ARIA.
                 * Accepts the button element so we can blur it immediately —
                 * this prevents it staying in :focus after a mouse click, which
                 * causes the theme/browser focus ring to show when the mouse leaves.
                 */
                window.bbDarkModeToggle = function(el) {
                    var isDark = $("body").toggleClass("dark-mode").hasClass("dark-mode");
                    $(".bb-dm-toggle").attr("aria-pressed", String(isDark));
                    try {
                        localStorage.setItem("bb_pref_theme", isDark ? "dark" : "light");
                    } catch (e) {}
                    // Release focus so the button does not stay in :focus state
                    // after a mouse click. Keyboard users are unaffected because
                    // they never trigger mouseleave after activating via Enter/Space.
                    if (el && el.blur) { el.blur(); }
                };

                $(function() {
                    var stored  = "";
                    try { stored = localStorage.getItem("bb_pref_theme") || ""; } catch (e) {}
                    var sysDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
                    var active  = (stored === "dark") || (!stored && config.systemSync && sysDark);

                    if (active) {
                        $("body").addClass("dark-mode");
                        $(".bb-dm-toggle").attr("aria-pressed", "true");
                    }
                });
            }(jQuery));'
        );
    }

    // -------------------------------------------------------------------------
    // Shortcode
    // -------------------------------------------------------------------------

    public function toggle_shortcode(): string {
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
    // Export / Import — secured
    // -------------------------------------------------------------------------

    public function handle_export_import(): void {

        // --- Export -------------------------------------------------------
        if ( isset( $_GET['bb_dm_export'] ) ) {
            // Capability check.
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'bb-dark-mode' ) );
            }
            // Nonce check.
            if ( ! isset( $_GET[ $this->export_nonce ] ) ||
                 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET[ $this->export_nonce ] ) ), 'bb_dm_export_action' )
            ) {
                wp_die( esc_html__( 'Security check failed.', 'bb-dark-mode' ) );
            }

            $data = get_option( $this->option_name, [] );
            header( 'Content-Type: application/json; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="bb-dm-settings.json"' );
            header( 'Cache-Control: no-cache, must-revalidate' );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo wp_json_encode( $data, JSON_PRETTY_PRINT );
            exit;
        }

        // --- Import -------------------------------------------------------
        if ( isset( $_POST['bb_dm_import_submit'] ) ) {
            // Only run on our own admin page.
            if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'bb-dark-mode' ) {
                return;
            }
            // Capability check.
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized.', 'bb-dark-mode' ) );
            }
            // Nonce check.
            if ( ! isset( $_POST[ $this->import_nonce ] ) ||
                 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $this->import_nonce ] ) ), 'bb_dm_import_action' )
            ) {
                wp_die( esc_html__( 'Security check failed.', 'bb-dark-mode' ) );
            }

            $file = $_FILES['bb_dm_import_file'] ?? null;

            // Basic upload error check.
            if ( ! $file || $file['error'] !== UPLOAD_ERR_OK || empty( $file['tmp_name'] ) ) {
                add_settings_error( $this->option_name, 'import_error', 'Import failed: upload error.', 'error' );
                return;
            }

            // Extension check — must be .json.
            $ext = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );
            if ( $ext !== 'json' ) {
                add_settings_error( $this->option_name, 'import_type', 'Import failed: only .json files are accepted.', 'error' );
                return;
            }

            // Size guard — reject files over 512 KB.
            if ( $file['size'] > 524288 ) {
                add_settings_error( $this->option_name, 'import_size', 'Import failed: file exceeds 512 KB limit.', 'error' );
                return;
            }

            $raw  = file_get_contents( $file['tmp_name'] );
            $data = json_decode( $raw, true );

            if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
                add_settings_error( $this->option_name, 'import_json', 'Import failed: invalid JSON.', 'error' );
                return;
            }

            // Run through the same sanitizer used by the Settings API.
            update_option( $this->option_name, $this->sanitize_settings( $data ) );
            add_settings_error( $this->option_name, 'import_ok', 'Settings imported successfully.', 'updated' );
        }
    }
}

new BBCustomDarkMode();