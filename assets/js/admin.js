/**
 * BB Custom Dark Mode — Admin Script
 *
 * Global colors manager, swatch syncing, sortable repeater rows, and
 * WordPress Iris color picker with alpha support.
 *
 * @package BB_Custom_Dark_Mode
 */

/* global jQuery, bbDarkModeAdmin */
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
		if (val.indexOf('rgb') !== -1 || val.indexOf('hsl') !== -1 || val.indexOf('#') === 0) {
			return val;
		}
		return '#' + val;
	}

	/**
	 * Update the nearest .bb-swatch sibling for a given <select>.
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
	 * drag-drop reorder or row addition/removal.
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

	/**
	 * Reindex all [vars][N] field names inside #var-repeater after a
	 * row addition/removal.
	 */
	function reindexVars() {
		$('#var-repeater .bb-dm-row').each(function (i) {
			$(this).find('input, select').each(function () {
				var n = $(this).attr('name');
				if (n) {
					$(this).attr('name', n.replace(/\[vars\]\[\d+\]/, '[vars][' + i + ']'));
				}
			});
		});
	}

	// =========================================================================
	// Tab Switching — matching Client AI plugin behaviour
	// =========================================================================

	// Safe SessionStorage retrieval to prevent SecurityError in restricted browsers/iframes
	var activeTab = 'tab-global-colours';
	try {
		activeTab = sessionStorage.getItem('bbdm_active_tab') || 'tab-global-colours';
	} catch (e) {
		console.warn('SessionStorage block detected, falling back to default tab.', e);
	}

	function switchTab(tabId) {
		$('.bbdm-tab-link').removeClass('active');
		$('.bbdm-tab-link[data-tab="' + tabId + '"]').addClass('active');
		$('.bbdm-tab-panel').removeClass('active').hide();
		$('#' + tabId).addClass('active').show();

		try {
			sessionStorage.setItem('bbdm_active_tab', tabId);
		} catch (e) {}
	}

	// Active progressive enhancement once jQuery loads successfully
	$('.bbdm-wrap').addClass('bbdm-js-active');

	$('.bbdm-tab-link').on('click', function (e) {
		e.preventDefault();
		var tabId = $(this).data('tab');
		switchTab(tabId);
	});

	// Initialize display configuration state safely
	switchTab(activeTab);

	// -------------------------------------------------------------------------
	// Iris Color Picker Initialization
	// -------------------------------------------------------------------------
	$('.bb-dm-iris-picker').wpColorPicker({
		mode: 'hsl',
		width: 280,
		change: function () {
			// Triggered when color changes.
		}
	});

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
			} else if ($container.attr('id') === 'var-repeater') {
				reindexVars();
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
		colors = colors || [];

		var optionsHtml = '<option value="" data-color="">&hellip;</option>';
		$.each(colors, function (i, c) {
			optionsHtml += '<option value="' + c.slug + '" data-color="' + c.color + '">' + c.name + '</option>';
		});

		$('.bb-color-select').each(function () {
			var $select  = $(this);
			var oldVal   = $select.val();
			$select.html(optionsHtml);
			$select.val(oldVal);
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
				'<button type="button" class="bb-dm-edit-color" data-slug="' + c.slug + '">Edit</button> | ' +
				'<button type="button" class="bb-dm-delete-color" data-slug="' + c.slug + '">Delete</button>' +
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
			// Reset the form fields.
			$('#bb-dm-new-color-name').val('');
			$('#bb-dm-new-color-slug').val('');
			// Reset Iris to default color.
			$('#bb-dm-new-color-hex').iris('color', '#ffffff');
		});
	});

	// -- Inline edit -------------------------------------------------------
	$(document).on('click', '.bb-dm-edit-color', function () {
		var slug   = $(this).data('slug');
		var $row   = $(this).closest('tr');
		var name   = $row.find('.col-name').text();
		var hex    = $row.find('.col-hex').text();

		// Store original row HTML before replacing for cancel.
		$row.data('bbdm-original-html', $row.html());

		$row.addClass('bb-dm-inline-edit').empty();

		// Swatch column.
		$('<td>').append(
			$('<span class="color-swatch">').css('background-color', formatColor(hex))
		).appendTo($row);

		// Name input.
		$('<td>').append(
			$('<input type="text" class="edit-name">').val(name)
		).appendTo($row);

		// Slug (read-only code).
		$('<td>').append(
			$('<code>').text(slug)
		).appendTo($row);

		// Hex input.
		$('<td>').append(
			$('<input type="text" class="edit-hex">').val(hex)
		).appendTo($row);

		// Actions column.
		$('<td class="row-actions">').append(
			$('<button type="button" class="bb-dm-save-edit">').attr('data-slug', slug).text('Save'),
			' | ',
			$('<button type="button" class="bb-dm-cancel-edit">').text('Cancel')
		).appendTo($row);
	});

	// -- Cancel inline edit ------------------------------------------------
	$(document).on('click', '.bb-dm-cancel-edit', function () {
		var $row = $(this).closest('tr');
		var original = $row.data('bbdm-original-html');
		if (original) {
			$row.removeClass('bb-dm-inline-edit').html(original);
			$row.removeData('bbdm-original-html');
		}
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

}(jQuery));