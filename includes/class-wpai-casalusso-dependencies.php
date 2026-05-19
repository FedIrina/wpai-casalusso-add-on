<?php
/**
 * Проверка зависимостей плагина.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wpai_Casalusso_Dependencies {

	/**
	 * @var array<string, string> slug => human-readable name
	 */
	private static $required_plugins = array(
		'woocommerce/woocommerce.php'                    => 'WooCommerce',
		'polylang/polylang.php'                          => 'Polylang',
		'woo-poly-integration_enhanced/__init__.php'    => 'Hyyan WooCommerce Polylang Integration (enhanced)',
		'woo-alt-variations/woo-alt-variations.php'      => 'Woocommerce Alternative Vatiations',
	);

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'deactivate_if_missing' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/**
	 * @return bool
	 */
	public static function are_met() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( self::$required_plugins as $plugin => $label ) {
			if ( ! is_plugin_active( $plugin ) ) {
				return false;
			}
		}

		if ( ! self::is_wp_all_import_active() ) {
			return false;
		}

		if ( ! function_exists( 'wp_all_import_get_import_id' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @return bool
	 */
	public static function is_wp_all_import_active() {
		return is_plugin_active( 'wp-all-import-pro/wp-all-import-pro.php' )
			|| is_plugin_active( 'wp-all-import/plugin.php' );
	}

	/**
	 * @return string[]
	 */
	public static function get_missing() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$missing = array();

		foreach ( self::$required_plugins as $plugin => $label ) {
			if ( ! is_plugin_active( $plugin ) ) {
				$missing[] = $label;
			}
		}

		if ( ! self::is_wp_all_import_active() ) {
			$missing[] = 'WP All Import (Pro или бесплатная версия)';
		}

		return $missing;
	}

	/**
	 * @return void
	 */
	public static function deactivate_if_missing() {
		if ( self::are_met() ) {
			return;
		}

		deactivate_plugins( plugin_basename( WPAI_CASALUSSO_FILE ) );
	}

	/**
	 * @return void
	 */
	public static function admin_notice() {
		if ( self::are_met() ) {
			return;
		}

		$missing = self::get_missing();
		if ( empty( $missing ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: comma-separated plugin names */
			__( 'WP All Import — Casalusso Add-On деактивирован: требуются плагины %s.', 'wpai-casalusso-add-on' ),
			implode( ', ', $missing )
		);

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}

Wpai_Casalusso_Dependencies::init();
