<?php
/**
 * BB Custom Dark Mode — Global Colour Manager
 *
 * Reads from and writes to Beaver Builder's global styles option so colours
 * created here appear immediately in BB's Global Styles → Colors page and
 * vice versa.
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
	 * Option key Beaver Builder uses for its global settings.
	 *
	 * @since 3.8.0
	 * @var   string
	 */
	const BB_OPTION_KEY = '_fl_builder_global_settings';

	/**
	 * Pull the global colour palette from Beaver Builder, if available.
	 *
	 * @since  3.8.0
	 * @return array[] Array of { 'name', 'slug', 'color' } maps.
	 */
	public function get_bb_colors() {
		$color_list = array();

		if ( ! class_exists( 'FLBuilderGlobalStyles' ) ) {
			return $color_list;
		}

		$settings = FLBuilderGlobalStyles::get_settings();
		$items    = isset( $settings->colors->items )
			? $settings->colors->items
			: ( isset( $settings->colors ) ? $settings->colors : array() );

		foreach ( (array) $items as $item ) {
			$data      = (array) $item;
			$name      = isset( $data['name'] ) ? $data['name'] : ( isset( $data['label'] ) ? $data['label'] : '' );
			$color_val = isset( $data['color'] ) ? $data['color'] : ( isset( $data['hex'] ) ? $data['hex'] : '' );

			// Normalise hex missing # for display (BB sometimes stores raw hex).
			if ( '' !== $color_val && '#' !== $color_val[0] && preg_match( '/^[a-fA-F0-9]{6}$/', $color_val ) ) {
				$color_val = '#' . $color_val;
			}

			if ( '' === $name ) {
				continue;
			}

			$raw_slug = isset( $data['slug'] ) ? $data['slug'] : sanitize_title( $name );

			$color_list[] = array(
				'name'  => esc_html( $name ),
				'slug'  => $this->sanitize_slug( $raw_slug ),
				'color' => esc_attr( $color_val ),
			);
		}

		return $color_list;
	}

	/**
	 * Add a new global colour to Beaver Builder's palette.
	 *
	 * @since  3.8.0
	 * @param  string $name  Human-readable label.
	 * @param  string $color Hex colour value (e.g. #ff0000).
	 * @param  string $slug  Optional machine-friendly slug; auto-generated from $name if omitted.
	 * @return array          Updated colour list, or empty array on failure.
	 */
	public function add_color( $name, $color, $slug = '' ) {
		if ( ! class_exists( 'FLBuilderGlobalStyles' ) ) {
			return array();
		}

		$slug  = '' !== $slug ? $this->sanitize_slug( $slug ) : sanitize_title( $name );
		$color = $this->normalize_hex( $color );

		$current = $this->get_raw_colors();

		// Avoid duplicate slugs — append a suffix if needed.
		$base_slug = $slug;
		$counter   = 1;
		while ( $this->slug_exists( $current, $slug ) ) {
			$slug = $base_slug . '-' . $counter;
			$counter++;
		}

		$current[] = array(
			'name'  => sanitize_text_field( $name ),
			'slug'  => $slug,
			'color' => $color,
		);

		$this->save_raw_colors( $current );

		return $this->get_bb_colors();
	}

	/**
	 * Update an existing global colour.
	 *
	 * @since  3.8.0
	 * @param  string $slug    Slug of the colour to update.
	 * @param  array  $updates Associative array with optional keys: name, color, slug.
	 * @return array           Updated colour list, or empty array on failure.
	 */
	public function update_color( $slug, $updates ) {
		if ( ! class_exists( 'FLBuilderGlobalStyles' ) ) {
			return array();
		}

		$current = $this->get_raw_colors();
		$found   = false;

		foreach ( $current as $key => $item ) {
			if ( isset( $item['slug'] ) && $item['slug'] === $slug ) {
				if ( isset( $updates['name'] ) ) {
					$current[ $key ]['name'] = sanitize_text_field( $updates['name'] );
				}
				if ( isset( $updates['color'] ) ) {
					$current[ $key ]['color'] = $this->normalize_hex( $updates['color'] );
				}
				if ( isset( $updates['slug'] ) ) {
					$current[ $key ]['slug'] = $this->sanitize_slug( $updates['slug'] );
				}
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return array();
		}

		$this->save_raw_colors( $current );

		return $this->get_bb_colors();
	}

	/**
	 * Delete a global colour by slug.
	 *
	 * @since  3.8.0
	 * @param  string $slug Slug of the colour to delete.
	 * @return array        Updated colour list.
	 */
	public function delete_color( $slug ) {
		if ( ! class_exists( 'FLBuilderGlobalStyles' ) ) {
			return array();
		}

		$current  = $this->get_raw_colors();
		$filtered = array();

		foreach ( $current as $item ) {
			if ( isset( $item['slug'] ) && $item['slug'] === $slug ) {
				continue;
			}
			$filtered[] = $item;
		}

		$this->save_raw_colors( $filtered );

		return $this->get_bb_colors();
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Read the raw colours array from BB's option, returning an always-indexed
	 * array (never an object).
	 *
	 * @since  3.8.0
	 * @return array[]
	 */
	private function get_raw_colors() {
		$settings = get_option( self::BB_OPTION_KEY, array() );

		if ( is_object( $settings ) && isset( $settings->colors ) ) {
			return isset( $settings->colors->items )
				? (array) $settings->colors->items
				: (array) $settings->colors;
		}

		if ( is_array( $settings ) && isset( $settings['colors'] ) ) {
			return isset( $settings['colors']['items'] )
				? (array) $settings['colors']['items']
				: (array) $settings['colors'];
		}

		return array();
	}

	/**
	 * Write the raw colours array back to BB's option, preserving the same
	 * object shape that FLBuilderGlobalStyles::get_settings() expects.
	 *
	 * @since 3.8.0
	 * @param array[] $colors Raw colour items.
	 */
	private function save_raw_colors( $colors ) {
		$existing = get_option( self::BB_OPTION_KEY, array() );

		if ( is_object( $existing ) ) {
			if ( ! isset( $existing->colors ) ) {
				$existing->colors = new stdClass();
			}
			if ( isset( $existing->colors->items ) ) {
				$existing->colors->items = $colors;
			} else {
				$existing->colors = $colors;
			}
		} else {
			if ( ! is_array( $existing ) ) {
				$existing = array();
			}
			if ( isset( $existing['colors']['items'] ) ) {
				$existing['colors']['items'] = $colors;
			} else {
				$existing['colors'] = $colors;
			}
		}

		update_option( self::BB_OPTION_KEY, $existing );
	}

	/**
	 * Check whether a slug already exists in the palette.
	 *
	 * @since  3.8.0
	 * @param  array[] $colors Raw colour items.
	 * @param  string  $slug   Slug to check.
	 * @return bool
	 */
	private function slug_exists( $colors, $slug ) {
		foreach ( $colors as $item ) {
			if ( isset( $item['slug'] ) && $item['slug'] === $slug ) {
				return true;
			}
		}
		return false;
	}

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
	 * Normalise a hex colour to `#rrggbb` format.
	 *
	 * @since  3.8.0
	 * @param  string $color Raw colour value.
	 * @return string
	 */
	private function normalize_hex( $color ) {
		$color = trim( $color );

		// Already valid 6-char hex?
		if ( preg_match( '/^#[a-fA-F0-9]{6}$/', $color ) ) {
			return $color;
		}

		// 3-char shorthand — expand to 6.
		if ( preg_match( '/^#[a-fA-F0-9]{3}$/', $color ) ) {
			return '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
		}

		// Missing # — prepend.
		if ( preg_match( '/^[a-fA-F0-9]{6}$/', $color ) ) {
			return '#' . $color;
		}

		// Treat anything else as #000.
		return '#000000';
	}
}