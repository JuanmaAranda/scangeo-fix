<?php
/**
 * Plugin Name:       scanGEO Fixer
 * Plugin URI:        https://scangeo.app
 * Description:       Sube el informe .md de scanGEO.app, mira tu nota GEO y su evolución, y repara los fallos SEO/GEO detectados: automáticamente cuando es seguro, o con una propuesta de IA que revisas y apruebas cuando toca contenido.
 * Version:           1.9.10
 * Author:            scanGEO.app
 * Author URI:        https://scangeo.app
 * Text Domain:       scangeo-fixer
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SCANGEO_FIXER_VERSION', '1.9.10' );
define( 'SCANGEO_FIXER_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCANGEO_FIXER_URL', plugin_dir_url( __FILE__ ) );

require_once SCANGEO_FIXER_DIR . 'includes/class-scangeo-parser.php';
require_once SCANGEO_FIXER_DIR . 'includes/class-scangeo-ai.php';
require_once SCANGEO_FIXER_DIR . 'includes/class-scangeo-fixers.php';
require_once SCANGEO_FIXER_DIR . 'includes/class-scangeo-frontend.php';
require_once SCANGEO_FIXER_DIR . 'includes/class-scangeo-updater.php';
require_once SCANGEO_FIXER_DIR . 'admin/class-scangeo-admin.php';

// Salidas en el frontend (metas, schema, robots, llms.txt) según los arreglos aplicados.
ScanGEO_Frontend::init();

// Comprobación de nuevas versiones vía GitHub Releases (ver class-scangeo-updater.php).
ScanGEO_Updater::init();

if ( is_admin() ) {
	ScanGEO_Admin::init();
}
