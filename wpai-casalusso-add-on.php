<?php
/**
 * Plugin Name:       WP All Import — Casalusso Add-On
 * Description:       Назначение языка и связей переводов Polylang при импорте товаров WooCommerce через WP All Import.
 * Version:           1.0.0
 * Author:            Irina Feodorova
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce, polylang, wp-all-import-pro, woo-poly-integration_enhanced, woo-alt-variations
 * Text Domain:       wpai-casalusso-add-on
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPAI_CASALUSSO_VERSION', '1.0.0' );
define( 'WPAI_CASALUSSO_FILE', __FILE__ );
define( 'WPAI_CASALUSSO_PATH', plugin_dir_path( __FILE__ ) );

require_once WPAI_CASALUSSO_PATH . 'includes/wpai-casalusso-functions.php';
require_once WPAI_CASALUSSO_PATH . 'includes/class-wpai-casalusso-dependencies.php';
require_once WPAI_CASALUSSO_PATH . 'includes/class-wpai-casalusso-import.php';

add_action( 'plugins_loaded', 'wpai_casalusso_init', 20 );

/**
 * @return void
 */
function wpai_casalusso_init() {
	if ( ! Wpai_Casalusso_Dependencies::are_met() ) {
		return;
	}

	Wpai_Casalusso_Import::init();
}
