<?php
/**
 * Обработчики импорта товаров через WP All Import.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wpai_Casalusso_Import {

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'pmxi_saved_post', array( __CLASS__, 'assign_language_on_import' ), 10, 3 );
		add_action( 'wp_all_import_variable_product_imported', array( __CLASS__, 'variable_product_imported' ), 10, 1 );
	}

	/**
	 * @param int                  $post_id
	 * @param SimpleXMLElement|mixed $xml_node
	 * @param bool                 $is_update
	 * @return void
	 */
	public static function assign_language_on_import( $post_id, $xml_node, $is_update ) {
		global $log_message;

		$log_message  = '';
		$group_id     = '';
		$translations = array();

		$import_id = wp_all_import_get_import_id();

		if ( get_post_type( $post_id ) === 'product' && ! $is_update && ( $import_id == '1' || $import_id == '3' ) ) {
			$log_message .= "========================================\n";

			$xml_node = (array) $xml_node;
			$local    = $xml_node['local'];
			$log_message .= 'xml_node: ' . print_r( $xml_node, true ) . "\n";
			$sku = $xml_node['scu'];

			$log_message .= "sku Текущего товара: {$sku}\n";

			self::set_post_language_directly( $post_id, $local );

			$log_message .= "Ищем товары с таким же SKU: {$sku}\n";
			$existing_products = get_posts(
				array(
					'post_type'  => 'product',
					'meta_query' => array(
						array(
							'key'     => '_sku',
							'value'   => $sku,
							'compare' => '=',
						),
					),
					'exclude'    => array( $post_id ),
				)
			);

			if ( ! empty( $existing_products ) ) {
				$log_message .= "Найдены товары с таким же SKU: \n";
				foreach ( $existing_products as $product ) {
					$product_translations = self::get_post_translations_directly( $product->ID );
					if ( ! empty( $product_translations ) ) {
						$group_id     = $product_translations->name;
						$translations = maybe_unserialize( $product_translations->description );
						$log_message .= "Найдена группа переводов: {$group_id}, translations: " . print_r( $translations, true ) . "\n";
					}
				}
			} else {
				$log_message .= "Товаров с таким же SKU не найдено\n";
			}

			if ( empty( $group_id ) ) {
				$group_id     = uniqid();
				$log_message .= "Группа переводов не найдена, создаем новую: {$group_id}\n";
			}

			$translations[ $local ] = $post_id;

			self::link_post_translations( $post_id, $translations, $group_id );

			self::sync_product_cat_terms_by_sku( $post_id, $sku );
			self::sync_product_tag_terms_by_sku( $post_id, $sku );
			self::sync_product_attribute_terms_by_sku( $post_id, $sku );
			// self::rebuild_yoast_indexable_for_product( $post_id );

			$log_file = WP_CONTENT_DIR . '/uploads/product_taxonomies.log';
			file_put_contents( $log_file, $log_message . "\n", FILE_APPEND | LOCK_EX );
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		$attributes       = $product->get_attributes();
		$attribute_keys   = array_keys( $attributes );
		$display_settings = array();

		foreach ( $attribute_keys as $key => $value ) {
			if ( $value === 'pa_czvet' ) {
				$display_settings['pa_czvet'] = 'yes';
			}
		}

		update_post_meta( $post_id, '_display_with_quantity_attributes', $display_settings );
	}

	/**
	 * @param int $parent_id
	 * @return void
	 */
	public static function variable_product_imported( $parent_id ) {
		global $log_message;

		$log_message = '';

		$import_id = wp_all_import_get_import_id();
		if ( $import_id != '3' ) {
			return;
		}

		$log_message .= "========================================\n";

		$product = wc_get_product( $parent_id );
		if ( ! $product ) {
			return;
		}

		$variation_ids = $product->get_children();
		$log_message    .= 'parent_id=' . $parent_id . ', type=variable, variations=' . print_r( $variation_ids, true ) . "\n";

		$log_file = WP_CONTENT_DIR . '/product_taxonomies.log';
		file_put_contents( $log_file, $log_message . "\n", FILE_APPEND | LOCK_EX );
	}

	/**
	 * @param int $post_id
	 * @return string|false
	 */
	public static function get_post_language_directly( $post_id ) {
		$terms = wp_get_post_terms( $post_id, 'language' );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return false;
		}

		return $terms[0]->slug;
	}

	/**
	 * @param int    $post_id
	 * @param string $language_slug
	 * @return bool
	 */
	public static function set_post_language_directly( $post_id, $language_slug ) {
		$language_term = get_term_by( 'slug', $language_slug, 'language' );

		if ( ! $language_term ) {
			return false;
		}

		$result = wp_set_object_terms( $post_id, $language_term->term_id, 'language' );

		return ! is_wp_error( $result );
	}

	/**
	 * @param int $post_id
	 * @return array|WP_Term
	 */
	public static function get_post_translations_directly( $post_id ) {
		global $log_message;

		$terms = wp_get_post_terms( $post_id, 'post_translations' );
		ob_start();
		var_dump( $terms );
		$log_message .= "terms post_translations for post_id {$post_id}: " . ob_get_clean() . "\n";

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		$term         = $terms[0];
		$log_message .= 'term->description: ' . $term->description . "\n";

		return $term;
	}

	/**
	 * @param int    $post_id
	 * @param array  $translations
	 * @param string $group_name
	 * @return int|false
	 */
	public static function link_post_translations( $post_id, $translations, $group_name = '' ) {
		global $log_message;

		if ( strpos( $group_name, 'pll_' ) === false ) {
			$group_name = 'pll_' . $group_name;
		}

		$log_message .= "group_name: {$group_name}\n";
		$term_result  = get_term_by( 'name', $group_name, 'post_translations' );
		$log_message .= 'term_result: ' . print_r( $term_result, true ) . "\n";

		if ( ! $term_result ) {
			$term_created = wp_insert_term(
				$group_name,
				'post_translations',
				array(
					'description' => maybe_serialize( $translations ),
				)
			);

			$term_result = get_term( $term_created['term_id'], 'post_translations' );
			$log_message .= 'Созданный термин: ' . print_r( $term_result, true ) . "\n";

			if ( is_wp_error( $term_result ) ) {
				ob_start();
				var_dump( $term_result );
				$log_message .= 'Ошибка создания термина: ' . ob_get_clean() . "\n";
				return false;
			}
		} else {
			$wp_update_term = wp_update_term(
				$term_result->term_id,
				'post_translations',
				array(
					'description' => maybe_serialize( $translations ),
				)
			);
			$log_message .= 'Обновлен термин: ' . print_r( $wp_update_term, true ) . "\n";
		}

		$group_term_id = (int) $term_result->term_id;
		$log_message  .= "group_term_id: {$group_term_id}\n";

		wp_set_post_terms( $post_id, array( $group_term_id ), 'post_translations' );
		$log_message .= "Связываем пост {$post_id} с группой переводов {$term_result->name}\n";

		return $group_term_id;
	}

	/**
	 * Язык и связи переводов product_cat для всех товаров с тем же SKU (поле scu в CSV).
	 *
	 * @param int    $post_id
	 * @param string $sku
	 * @return void
	 */
	public static function sync_product_cat_terms_by_sku( $post_id, $sku ) {
		global $log_message;

		if ( ! function_exists( 'pll_set_term_language' ) || ! function_exists( 'pll_save_term_translations' ) ) {
			return;
		}

		if ( ! pll_is_translated_taxonomy( 'product_cat' ) || $sku === '' || $sku === null ) {
			return;
		}

		$products_by_lang = self::get_products_by_sku_for_translations( $post_id, $sku );
		if ( count( $products_by_lang ) < 1 ) {
			return;
		}

		$log_message .= "sync_product_cat_terms_by_sku SKU={$sku}\n";

		$terms_by_lang = array();

		foreach ( $products_by_lang as $lang => $product_id ) {
			$term_ids = self::get_product_cat_term_ids( $product_id );
			if ( empty( $term_ids ) ) {
				continue;
			}

			$term_ids_with_ancestors = array();
			foreach ( $term_ids as $term_id ) {
				foreach ( self::get_term_ids_with_ancestors( $term_id ) as $tid ) {
					self::ensure_term_language( $tid, $lang );
					$term_ids_with_ancestors[] = $tid;
				}
			}
			$term_ids_with_ancestors = array_values( array_unique( $term_ids_with_ancestors ) );

			$terms_by_lang[ $lang ] = $term_ids_with_ancestors;
			$log_message .= "  product {$product_id} ({$lang}) product_cat: " . implode( ',', $term_ids_with_ancestors ) . "\n";
		}

		self::link_product_cat_terms_by_depth( $terms_by_lang );
	}

	/**
	 * Язык и связи переводов product_tag для всех товаров с тем же SKU (поле scu в CSV).
	 *
	 * @param int    $post_id
	 * @param string $sku
	 * @return void
	 */
	public static function sync_product_tag_terms_by_sku( $post_id, $sku ) {
		self::sync_flat_translated_terms_by_sku( $post_id, $sku, 'product_tag' );
	}

	/**
	 * Язык и связи переводов pa_* (атрибуты WooCommerce) для всех товаров с тем же SKU.
	 *
	 * @param int    $post_id
	 * @param string $sku
	 * @return void
	 */
	public static function sync_product_attribute_terms_by_sku( $post_id, $sku ) {
		global $log_message;

		if ( ! function_exists( 'pll_set_term_language' ) || $sku === '' || $sku === null ) {
			return;
		}

		$taxonomies = self::get_translated_product_attribute_taxonomies();
		if ( empty( $taxonomies ) ) {
			return;
		}

		$products_by_lang = self::get_products_by_sku_for_translations( $post_id, $sku );
		if ( count( $products_by_lang ) < 1 ) {
			return;
		}

		$log_message .= "sync_product_attribute_terms_by_sku SKU={$sku}\n";

		foreach ( $taxonomies as $taxonomy ) {
			self::sync_flat_translated_terms_for_products( $products_by_lang, $taxonomy );
		}
	}

	/**
	 * Плоская переводимая таксономия: язык терминов и связи по SKU.
	 *
	 * @param int    $post_id
	 * @param string $sku
	 * @param string $taxonomy
	 * @return void
	 */
	private static function sync_flat_translated_terms_by_sku( $post_id, $sku, $taxonomy ) {
		if ( ! function_exists( 'pll_set_term_language' ) || $sku === '' || $sku === null ) {
			return;
		}

		if ( ! pll_is_translated_taxonomy( $taxonomy ) ) {
			return;
		}

		$products_by_lang = self::get_products_by_sku_for_translations( $post_id, $sku );
		if ( count( $products_by_lang ) < 1 ) {
			return;
		}

		global $log_message;
		$log_message .= 'sync_' . $taxonomy . "_terms_by_sku SKU={$sku}\n";

		self::sync_flat_translated_terms_for_products( $products_by_lang, $taxonomy );
	}

	/**
	 * @param array<string, int> $products_by_lang
	 * @param string             $taxonomy
	 * @return void
	 */
	private static function sync_flat_translated_terms_for_products( array $products_by_lang, $taxonomy ) {
		global $log_message;

		$terms_by_lang = array();

		foreach ( $products_by_lang as $lang => $product_id ) {
			$term_ids = self::get_object_term_ids_ordered( $product_id, $taxonomy );
			if ( empty( $term_ids ) ) {
				continue;
			}

			foreach ( $term_ids as $term_id ) {
				self::ensure_term_language( $term_id, $lang );
			}

			$terms_by_lang[ $lang ] = $term_ids;
			$log_message .= "  product {$product_id} ({$lang}) {$taxonomy}: " . implode( ',', $term_ids ) . "\n";
		}

		if ( ! empty( $terms_by_lang ) ) {
			self::link_flat_terms_by_lang( $terms_by_lang, $taxonomy );
		}
	}

	/**
	 * @return string[] таксономии pa_*, включённые в Polylang
	 */
	private static function get_translated_product_attribute_taxonomies() {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) || ! function_exists( 'pll_is_translated_taxonomy' ) ) {
			return array();
		}

		$taxonomies = array();

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
			if ( pll_is_translated_taxonomy( $taxonomy ) ) {
				$taxonomies[] = $taxonomy;
			}
		}

		return $taxonomies;
	}

	/**
	 * @param int    $post_id
	 * @param string $sku
	 * @return array<string, int> slug языка => ID товара
	 */
	private static function get_products_by_sku_for_translations( $post_id, $sku ) {
		$products_by_lang = array();

		if ( function_exists( 'pll_get_post_translations' ) ) {
			$translations = pll_get_post_translations( $post_id );
			if ( is_array( $translations ) ) {
				$products_by_lang = array_filter( $translations );
			}
		}

		$others = get_posts(
			array(
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_sku',
						'value'   => $sku,
						'compare' => '=',
					),
				),
			)
		);

		foreach ( $others as $other_id ) {
			$lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $other_id ) : '';
			if ( $lang ) {
				$products_by_lang[ $lang ] = (int) $other_id;
			}
		}

		$current_lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post_id ) : '';
		if ( $current_lang ) {
			$products_by_lang[ $current_lang ] = (int) $post_id;
		}

		return $products_by_lang;
	}

	/**
	 * Термин и все предки в product_cat (от листа к корню).
	 *
	 * @param int $term_id
	 * @return int[]
	 */
	private static function get_term_ids_with_ancestors( $term_id ) {
		$ids  = array();
		$term = get_term( (int) $term_id, 'product_cat' );

		while ( $term && ! is_wp_error( $term ) ) {
			$ids[] = (int) $term->term_id;
			if ( ! $term->parent ) {
				break;
			}
			$term = get_term( (int) $term->parent, 'product_cat' );
		}

		return $ids;
	}

	/**
	 * @param int $product_id
	 * @return int[]
	 */
	private static function get_product_cat_term_ids( $product_id ) {
		return self::get_object_term_ids_ordered( $product_id, 'product_cat' );
	}

	/**
	 * @param int    $product_id
	 * @param string $taxonomy
	 * @return int[]
	 */
	private static function get_object_term_ids_ordered( $product_id, $taxonomy ) {
		$terms = wp_get_object_terms(
			$product_id,
			$taxonomy,
			array(
				'fields'  => 'ids',
				'orderby' => 'term_order',
				'order'   => 'ASC',
			)
		);

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		return array_map( 'intval', $terms );
	}

	/**
	 * @param int    $term_id
	 * @param string $lang_slug
	 * @return void
	 */
	private static function ensure_term_language( $term_id, $lang_slug ) {
		$current = function_exists( 'pll_get_term_language' ) ? pll_get_term_language( $term_id ) : '';

		if ( $current === $lang_slug ) {
			return;
		}

		pll_set_term_language( $term_id, $lang_slug );
	}

	/**
	 * Связывает переводы product_cat по уровню вложенности (один термин на язык на уровень).
	 *
	 * @param array<string, int[]> $terms_by_lang
	 * @return void
	 */
	private static function link_product_cat_terms_by_depth( array $terms_by_lang ) {
		global $log_message;

		if ( count( $terms_by_lang ) < 2 ) {
			return;
		}

		$depths = array();
		foreach ( $terms_by_lang as $lang => $term_ids ) {
			foreach ( $term_ids as $term_id ) {
				$depths[ $lang ][ self::get_term_depth( $term_id ) ][] = $term_id;
			}
		}

		$all_depths = array();
		foreach ( $depths as $by_depth ) {
			$all_depths = array_merge( $all_depths, array_keys( $by_depth ) );
		}
		$all_depths = array_unique( $all_depths );
		sort( $all_depths, SORT_NUMERIC );

		foreach ( $all_depths as $depth ) {
			$at_depth = array();
			foreach ( $depths as $lang => $by_depth ) {
				if ( ! empty( $by_depth[ $depth ] ) && count( $by_depth[ $depth ] ) === 1 ) {
					$at_depth[ $lang ] = (int) $by_depth[ $depth ][0];
				}
			}

			if ( count( $at_depth ) < 2 ) {
				continue;
			}

			self::link_term_translations( $at_depth, 'product_cat' );
			$log_message .= '  linked product_cat at depth ' . $depth . ': ' . wp_json_encode( $at_depth, JSON_UNESCAPED_UNICODE ) . "\n";
		}
	}

	/**
	 * Связывает переводы плоской таксономии (product_tag): по одному термину на язык или по term_order.
	 *
	 * @param array<string, int[]> $terms_by_lang
	 * @param string               $taxonomy
	 * @return void
	 */
	private static function link_flat_terms_by_lang( array $terms_by_lang, $taxonomy ) {
		global $log_message;

		if ( count( $terms_by_lang ) < 2 ) {
			return;
		}

		$counts = array_map( 'count', $terms_by_lang );
		$unique = array_unique( $counts );

		if ( count( $unique ) === 1 && reset( $counts ) === 1 ) {
			$at_single = array();
			foreach ( $terms_by_lang as $lang => $term_ids ) {
				$at_single[ $lang ] = (int) $term_ids[0];
			}
			self::link_term_translations( $at_single, $taxonomy );
			$log_message .= '  linked ' . $taxonomy . ' (single): ' . wp_json_encode( $at_single, JSON_UNESCAPED_UNICODE ) . "\n";
			return;
		}

		if ( count( $unique ) !== 1 || reset( $counts ) < 1 ) {
			$log_message .= '  skip link ' . $taxonomy . ' (unequal tag counts per lang)' . "\n";
			return;
		}

		$n     = (int) reset( $counts );
		$langs = array_keys( $terms_by_lang );

		for ( $i = 0; $i < $n; $i++ ) {
			$at_index = array();
			foreach ( $langs as $lang ) {
				$at_index[ $lang ] = (int) $terms_by_lang[ $lang ][ $i ];
			}
			self::link_term_translations( $at_index, $taxonomy );
			$log_message .= '  linked ' . $taxonomy . ' at index ' . $i . ': ' . wp_json_encode( $at_index, JSON_UNESCAPED_UNICODE ) . "\n";
		}
	}

	/**
	 * @param int $term_id
	 * @return int
	 */
	private static function get_term_depth( $term_id ) {
		$depth = 0;
		$term  = get_term( $term_id, 'product_cat' );

		while ( $term && ! is_wp_error( $term ) && $term->parent ) {
			$depth++;
			$term = get_term( (int) $term->parent, 'product_cat' );
		}

		return $depth;
	}

	/**
	 * Связь переводов терминов (term_translations), по аналогии с link_post_translations.
	 *
	 * @param array<string, int> $lang_term_ids slug языка => term_id
	 * @param string             $taxonomy      таксономия для clean_term_cache
	 * @return void
	 */
	private static function link_term_translations( array $lang_term_ids, $taxonomy = 'product_cat' ) {
		global $log_message;

		$lang_term_ids = array_filter( $lang_term_ids );

		if ( count( $lang_term_ids ) < 2 ) {
			return;
		}

		$merged = $lang_term_ids;

		foreach ( $lang_term_ids as $term_id ) {
			if ( function_exists( 'pll_get_term_translations' ) ) {
				$existing = pll_get_term_translations( $term_id );
				if ( is_array( $existing ) ) {
					$merged = array_merge( $existing, $merged );
				}
			}
		}

		$merged = array_filter( $merged );

		$group_name = '';
		foreach ( $merged as $term_id ) {
			$groups = wp_get_object_terms( (int) $term_id, 'term_translations' );
			if ( ! empty( $groups ) && ! is_wp_error( $groups ) ) {
				$group_name = $groups[0]->name;
				break;
			}
		}

		if ( empty( $group_name ) ) {
			$group_name = 'pll_' . uniqid();
		} elseif ( strpos( $group_name, 'pll_' ) !== 0 ) {
			$group_name = 'pll_' . $group_name;
		}

		$term_result = get_term_by( 'name', $group_name, 'term_translations' );

		if ( ! $term_result ) {
			$term_created = wp_insert_term(
				$group_name,
				'term_translations',
				array(
					'description' => maybe_serialize( $merged ),
				)
			);

			if ( is_wp_error( $term_created ) ) {
				$log_message .= '  link_term_translations error: ' . $term_created->get_error_message() . "\n";
				return;
			}

			$term_result = get_term( $term_created['term_id'], 'term_translations' );
		} else {
			$descr = maybe_unserialize( $term_result->description );
			if ( ! is_array( $descr ) ) {
				$descr = array();
			}
			$merged = array_merge( $descr, $merged );
			wp_update_term(
				$term_result->term_id,
				'term_translations',
				array(
					'description' => maybe_serialize( $merged ),
				)
			);
		}

		$group_term_id = (int) $term_result->term_id;

		foreach ( $merged as $tid ) {
			wp_set_object_terms( (int) $tid, array( $group_term_id ), 'term_translations' );
		}

		clean_term_cache( array_values( $merged ), $taxonomy );

		$log_message .= '  link_term_translations group ' . $group_name . ': ' . wp_json_encode( $merged, JSON_UNESCAPED_UNICODE ) . "\n";
	}

	/*
	 * Пересборка Yoast indexable (иерархия product_cat для крошек) — временно отключено.
	 *
	 * @param int $post_id
	 * @return void
	 */
	/*
	private static function rebuild_yoast_indexable_for_product( $post_id ) {
		global $log_message;

		if ( ! function_exists( 'YoastSEO' ) || get_post_type( $post_id ) !== 'product' ) {
			return;
		}

		clean_object_term_cache( (int) $post_id, 'product' );

		try {
			$watcher = YoastSEO()->classes->get(
				\Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher::class
			);
			$watcher->build_indexable( (int) $post_id );
			$log_message .= "rebuild_yoast_indexable post_id={$post_id}\n";
		} catch ( Exception $e ) {
			$log_message .= "rebuild_yoast_indexable post_id={$post_id} error: " . $e->getMessage() . "\n";
		}
	}
	*/
}
