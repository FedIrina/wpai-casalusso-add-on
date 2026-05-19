<?php
/**
 * Вспомогательные функции для импорта (в т.ч. вызов из WP All Import: [wpai_casalusso_make_vars_string({поле})]).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'wpai_casalusso_make_vars_string' ) ) {
	/**
	 * Преобразует строку alt_variations из CSV в JSON для мета vars_info.
	 *
	 * Формат входа: «ИмяАтрибута|id1,Значение1;id2,Значение2;…»
	 *
	 * @param string $input
	 * @return string JSON или пустая строка при ошибке.
	 */
	function wpai_casalusso_make_vars_string( $input ) {
		$log_file = WP_CONTENT_DIR . '/import_alt_variatios.log';
		$log_msgs = array();

		$parts = explode( '|', trim( $input ) );
		if ( count( $parts ) < 2 ) {
			$log_msgs[] = "Ошибка: строка не содержит символ '|'. Вход: '$input'";
			file_put_contents( $log_file, implode( "\n", $log_msgs ) . "\n", FILE_APPEND );
			return '';
		}

		$attr_name   = $parts[0];
		$values_part = $parts[1];
		$values      = explode( ';', $values_part );

		$products = array();

		foreach ( $values as $item ) {
			$item = trim( $item );
			if ( $item === '' ) {
				continue;
			}

			if ( preg_match( '/^(\d+),(.+)$/u', $item, $matches ) ) {
				$product_id = $matches[1];
				$name       = $matches[2];
				$products[] = array(
					'product_id'     => $product_id,
					'image_id'       => '',
					'var_attr_value' => $name,
				);
			} else {
				$log_msgs[] = "Неправильный формат элемента: '$item'";
			}
		}

		if ( ! empty( $log_msgs ) ) {
			file_put_contents( $log_file, implode( "\n", $log_msgs ) . "\n", FILE_APPEND );
		}

		$result = array(
			array(
				'attr_name' => $attr_name,
				'products'  => $products,
			),
		);
		file_put_contents( $log_file, wp_json_encode( $result, JSON_UNESCAPED_UNICODE ) . "\n", FILE_APPEND );

		return wp_json_encode( $result, JSON_UNESCAPED_UNICODE );
	}
}
