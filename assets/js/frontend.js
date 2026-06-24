/**
 * BB Custom Dark Mode — Frontend Script
 *
 * Toggle logic and system-preference sync.
 *
 * @package BB_Custom_Dark_Mode
 */

/* global jQuery, bbDarkModeConfig */
(function ($) {
	'use strict';

	var config  = window.bbDarkModeConfig || { systemSync: 0 };
	var sysDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

	/**
	 * Toggle dark mode on/off.
	 *
	 * Three-state localStorage model:
	 *   "dark"  — user explicitly chose dark
	 *   "light" — user explicitly chose light
	 *   null    — no manual preference; system sync decides
	 *
	 * When system sync is on and the user toggles back to match
	 * the system preference, we clear the stored value so system
	 * sync resumes control automatically on future visits.
	 *
	 * @param {HTMLElement} el The toggle button element (optional).
	 */
	window.bbDarkModeToggle = function (el) {
		var isDark = $('body').toggleClass('dark-mode').hasClass('dark-mode');
		$('.bb-dm-toggle').attr('aria-pressed', String(isDark));
		try {
			if (config.systemSync && isDark === sysDark) {
				// User toggled back to match system — let system sync take over again.
				localStorage.removeItem('bb_pref_theme');
			} else {
				localStorage.setItem('bb_pref_theme', isDark ? 'dark' : 'light');
			}
		} catch (e) {}
		if (el && el.blur) {
			el.blur();
		}
	};

	$(function () {
		var stored = null;
		try {
			stored = localStorage.getItem('bb_pref_theme');
		} catch (e) {}

		// Three-state check — mirrors the anti-flash script exactly.
		var shouldBeDark = (stored === 'dark') ||
						   (stored === null && config.systemSync && sysDark);

		if (shouldBeDark) {
			$('body').addClass('dark-mode');
			$('.bb-dm-toggle').attr('aria-pressed', 'true');
		}

		// Keep in sync if the OS preference changes while the page is open.
		if (config.systemSync) {
			window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
				var manualPref = null;
				try {
					manualPref = localStorage.getItem('bb_pref_theme');
				} catch (err) {}
				// Only follow the system change if the user has no manual preference.
				if (manualPref === null) {
					$('body').toggleClass('dark-mode', e.matches);
					$('.bb-dm-toggle').attr('aria-pressed', String(e.matches));
				}
			});
		}
	});

}(jQuery));