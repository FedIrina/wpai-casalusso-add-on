<?php
/**
 * Вспомогательные функции для импорта.
 *
 * WP All Import: [wpai_casalusso_make_vars_string({alt_variations[1]}, {local[1]})]
 * Второй аргумент {local[1]} обязателен: custom fields парсятся до цикла записи, без AttachmentHandler::$importData.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Код языка (local) из текущей строки импорта WPAI.
 *
 * @return string Пустая строка, если контекст недоступен.
 */
function wpai_casalusso_get_local_from_import_context() {
	if ( ! class_exists( 'Wpai\WordPress\AttachmentHandler' ) || empty( \Wpai\WordPress\AttachmentHandler::$importData['current_xml_node'] ) ) {
		return '';
	}

	$node = (array) \Wpai\WordPress\AttachmentHandler::$importData['current_xml_node'];

	foreach ( array( 'local', 'Local', 'LOCAL' ) as $key ) {
		if ( isset( $node[ $key ] ) && $node[ $key ] !== '' ) {
			return strtolower( trim( (string) $node[ $key ] ) );
		}
	}

	return '';
}

/**
 * @param string $line
 * @return void
 */
function wpai_casalusso_log_alt_variations( $line ) {
	file_put_contents( WP_CONTENT_DIR . '/import_alt_variatios.log', $line . "\n", FILE_APPEND );
}

/**
 * Язык строки импорта: {local[1]} из WPAI, узел XML (если есть), язык обновляемого поста.
 *
 * @param string $local_arg Значение {local[1]} из CSV.
 * @return string
 */
function wpai_casalusso_resolve_import_lang( $local_arg ) {
	$lang = strtolower( trim( (string) $local_arg ) );
	if ( $lang !== '' ) {
		return $lang;
	}

	$lang = wpai_casalusso_get_local_from_import_context();
	if ( $lang !== '' ) {
		return $lang;
	}

	if ( class_exists( 'Wpai\WordPress\AttachmentHandler' ) && ! empty( \Wpai\WordPress\AttachmentHandler::$importData['pid'] ) && function_exists( 'pll_get_post_language' ) ) {
		$pid_lang = pll_get_post_language( (int) \Wpai\WordPress\AttachmentHandler::$importData['pid'] );
		if ( $pid_lang ) {
			return $pid_lang;
		}
	}

	return '';
}

/**
 * post_id товара по SKU и языку Polylang (один SCU — несколько постов, по одному на язык).
 *
 * @param string 	$sku Артикул товара
 * @param string 	$lang Код языка, например ru, en.
 * @return int 		id поста или 0, если не найден.
 */
function wpai_casalusso_get_product_id_by_sku_and_language( $sku, $lang ) {
	$sku  = trim( (string) $sku );
	$lang = strtolower( trim( (string) $lang ) );

	if ( $sku === '' || $lang === '' ) {
		return 0;
	}

	global $wpdb;

	$lookup_table = isset( $wpdb->wc_product_meta_lookup )
		? $wpdb->wc_product_meta_lookup
		: $wpdb->prefix . 'wc_product_meta_lookup';

	// wc_product_meta_lookup: индекс по sku (как wp_all_import_get_product_id_by_sku), без LIMIT — все языковые посты.
	$post_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$lookup_table} lookup ON p.ID = lookup.product_id
			WHERE lookup.sku = %s
			AND p.post_type IN ( 'product', 'product_variation' )
			AND p.post_status != 'trash'",
			$sku
		)
	);

	// Запасной путь, если lookup ещё не синхронизирован с _sku.
	if ( empty( $post_ids ) ) {
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE pm.meta_key = '_sku' AND pm.meta_value = %s
				AND p.post_type IN ( 'product', 'product_variation' )
				AND p.post_status != 'trash'",
				$sku
			)
		);
	}

	if ( empty( $post_ids ) ) {
		return 0;
	}

	if ( function_exists( 'pll_get_post_language' ) ) {
		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( pll_get_post_language( $post_id ) === $lang ) {
				return $post_id;
			}
		}

		return 0;
	}

	// Без Polylang — один пост на SKU.
	return (int) $post_ids[0];
}

/**
 * Преобразует строку alt_variations из CSV в JSON для мета vars_info.
 *
 * Формат входа: «ИмяАтрибута|SKU1,Значение1;SKU2,Значение2;…»
 *
 * @param string $input Строка alt_variations.
 * @param string $local Значение {local[1]} из той же строки CSV (рекомендуется).
 * @return string JSON или пустая строка при ошибке.
 */
