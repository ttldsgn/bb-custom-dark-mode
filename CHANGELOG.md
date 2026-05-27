# Changelog

All notable changes to BB Custom Dark Mode are documented here.

---

## [3.7.0] — 2026-05-27

### Added
- Drag & drop sorting for Global Colour Mapping pairs. Rows can be
  reordered by grabbing the ↕ handle on the left. Order is preserved
  on save. Uses jQuery UI Sortable which is bundled with WordPress
  admin — no extra library required.

---

## [3.6.2] — 2025-05-23

### Fixed
- Toggle button no longer shows a stuck blue focus ring when the mouse leaves after a click. The button was retaining `:focus` after a mouse click, causing the browser/theme focus ring to reappear once `:hover` cleared. Fixed by calling `el.blur()` immediately after the toggle action. Keyboard users are unaffected.

---

## [3.6.1] — 2025-05-23

### Fixed
- Added `box-shadow: none`, `appearance: none`, `-webkit-appearance: none`, and `-webkit-tap-highlight-color: transparent` to the base toggle rule to suppress browser-default and theme-injected focus chrome.
- Explicit `:focus`, `:focus:not(:focus-visible)`, and `:active` resets added to prevent theme stylesheets overriding button appearance after a click.

---

## [3.6.0] — 2025-05-23

### Added
- Hover state colours for the toggle button — Background, Icon Colour, and Border Colour each have a matching hover picker, all mapped to BB Global Colours using the same swatch interface as the base button colours.
- Hover colours fall back to the base value automatically when left unset.

---

## [3.5.2] — 2025-05-23

### Fixed
- Changing the Dark colour dropdown was incorrectly updating the Light swatch. Fixed by scoping `updateSwatch()` to `$select.parent().find(".bb-swatch")` so each dropdown only updates its own paired swatch.

---

## [3.5.1] — 2025-05-23

### Fixed
- Swatches showed grey on page load after saving. Admin JS was attached to the `jquery` handle which runs in `<head>` before the DOM exists. Moved to a dedicated `bb-dark-mode-admin` script handle registered with `in_footer: true`.

---

## [3.5.0] — 2025-05-23

### Added
- Initial public release.

### Security
- Export and import endpoints secured with `manage_options` capability checks and nonce verification.
- Import validates file extension, enforces a 512 KB size limit, and checks JSON validity before writing to the database.
- `register_setting()` sanitize callback added — all fields sanitized before any database write.
- All colour slugs and CSS variable names restricted to `[a-zA-Z0-9\-_]` at save time and CSS output time.
- Post type exclusions validated against registered public post types.
- Excluded IDs cast to `intval` and compared with strict type checking.
- `btn[size]` validated as an integer between 10 and 200.

### Changed
- Admin JavaScript moved from raw `admin_head` injection to a properly enqueued footer script.
- PHP data passed to frontend JS via `wp_localize_script()` instead of string interpolation.
- All HTML outputs use `esc_attr()` and `esc_html()` consistently.
- Toggle function namespaced to `bbDarkModeToggle`.
- SVG icons include `aria-hidden="true"` and `focusable="false"` for correct screen reader behaviour.
- Admin page shows a notice when no BB Global Colours are detected.
