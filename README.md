# BB Custom Dark Mode

A pro-grade dark mode engine for [Beaver Builder](https://www.wpbeaverbuilder.com/) that maps your existing Global Colour palette to dark-mode equivalents — no hardcoded hex values, no separate colour management.

![Version](https://img.shields.io/badge/version-3.6.2-blue)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-informational)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-informational)
![License](https://img.shields.io/badge/license-GPL--2.0-green)

---

## How It Works

When a visitor toggles dark mode, the plugin overrides Beaver Builder's CSS custom properties on `body.dark-mode`:

```css
body.dark-mode {
  --fl-global-your-light-color: var(--fl-global-your-dark-color) !important;
}
```

Because BB renders all module colours as `var(--fl-global-*)` references, a single property swap cascades across every module on the page — no per-module configuration needed. The user's preference is stored in `localStorage` and optionally synced with the OS `prefers-color-scheme` setting.

---

## Features

- **Global Colour Mapping** — visually map any BB Global Colour to its dark-mode counterpart using a live swatch picker
- **Site Background Mapping** — dedicated light → dark background override for `body` and `.fl-page-content`
- **CSS Variable Bridge** — map any CSS custom property (e.g. from a child theme) to a BB Global Colour for dark mode
- **Toggle Button Styling** — control size, shape, background, icon colour, and border via Global Colours
- **Hover State Control** — separate hover colours for button background, icon, and border
- **System Preference Sync** — optionally follow the visitor's OS dark/light preference
- **Exclusions** — exclude specific post types or individual post/page IDs from dark mode
- **Shortcode** — place the toggle button anywhere with `[bb_dark_mode_toggle]`
- **Export / Import** — back up and restore all settings as a JSON file
- **Accessibility** — `aria-pressed` state management, `:focus-visible` keyboard ring, programmatic `blur()` on mouse click to prevent stuck focus states
- **Security hardened** — nonce-verified export/import, capability checks, full input sanitization on every field

---

## Requirements

| | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 8.0 |
| Beaver Builder | Any version with Global Styles / Global Colours |

---

## Installation

### From the GitHub Releases page

1. Download the latest `bb-custom-dark-mode.zip` from [Releases](../../releases)
2. In WordPress go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP, click **Install Now**, then **Activate**

### Manual / developer install

```bash
cd wp-content/plugins
git clone https://github.com/your-username/bb-custom-dark-mode.git
```

Activate the plugin from **Plugins → Installed Plugins**.

---

## Configuration

Go to **Settings → BB Dark Mode** in your WordPress admin.

### 1 — Site Background Mapping

Pick which BB Global Colour is used as the light-mode page background, and which is the dark-mode replacement. Applied to `body` and `.fl-page-content`.

### 2 — Global Colour Mapping

Add as many light → dark pairs as you need. Each pair tells the plugin: *"in dark mode, replace this light colour variable with this dark colour variable."* Use the **+ Add Pair** button to add rows; remove unwanted rows with the **Remove** link.

### 3 — CSS Variable Bridge

For CSS variables that live outside BB Global Styles (child theme, third-party plugin), type the variable name (e.g. `--my-heading-color`) and select the BB Global Colour it should resolve to in dark mode.

### 4 — Settings & Exclusions

| Option | Description |
|---|---|
| System Preference Sync | Auto-activate dark mode for visitors whose OS prefers dark |
| Exclude Post Types | Don't load dark mode CSS on selected post types |
| Exclude by IDs | Comma-separated post/page IDs to exclude (e.g. `12, 45`) |

### 5 — Toggle Button Styling

| Option | Description |
|---|---|
| Shape | Round or square |
| Size | Button size in px (10–200) |
| Background | Fill colour (BB Global Colour) |
| Icon Colour | SVG stroke colour (BB Global Colour) |
| Border Colour | Border colour (BB Global Colour) |
| Background (hover) | Fill colour on hover |
| Icon Colour (hover) | SVG stroke colour on hover |
| Border Colour (hover) | Border colour on hover |

Any hover field left blank falls back to the base value automatically.

---

## Placing the Toggle Button

Use the shortcode in any BB HTML Module, Code Module, or widget area:

```
[bb_dark_mode_toggle]
```

The button renders as an accessible `<button>` element with sun/moon SVG icons and full ARIA state management.

---

## Export & Import

The **Export / Import Settings** card at the bottom of the settings page lets you:

- **Export** — download your current settings as `bb-dm-settings.json`
- **Import** — upload a previously exported file to restore settings

Both actions require `manage_options` capability and are nonce-verified. Imported data is validated and passed through the same sanitization pipeline as the Settings API.

---

## Security

| Concern | How it's handled |
|---|---|
| Settings sanitization | `register_setting()` sanitize callback sanitizes every field before DB write |
| Export | Requires `manage_options` + valid nonce |
| Import | Requires `manage_options` + valid nonce + `.json` extension + 512 KB size limit + JSON validity check |
| CSS injection | All colour slugs and CSS variable names restricted to `[a-zA-Z0-9\-_]` at save time and at CSS output time |
| Post type exclusions | Validated against registered public post types |
| ID exclusions | Cast to `intval`, compared with strict type checking |
| External requests | None — no data leaves your server |

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.

---

## Open Source

This plugin is released as-is with no promise of support, updates, or responses to bug reports. It is fully open source — you are encouraged to fork it, adapt it, extend it, or take it in an entirely different direction. No attribution required, though always appreciated.

---

## License

Released under the [GNU General Public License v2.0](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html) or later, in keeping with WordPress licensing requirements.

---

## Author

**ttldsgn**
