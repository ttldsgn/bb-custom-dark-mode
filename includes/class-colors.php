<?php
/**
 * BB Custom Dark Mode — Global Colour Manager
 *
 * Reads from and writes to Beaver Builder's global styles storage so colours
 * created here appear immediately in BB's Global Styles → Colors page and
 * vice versa.
 *
 * Uses FLBuilderGlobalStyles::get_settings() and save_settings() directly
 * to ensure full compatibility with BB's internal data format.
 *
 * @package BB_Custom_Dark_Mode
 * @since   3.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles all interaction with Beaver Builder's global colour storage.
 */
class BBCustomDarkMode_Colors {

	/**
	 * Pull the global colour palette from Beaver Builder, if available.
	 *
	 * BB stores colours under _fl_builder_styles as `colors` → array of
	 * { label, color, uid } maps.
	 *
	 * @since  3.8.0
	 * @return array[] Array of { 'label', 'slug', 'color' } maps.
	 */
	public function get_bb_colors() {
		$color_list = array();

		if ( ! class_exists( 'FLBuilderGlobalStyles' ) ) {
			return $color_list;
		}

		$settings = FLBuilderGlobalStyles::get_settings( false );
		$colors   = isset( $settings->colors ) ? $settings->colors : array();

		foreach ( (array) $colors as $item ) {
			$data = (array) $item;

			// BB uses 'label' as the colour display name.
			$label = isset( $data['label'] ) ? $data['label'] : ( isset( $data['name'] ) ? $data['name'] : '' );
			$color = isset( $data['color'] ) ? $data['color'] : '';

			if ( '' === $label ) {
				continue;
			}

			// Use stored slug if present, otherwise derive from label.
			$slug = isset( $data['slug'] ) ? $data['slug'] : FLBuilderGlobalStyles::label_to_key( $label );

			// Normalise hex missing # for display only (BB sometimes stores raw hex).
			$color_display = $color;
			if ( '' !== $color_display && '#' !== $color_display[0] && ! preg_match( '/^(rgb|rgba|hsl)/', $color_display ) && preg_match( '/^[a-fA-F0-9]{6}$/', $color_display ) ) {
				$color_display = '#' . $color_display;
			}

			$color_list[] = array(
				'name'  => $label,
				'slug'  => $slug,
				'color' => $color_display,
			);
		}

		return $color_list;
	}

	/**
	 * Add a new global colour to Beaver Builder's palette.
	 *
	 * @since  3.8.0
	 * @param  string $name  Human-readable label.
	 * @param  string $color Colour value (e.g. #ff0000 or rgba(0,0,0,0.5)).
	 * @param  string $slug  Optional machine-friendly slug; auto-generated from $name if omitted.
	 * @return array|false   Updated colour list, or false on failure.
	 */
	public function add_color( $name, $color, $slug = '' ) {
		if ( ! class_exists( 'FLBuilderGlobalStyles' ) ) {
			return false;
		}

		$settings = FLBuilderGlobalStyles::get_settings( false );
		$colors   = isset( $settings->colors ) ? (array) $settings->colors : array();

		$label     = sanitize_text_field( $name );
		$safe_slug = '' !== $slug ? $this->sanitize_slug( $slug ) : FLBuilderGlobalStyles::label_to_key( $label );

		// Check for duplicate slug — reject if already exists.
		foreach ( $colors as $item ) {
			$existing_slug = $this->get_color_slug( $item );
			if ( $existing_slug === $safe_slug ) {
				return false;
			}
		}

		// Build the new colour item in BB's native format.
		$new_item = array(
			'label' => $label,
			'color' => $this->normalize_color( $color ),
			'uid'   => substr( md5( wp_rand() ), 0, 9 ),
		);

		// Always store the slug so it persists across renames.
		$new_item['slug'] = $safe_slug;

		$colors[] = $new_item;

		// Save via BB's own method so it handles caching, cache busting, etc.
		$settings->colors = $colors;
		FLBuilderGlobalStyles::save_settings( $settings );

		return $this->get_bb_colors();
	}

	/**
	 * Update an existing global colour.
	 *
	 * @since  3.8.0
	 * @param  string $slug    Slug of the colour to update.
	 * @param  array  $updates Associative array with optional keys: name (label), color.
	 * @return array|false     Updated colour list, or false on failure.
	 */
	public function update_color( $slug, $updates ) {
		if ( ! class_exists( 'FLBuilderGlobalStyles' ) ) {
			return false;
		}

		$settings = FLBuilderGlobalStyles::get_settings( false );
		$colors   = isset( $settings->colors ) ? (array) $settings->colors : array();
		$found    = false;

		foreach ( $colors as $key => $item ) {
			$item_slug = $this->get_color_slug( $item );

			if ( $item_slug === $slug ) {
				if ( isset( $updates['name'] ) ) {
					$colors[ $key ]['label'] = sanitize_text_field( $updates['name'] );
				}
				if ( isset( $updates['color'] ) ) {
					$colors[ $key ]['color'] = $this->normalize_color( $updates['color'] );
				}
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return false;
		}

		$settings->colors = $colors;
		FLBuilderGlobalStyles::save_settings( $settings );

		return $this->get_bb_colors();
	}

	/**
	 * Delete a global colour by slug.
	 *
	 * @since  3.8.0
	 * @param  string $slug Slug of the colour to delete.
	 * @return array|false  Updated colour list, or false on failure.
	 */
	public function delete_color( $slug ) {
		if ( ! class_exists( 'FLBuilderGlobalStyles' ) ) {
			return false;
		}

		$settings = FLBuilderGlobalStyles::get_settings( false );
		$colors   = isset( $settings->colors ) ? (array) $settings->colors : array();
		$filtered = array();

		foreach ( $colors as $item ) {
			$item_slug = $this->get_color_slug( $item );

			if ( $item_slug === $slug ) {
				continue;
			}
			$filtered[] = $item;
		}

		$settings->colors = $filtered;
		FLBuilderGlobalStyles::save_settings( $settings );

		return $this->get_bb_colors();
	}

	/**
	 * Get a stable slug for a colour item.
	 *
	 * Uses the stored slug field if present, otherwise derives from label.
	 *
	 * @since  3.9.0
	 * @param  array  $item BB colour item { label, color, uid, [slug] }.
	 * @return string
	 */
	private function get_color_slug( $item ) {
		$data = (array) $item;

		// Use stored slug if available.
		if ( isset( $data['slug'] ) && '' !== $data['slug'] ) {
			return $this->sanitize_slug( $data['slug'] );
		}

		// Fallback to label-derived slug.
		$label = isset( $data['label'] ) ? $data['label'] : ( isset( $data['name'] ) ? $data['name'] : '' );
		return FLBuilderGlobalStyles::label_to_key( $label );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
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
	 * Normalise a colour value — hex gets # prefix if missing, everything
	 * else (rgb, rgba, hsl) is returned unchanged.
	 *
	 * @since  3.8.0
	 * @param  string $color Raw colour value.
	 * @return string
	 */
	private function normalize_color( $color ) {
		$color = trim( $color );

		// Already has # prefix — valid hex.
		if ( preg_match( '/^#[a-fA-F0-9]{3,8}$/', $color ) ) {
			return $color;
		}

		// rgb/rgba/hsl/hsla — leave untouched.
		if ( preg_match( '/^(rgb|rgba|hsl|hsla)\(/', $color ) ) {
			return $color;
		}

		// Raw hex without # — prepend #.
		if ( preg_match( '/^[a-fA-F0-9]{3,8}$/', $color ) ) {
			return '#' . $color;
		}

		// Fallback: return original.
		return $color;
	}
}