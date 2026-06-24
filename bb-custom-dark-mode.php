<?php
/**
 * Plugin Name: BB Custom Dark Mode
 * Description: Pro-grade Dark Mode engine for Beaver Builder. Full mapping, Exclusions, and Strict Accessibility.
 * Version:     3.8.0
 * Author:      totaldsgn
 * Text Domain: bb-dark-mode
 *
 * @package BB_Custom_Dark_Mode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load class files.
require_once __DIR__ . '/includes/class-colors.php';
require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-frontend.php';

// Bootstrap.
$bb_dm_colors    = new BBCustomDarkMode_Colors();
$bb_dm_admin     = new BBCustomDarkMode_Admin( $bb_dm_colors );
$bb_dm_frontend  = new BBCustomDarkMode_Frontend();