function wpai_casalusso_make_vars_string( $input, $local = '' ) {
	$input = trim( (string) $input );
	wpai_casalusso_log_alt_variations( '--- make_vars_string ---' );
	wpai_casalusso_log_alt_variations( 'input: ' . $input );
	wpai_casalusso_log_alt_variations( 'local arg: ' . trim( (string) $local ) );

	$parts = explode( '|', $input );
	if ( count( $parts ) < 2 ) {
		wpai_casalusso_log_alt_variations( "Ошибка: нет '|' во входной строке" );
		return '';
	}

	$attr_name   = trim( $parts[0] );
	$values_part = $parts[1];
	$values      = explode( ';', $values_part );

	$lang = wpai_casalusso_resolve_import_lang( $local );
	wpai_casalusso_log_alt_variations( 'resolved lang: ' . ( $lang !== '' ? $lang : '(пусто)' ) );

	if ( $lang === '' ) {
		wpai_casalusso_log_alt_variations( 'Ошибка: не удалось определить язык. Укажите {local[1]} в вызове WPAI.' );
		return '';
	}

	$products  = array();
	$log_msgs  = array();

	foreach ( $values as $item ) {
		$item = trim( $item );
		if ( $item === '' ) {
			continue;
		}

		if ( ! preg_match( '/^([^,]+),(.+)$/u', $item, $matches ) ) {
			$log_msgs[] = "Неправильный формат элемента: '$item'";
			continue;
		}

		$sku  = trim( $matches[1] );
		$name = trim( $matches[2] );

		$product_id = wpai_casalusso_get_product_id_by_sku_and_language( $sku, $lang );
		if ( ! $product_id ) {
			$log_msgs[] = "SKU '$sku' не найден для языка '$lang' (var_attr_value: '$name')";
			continue;
		}

		$products[] = array(
			'product_id'     => (string) $product_id,
			'image_id'       => '',
			'var_attr_value' => $name,
		);
	}

	foreach ( $log_msgs as $msg ) {
		wpai_casalusso_log_alt_variations( $msg );
	}

	wpai_casalusso_log_alt_variations( 'products count: ' . count( $products ) );

	if ( empty( $products ) ) {
		wpai_casalusso_log_alt_variations( 'Ошибка: пустой список products — vars_info не записываем' );
		return '';
	}

	$result = array(
		array(
			'attr_name' => $attr_name,
			'products'  => $products,
		),
	);
	$json = wp_json_encode( $result, JSON_UNESCAPED_UNICODE );
	wpai_casalusso_log_alt_variations( 'result: ' . $json );

	return $json;
}

/**
 * Преобразует список названий атрибутов в формат мета _display_with_quantity_attributes.
 *
 * Вход (CSV): "Цвет, Размер" / "Color" / "Цвет;Размер".
 * Выход (meta): array( 'pa_czvet' => 'yes', 'pa_razmer' => 'yes' ).
 *
 * @param string $raw_labels Названия атрибутов из CSV (человекочитаемые).
 * @return string Сериализованный массив для записи в post meta.
 */
function wpai_casalusso_make_qty_attr_map( $raw_labels ) {
	$raw_labels = trim( (string) $raw_labels );
	$result     = array();

	if ( $raw_labels === '' ) {
		return maybe_serialize( $result );
	}

	$labels = preg_split( '/[;,]/', $raw_labels );
	if ( ! is_array( $labels ) ) {
		return maybe_serialize( $result );
	}

	$labels = array_filter(
		array_map(
			static function ( $label ) {
				return mb_strtolower( trim( (string) $label ) );
			},
			$labels
		),
		static function ( $label ) {
			return $label !== '';
		}
	);

	if ( empty( $labels ) ) {
		return maybe_serialize( $result );
	}

	$label_to_taxonomy = array();
	$taxonomies        = wc_get_attribute_taxonomies();
	if ( is_array( $taxonomies ) ) {
		foreach ( $taxonomies as $taxonomy ) {
			if ( empty( $taxonomy->attribute_name ) ) {
				continue;
			}

			$taxonomy_name = wc_attribute_taxonomy_name( $taxonomy->attribute_name ); // pa_*
			$label         = mb_strtolower( trim( (string) $taxonomy->attribute_label ) );
			$name          = mb_strtolower( trim( (string) $taxonomy->attribute_name ) );

			if ( $label !== '' ) {
				$label_to_taxonomy[ $label ] = $taxonomy_name;
			}
			if ( $name !== '' ) {
				$label_to_taxonomy[ $name ] = $taxonomy_name;
			}
			$label_to_taxonomy[ mb_strtolower( wc_attribute_label( $taxonomy_name ) ) ] = $taxonomy_name;
		}
	}

	foreach ( $labels as $label ) {
		if ( isset( $label_to_taxonomy[ $label ] ) ) {
			$result[ $label_to_taxonomy[ $label ] ] = 'yes';
		}
	}

	return maybe_serialize( $result );
}

/**
 * Колонка Availability CSV → yes|no.
 *
 * «В наличии» → yes, любое другое значение (например «Предзаказ») → no.
 *
 * WPAI: [wpai_casalusso_availability_to_yes_no({availability[1]})]
 *
 * @param string $availability Значение Availability из строки импорта.
 * @return string yes|no
 */
function wpai_casalusso_availability_to_yes_no( $availability ) {
	$availability = trim( (string) $availability );

	if ( $availability === 'В наличии' ) {
		return 'yes';
	}

	return 'no';
}
