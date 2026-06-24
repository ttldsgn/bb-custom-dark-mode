/**
 * BB Custom Dark Mode — Admin Script
 *
 * Global colors manager, swatch syncing, sortable repeater rows, and
 * Iris colour picker integration.
 *
 * @package BB_Custom_Dark_Mode
 */

/* global jQuery, wp, bbDarkModeAdmin */
(function ($) {
	'use strict';

	var config = window.bbDarkModeAdmin || {};

	/**
	 * Given a raw colour string, return something the browser can use as a
	 * CSS background-color value.
	 *
	 * @param {string} val Raw colour value.
	 * @return {string} CSS-ready colour or empty string.
	 */
	function formatColor(val) {
		if (!val) {
			return '';
		}
		val = val.trim();
		return (val.indexOf('rgb') !== -1 || val.indexOf('#') === 0) ? val : '#' + val;
	}

	/**
	 * Update the nearest .bb-swatch sibling for a given <select>.
	 *
	 * We walk up one parent level (the immediate <div> wrapping both the
	 * swatch and the select) so we only touch the swatch paired with this
	 * dropdown, never the one belonging to the sibling Light/Dark column.
	 *
	 * @param {HTMLSelectElement} selectElement The select that changed.
	 */
	function updateSwatch(selectElement) {
		var $select  = $(selectElement);
		var colorVal = $select.find(':selected').attr('data-color');
		var color    = colorVal ? formatColor(colorVal) : '#eeeeee';

		$select.parent().find('.bb-swatch').css('background-color', color);
	}

	/**
	 * Reindex all [pairs][N] field names inside #bb-repeater after a
	 * drag-drop reorder or row addition/removal so the submitted order
	 * matches the visual order on screen.
	 */
	function reindexPairs() {
		$('#bb-repeater .bb-dm-row').each(function (i) {
			$(this).find('select').each(function () {
				var n = $(this).attr('name');
				if (n) {
					$(this).attr('name', n.replace(/\[pairs\]\[\d+\]/, '[pairs][' + i + ']'));
				}
			});
		});
	}

	// -------------------------------------------------------------------------
	// Colour-swatch initialisation & live-update
	// -------------------------------------------------------------------------
	$('.bb-color-select').each(function () {
		updateSwatch(this);
	});

	$(document).on('change', '.bb-color-select', function () {
		updateSwatch(this);
	});

	// -------------------------------------------------------------------------
	// Sortable repeater rows (pairs)
	// -------------------------------------------------------------------------
	$('#bb-repeater').sortable({
		handle:      '.bb-dm-drag-handle',
		axis:        'y',
		placeholder: 'bb-dm-row ui-sortable-placeholder',
		forcePlaceholderSize: true,
		tolerance:   'pointer',
		stop:        reindexPairs
	});

	// -------------------------------------------------------------------------
	// Add / Remove repeater rows
	// -------------------------------------------------------------------------
	$('.add-row-btn').on('click', function () {
		var target     = $(this).data('target');
		var $container = $('#' + target);
		var $firstRow  = $container.find('.bb-dm-row').first();
		if (!$firstRow.length) {
			return;
		}

		var $row  = $firstRow.clone();
		var index = $container.find('.bb-dm-row').length;

		$row.find('input, select').val('').each(function () {
			var n = $(this).attr('name');
			if (n) {
				$(this).attr('name', n.replace(/\[\d+\]/, '[' + index + ']'));
			}
		});
		$row.find('.bb-swatch').css('background-color', '#eeeeee');
		$container.append($row);

		if (target === 'bb-repeater') {
			$('#bb-repeater').sortable('refresh');
		}
	});

	$(document).on('click', '.bb-dm-remove', function () {
		var $container = $(this).closest('.color-container');
		if ($container.find('.bb-dm-row').length > 1) {
			$(this).closest('.bb-dm-row').remove();
			if ($container.attr('id') === 'bb-repeater') {
				reindexPairs();
			}
		}
	});

	// =========================================================================
	// Global Colours Manager (CRUD via AJAX)
	// =========================================================================

	/**
	 * Refresh all .bb-color-select dropdowns with an updated colour list
	 * returned from the server, preserving the currently selected values.
	 *
	 * @param {Array} colors Array of { name, slug, color } objects.
	 */
	function refreshAllColorDropdowns(colors) {
		if (!colors || !colors.length) {
			return;
		}

		// Build the new <option> HTML with slugs mapped to their hex values.
		var optionsHtml = '<option value="" data-color="">&hellip;</option>';
		$.each(colors, function (i, c) {
			optionsHtml += '<option value="' + c.slug + '" data-color="' + c.color + '">' + c.name + '</option>';
		});

		// For every .bb-color-select on the page, swap inner options while
		// preserving the currently selected value (it will flash-reset if
		// the slug no longer exists, which is benign).
		$('.bb-color-select').each(function () {
			var $select  = $(this);
			var oldVal   = $select.val();
			$select.html(optionsHtml);
			$select.val(oldVal); // gracefully resets to '' if oldVal not in new list
			updateSwatch(this);
		});
	}

	/**
	 * Re-render the table of existing global colours.
	 *
	 * @param {Array} colors Array of { name, slug, color } objects.
	 */
	function renderColorsTable(colors) {
		var $tbody = $('#bb-dm-colors-tbody');
		if (!$tbody.length) {
			return;
		}

		if (!colors.length) {
			$tbody.html(
				'<tr class="no-items"><td colspan="5">' +
				'No global colours yet. Add one above.' +
				'</td></tr>'
			);
			return;
		}

		var rows = '';
		$.each(colors, function (i, c) {
			rows += '<tr data-slug="' + c.slug + '">' +
				'<td><span class="color-swatch" style="background-color:' +
				formatColor(c.color) + '"></span></td>' +
				'<td class="col-name">' + c.name + '</td>' +
				'<td class="col-slug"><code>' + c.slug + '</code></td>' +
				'<td class="col-hex">' + c.color + '</td>' +
				'<td class="row-actions">' +
				'<a class="bb-dm-edit-color" data-slug="' + c.slug + '">Edit</a> | ' +
				'<a class="bb-dm-delete-color" data-slug="' + c.slug + '">Delete</a>' +
				'</td>' +
				'</tr>';
		});
		$tbody.html(rows);
	}

	/**
	 * Generic AJAX wrapper for colour CRUD.
	 *
	 * @param {string}   action   CRUD action: add / edit / delete.
	 * @param {Object}   data     Payload to send.
	 * @param {Function} callback Called with the full server response on success.
	 */
	function colorAjax(action, data, callback) {
		var $spinner = $('#bb-dm-color-spinner');
		$spinner.addClass('is-active');

		$.post(
			config.ajaxUrl,
			{
				action: 'bb_dm_manage_color',
				nonce:  config.nonce,
				crud:   action,
				data:   data
			},
			function (response) {
				$spinner.removeClass('is-active');

				if (!response || !response.success) {
					alert(response && response.data ? response.data.message : 'Unknown error.');
					return;
				}

				if (callback) {
					callback(response);
				}
			}
		).fail(function () {
			$spinner.removeClass('is-active');
			alert('AJAX request failed. Please try again.');
		});
	}

	// -- Add colour --------------------------------------------------------
	$('#bb-dm-add-color-btn').on('click', function () {
		var name  = $('#bb-dm-new-color-name').val().trim();
		var hex   = $('#bb-dm-new-color-hex').val().trim();
		var slug  = $('#bb-dm-new-color-slug').val().trim();

		if (!name || !hex) {
			alert('Please provide both a name and a colour value.');
			return;
		}

		colorAjax('add', { name: name, color: hex, slug: slug }, function (res) {
			refreshAllColorDropdowns(res.data.colors);
			renderColorsTable(res.data.colors);
			$('#bb-dm-new-color-name').val('');
			$('#bb-dm-new-color-hex').val('');
			$('#bb-dm-new-color-slug').val('');
		});
	});

	// -- Inline edit -------------------------------------------------------
	$(document).on('click', '.bb-dm-edit-color', function () {
		var slug   = $(this).data('slug');
		var $row   = $(this).closest('tr');
		var name   = $row.find('.col-name').text();
		var hex    = $row.find('.col-hex').text();

		// Replace row with inline edit fields.
		$row.addClass('bb-dm-inline-edit').html(
			'<td><span class="color-swatch" style="background-color:' +
			formatColor(hex) + '"></span></td>' +
			'<td><input type="text" class="edit-name" value="' + name + '"></td>' +
			'<td><code>' + slug + '</code></td>' +
			'<td><input type="text" class="edit-hex" value="' + hex + '"></td>' +
			'<td class="row-actions">' +
			'<a class="bb-dm-save-edit" data-slug="' + slug + '">Save</a> | ' +
			'<a class="bb-dm-cancel-edit">Cancel</a>' +
			'</td>'
		);
	});

	// -- Cancel inline edit ------------------------------------------------
	$(document).on('click', '.bb-dm-cancel-edit', function () {
		// Re-render the full table to restore the static view.
		colorAjax('list', {}, function (res) {
			renderColorsTable(res.data.colors);
		});
	});

	// -- Save inline edit --------------------------------------------------
	$(document).on('click', '.bb-dm-save-edit', function () {
		var slug = $(this).data('slug');
		var name = $(this).closest('tr').find('.edit-name').val().trim();
		var hex  = $(this).closest('tr').find('.edit-hex').val().trim();

		if (!name || !hex) {
			alert('Name and colour are required.');
			return;
		}

		colorAjax('edit', { slug: slug, name: name, color: hex }, function (res) {
			refreshAllColorDropdowns(res.data.colors);
			renderColorsTable(res.data.colors);
		});
	});

	// -- Delete colour -----------------------------------------------------
	$(document).on('click', '.bb-dm-delete-color', function () {
		var slug = $(this).data('slug');
		if (!confirm('Delete this global colour? This cannot be undone.')) {
			return;
		}

		colorAjax('delete', { slug: slug }, function (res) {
			refreshAllColorDropdowns(res.data.colors);
			renderColorsTable(res.data.colors);
		});
	});

	// -- Iris colour picker on the hex field -------------------------------
	if (typeof $.fn.wpColorPicker === 'function') {
		$('#bb-dm-new-color-hex').wpColorPicker({
			defaultColor: '#ffffff',
			change: function () {
				// No-op; the value is read from the field on submit.
			}
		});
	}

}(jQuery));