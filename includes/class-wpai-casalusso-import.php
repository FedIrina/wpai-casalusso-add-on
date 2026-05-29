<?php
/**
 * Обработчики импорта товаров через WP All Import.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wpai_Casalusso_Import {

	const PRODUCT_COLLECTION_TAXONOMY = 'collection';
	const VARIATIONS_PARENT_FIX_LOG   = '/uploads/wpai_casalusso_variations_parent_fix.log';
	const VARIATIONS_PARENT_FIX_DEBUG = false;

	/**
	 * Значение атрибута из CSV (имя, не slug) для текущей строки импорта.
	 *
	 * @var string
	 */
	private static $attribute_value_hint = '';

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'create_term', array( __CLASS__, 'set_attribute_term_language_on_create' ), 998, 4 );
		add_filter( 'wp_all_import_term_exists', array( __CLASS__, 'filter_term_exists_by_language' ), 10, 4 );
		add_action( 'pmxi_after_post_import', array( __CLASS__, 'fix_variation_parent_after_row' ), 999, 1 );
		add_action( 'pmxi_saved_post', array( __CLASS__, 'assign_language_on_import' ), 10, 3 );
	}

	/**
	 * Импорт товаров WooCommerce: есть контекст строки CSV в AttachmentHandler.
	 *
	 * @return bool
	 */
	private static function is_woocommerce_product_import_context() {
		if ( ! class_exists( 'Wpai\WordPress\AttachmentHandler' ) || empty( \Wpai\WordPress\AttachmentHandler::$importData ) ) {
			return false;
		}

		$data = \Wpai\WordPress\AttachmentHandler::$importData;

		return ! empty( $data['post_type'] ) && $data['post_type'] === 'product';
	}

	/**
	 * Импорт товаров с колонкой Local (или суффиксом языка в unique_key).
	 *
	 * @return bool
	 */
	private static function is_target_product_import() {
		if ( ! self::is_woocommerce_product_import_context() ) {
			return false;
		}

		return self::row_has_language_context() !== '';
	}

	/**
	 * @param array|SimpleXMLElement|mixed $xml_node
	 * @return string
	 */
	private static function get_local_from_xml_node( $xml_node ) {
		$node = (array) $xml_node;

		foreach ( array( 'local', 'Local', 'LOCAL' ) as $key ) {
			if ( isset( $node[ $key ] ) && $node[ $key ] !== '' ) {
				return strtolower( trim( (string) $node[ $key ] ) );
			}
		}

		return '';
	}

	/**
	 * @param int $post_id
	 * @param int $import_id
	 * @return string
	 */
	private static function row_has_language_context( $post_id = 0, $import_id = 0 ) {
		$post_id   = $post_id ? (int) $post_id : self::get_import_row_post_id();
		$import_id = $import_id ? (int) $import_id : self::get_current_import_id();

		if ( $post_id && $import_id ) {
			$local = self::get_local_for_post_in_import( $post_id, $import_id );
			if ( $local !== '' ) {
				return $local;
			}
		}

		return self::get_local_from_import_context();
	}

	/**
	 * @param int $post_id
	 * @return string
	 */
	private static function row_has_parent_scu( $post_id = 0 ) {
		$post_id = $post_id ? (int) $post_id : self::get_import_row_post_id();

		if ( $post_id ) {
			$parent_sku = get_post_meta( $post_id, '_parent_sku', true );
			if ( $parent_sku !== '' ) {
				return trim( (string) $parent_sku );
			}
		}

		return self::get_parent_scu_from_import_context();
	}

	/**
	 * @param int $variation_id
	 * @param int $import_id
	 * @return bool
	 */
	private static function should_fix_variation_parent( $variation_id, $import_id = 0 ) {
		if ( ! $variation_id || get_post_type( $variation_id ) !== 'product_variation' ) {
			return false;
		}

		if ( ! self::is_woocommerce_product_import_context() ) {
			return false;
		}

		$import_id = $import_id ? (int) $import_id : self::get_current_import_id();

		if ( self::row_has_language_context( $variation_id, $import_id ) === '' ) {
			return false;
		}

		return self::row_has_parent_scu( $variation_id ) !== '';
	}

	/**
	 * @param int                    $post_id
	 * @param array|SimpleXMLElement $xml_node
	 * @param bool                   $is_update
	 * @return bool
	 */
	private static function should_apply_polylang_on_product_create( $post_id, $xml_node, $is_update ) {
		if ( $is_update || get_post_type( $post_id ) !== 'product' ) {
			return false;
		}

		if ( ! self::is_woocommerce_product_import_context() ) {
			return false;
		}

		return self::get_local_from_xml_node( $xml_node ) !== '';
	}

	/**
	 * ID текущего импорта (во время прогона wp_all_import_get_import_id() часто возвращает "new").
	 *
	 * @return int
	 */
	private static function get_current_import_id() {
		if ( class_exists( 'Wpai\WordPress\AttachmentHandler' ) && ! empty( \Wpai\WordPress\AttachmentHandler::$importData['import'] ) ) {
			$import = \Wpai\WordPress\AttachmentHandler::$importData['import'];
			if ( is_object( $import ) && ! empty( $import->id ) ) {
				return (int) $import->id;
			}
		}

		if ( class_exists( 'PMXI_Plugin' ) && method_exists( 'PMXI_Plugin', 'getCurrentImportId' ) ) {
			$import_id = PMXI_Plugin::getCurrentImportId();
			if ( $import_id ) {
				return (int) $import_id;
			}
		}

		$import_id = wp_all_import_get_import_id();

		return is_numeric( $import_id ) ? (int) $import_id : 0;
	}

	/**
	 * Лог для диагностики привязки вариаций (импорт #3).
	 *
	 * @param string $message
	 * @return void
	 */
	private static function log_variation_parent_fix( $message ) {
		if ( ! self::VARIATIONS_PARENT_FIX_DEBUG ) {
			return;
		}

		$path = WP_CONTENT_DIR . self::VARIATIONS_PARENT_FIX_LOG;
		file_put_contents( $path, $message, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Собрать краткую строку контекста строки импорта.
	 *
	 * @param int $post_id
	 * @param int $import_id
	 * @return string
	 */
	private static function get_variation_fix_context_line( $post_id, $import_id ) {
		$unique_key = '';
		if ( class_exists( 'PMXI_Post_Record' ) ) {
			$r = new \PMXI_Post_Record();
			$r->clear();
			$r->getBy(
				array(
					'import_id' => (int) $import_id,
					'post_id'   => (int) $post_id,
				)
			);
			if ( ! $r->isEmpty() ) {
				$unique_key = (string) $r->unique_key;
			}
		}

		$local      = self::get_local_for_post_in_import( $post_id, $import_id );
		$parent_scu = get_post_meta( $post_id, '_parent_sku', true );
		$parent_scu = $parent_scu !== '' ? trim( (string) $parent_scu ) : '';

		return 'uk=' . $unique_key . ' local=' . $local . ' _parent_sku=' . $parent_scu;
	}

	/**
	 * ID текущей строки импорта (WooCommerce Add-On).
	 *
	 * @return int
	 */
	private static function get_import_row_post_id() {
		if ( ! class_exists( 'Wpai\WordPress\AttachmentHandler' ) ) {
			return 0;
		}

		return (int) ( \Wpai\WordPress\AttachmentHandler::$importData['pid'] ?? 0 );
	}

	/**
	 * Local по unique_key записи в pmxi_posts (119070en → en).
	 *
	 * @param int $post_id
	 * @param int $import_id
	 * @return string
	 */
	private static function get_local_for_post_in_import( $post_id, $import_id ) {
		if ( ! $post_id || ! $import_id || ! class_exists( 'PMXI_Post_Record' ) ) {
			return '';
		}

		$post_record = new \PMXI_Post_Record();
		$post_record->clear();
		$post_record->getBy(
			array(
				'import_id' => $import_id,
				'post_id'   => $post_id,
			)
		);

		if ( $post_record->isEmpty() || ! preg_match( '/(ru|en|tr|de)$/i', $post_record->unique_key, $matches ) ) {
			return '';
		}

		return strtolower( $matches[1] );
	}

	/**
	 * Local из current_xml_node, иначе суффикс unique_key (119070en → en).
	 *
	 * @return string
	 */
	private static function get_local_from_import_context() {
		if ( class_exists( 'Wpai\WordPress\AttachmentHandler' ) && ! empty( \Wpai\WordPress\AttachmentHandler::$importData['current_xml_node'] ) ) {
			$node = (array) \Wpai\WordPress\AttachmentHandler::$importData['current_xml_node'];

			foreach ( array( 'local', 'Local', 'LOCAL' ) as $key ) {
				if ( isset( $node[ $key ] ) && $node[ $key ] !== '' ) {
					return strtolower( trim( (string) $node[ $key ] ) );
				}
			}
		}

		$post_id   = self::get_import_row_post_id();
		$import_id = self::get_current_import_id();
		if ( $post_id && $import_id ) {
			$local = self::get_local_for_post_in_import( $post_id, $import_id );
			if ( $local !== '' ) {
				return $local;
			}
		}

		return '';
	}

	/**
	 * Parent_SCU из current_xml_node, иначе meta _parent_sku.
	 *
	 * @return string
	 */
	private static function get_parent_scu_from_import_context() {
		if ( class_exists( 'Wpai\WordPress\AttachmentHandler' ) && ! empty( \Wpai\WordPress\AttachmentHandler::$importData['current_xml_node'] ) ) {
			$node = (array) \Wpai\WordPress\AttachmentHandler::$importData['current_xml_node'];

			foreach ( array( 'parent_scu', 'Parent_SCU', 'PARENT_SCU' ) as $key ) {
				if ( isset( $node[ $key ] ) && $node[ $key ] !== '' ) {
					return trim( (string) $node[ $key ] );
				}
			}
		}

		$post_id = self::get_import_row_post_id();
		if ( $post_id ) {
			$parent_sku = get_post_meta( $post_id, '_parent_sku', true );
			if ( $parent_sku !== '' ) {
				return trim( (string) $parent_sku );
			}
		}

		return '';
	}

	/**
	 * Родитель variable product по SKU и языку строки (импорт #3).
	 *
	 * @param string $sku
	 * @param string $local
	 * @return int
	 */
	private static function find_variable_parent_by_sku_and_language( $sku, $local ) {
		$posts = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
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

		if ( empty( $posts ) ) {
			return 0;
		}

		if ( ! function_exists( 'pll_get_post_language' ) ) {
			return (int) $posts[0];
		}

		foreach ( $posts as $post_id ) {
			if ( pll_get_post_language( $post_id ) === $local ) {
				return (int) $post_id;
			}
		}

		return 0;
	}

	/**
	 * Родитель variable product по Parent_SCU и языку строки.
	 *
	 * @param int $post_id
	 * @param int $import_id
	 * @return int
	 */
	private static function resolve_variable_parent_for_post( $post_id, $import_id ) {
		if ( ! $post_id ) {
			return 0;
		}

		$import_id = $import_id ? (int) $import_id : self::get_current_import_id();
		$local     = self::row_has_language_context( $post_id, $import_id );
		$parent_scu = self::row_has_parent_scu( $post_id );

		if ( $local === '' || $parent_scu === '' ) {
			return 0;
		}

		return self::find_variable_parent_by_sku_and_language( $parent_scu, $local );
	}

	/**
	 * Исправляем post_parent и язык вариации после импорта вариации WooCommerce Add-On'ом.
	 * WPAI ничего не знает о языковых версиях вариаций, поэтому после импорта вариации WooCommerce Add-On'ом
	 * мы должны исправить post_parent и язык вариации напрямую в wp_posts вместо того, 
	 * чтобы использовать WC_Product_Variation::save().
	 *
	 * @param int $variation_id
	 * @param int $import_id
	 * @return void
	 */
	private static function apply_variation_parent_fix( $variation_id, $import_id ) {
		if ( ! self::should_fix_variation_parent( $variation_id, $import_id ) ) {
			return;
		}

		$import_id     = $import_id ? (int) $import_id : self::get_current_import_id();
		$before_parent = (int) wp_get_post_parent_id( $variation_id );
		$local         = self::row_has_language_context( $variation_id, $import_id );

		$parent_id = self::resolve_variable_parent_for_post( $variation_id, $import_id );
		if ( ! $parent_id ) {
			self::log_variation_parent_fix(
				date( 'Y-m-d H:i:s' ) . ' import=' . (int) $import_id . ' variation=' . (int) $variation_id
				. ' before_parent=' . $before_parent . ' resolved_parent=0'
				. ' hook_ctx={' . self::get_variation_fix_context_line( $variation_id, $import_id ) . "}\n"
			);
			return;
		}

		if ( $local !== '' && function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $variation_id, $local );
		}

		$current_parent = (int) wp_get_post_parent_id( $variation_id );
		if ( $current_parent !== $parent_id ) {
			global $wpdb;

			// Пишем родителя вариации напрямую в wp_posts вместо variation->save, 
			// так как при импорте WC_Product_Variation::save() после set_parent_id()
			// снова подставляет post_parent первого попавшегося родителя с тем же SKU, не взирая на локаль.
			$wpdb->update(
				$wpdb->posts,
				array( 'post_parent' => $parent_id ),
				array( 'ID' => $variation_id ),
				array( '%d' ),
				array( '%d' )
			);

			clean_post_cache( $variation_id );
			wc_delete_product_transients( $parent_id );
			if ( $current_parent ) {
				wc_delete_product_transients( $current_parent );
			}
		}

		$after_parent = (int) wp_get_post_parent_id( $variation_id );
		self::log_variation_parent_fix(
			date( 'Y-m-d H:i:s' ) . ' import=' . (int) $import_id . ' variation=' . (int) $variation_id
			. ' before_parent=' . $before_parent . ' resolved_parent=' . (int) $parent_id . ' after_parent=' . $after_parent
			. ' hook_ctx={' . self::get_variation_fix_context_line( $variation_id, $import_id ) . "}\n"
		);
	}

	/**
	 * В конце строки CSV (запасной хук, если pmxi_update_product_variation не сработал).
	 *
	 * @param int $import_id
	 * @return void
	 */
	public static function fix_variation_parent_after_row( $import_id ) {
		$import_id = (int) $import_id;
		if ( ! $import_id ) {
			$import_id = self::get_current_import_id();
		}
		$post_id = self::get_import_row_post_id();

		if ( ! self::should_fix_variation_parent( $post_id, $import_id ) ) {
			return;
		}

		self::log_variation_parent_fix(
			date( 'Y-m-d H:i:s' ) . ' hook=pmxi_after_post_import import=' . (int) $import_id
			. ' pid=' . (int) $post_id . "\n"
		);
		self::apply_variation_parent_fix( $post_id, $import_id );
	}

	/**
	 * term_id из результата get_term_by / is_exists_term / wp_all_import_term_exists.
	 *
	 * @param mixed $term
	 * @return int
	 */
	private static function normalize_term_exists_result( $term ) {
		if ( is_array( $term ) && ! empty( $term['term_id'] ) ) {
			return (int) $term['term_id'];
		}

		if ( is_object( $term ) && isset( $term->term_id ) ) {
			return (int) $term->term_id;
		}

		return 0;
	}

	/**
	 * @param string $message
	 * @return void
	 */
	private static function log_attribute_term_message( $message ) {
		$log_file = WP_CONTENT_DIR . '/uploads/product_taxonomies.log';
		file_put_contents( $log_file, $message, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Сразу после wp_insert_term: язык pa_* из Local до Polylang (create_term @ 999).
	 *
	 * @param int    $term_id
	 * @param int    $tt_id
	 * @param string $taxonomy
	 * @param array  $args
	 * @return void
	 */
	public static function set_attribute_term_language_on_create( $term_id, $tt_id, $taxonomy, $args ) {
		if ( strpos( $taxonomy, 'pa_' ) !== 0 ) {
			return;
		}

		if ( ! self::is_target_product_import() || ! function_exists( 'pll_set_term_language' ) ) {
			return;
		}

		$local = self::get_local_from_import_context();
		if ( $local === '' ) {
			return;
		}

		$before = function_exists( 'pll_get_term_language' ) ? pll_get_term_language( $term_id ) : '';
		pll_set_term_language( (int) $term_id, $local );
		$after = pll_get_term_language( $term_id );

		if ( $before !== $after ) {
			self::log_attribute_term_message(
				"  create_term {$taxonomy} #{$term_id}: " . ( $before ? $before : '(none)' ) . " -> {$after}\n"
			);
		}
	}

	/**
	 * Не использовать найденный pa_*-термин, если его язык не совпадает с Local строки.
	 *
	 * @param array|false|object $term
	 * @param string             $taxonomy
	 * @param string             $term_name_or_slug
	 * @param int|null           $parent
	 * @return array|false|object
	 */
	public static function filter_term_exists_by_language( $term, $taxonomy, $term_name_or_slug, $parent ) {
		if ( strpos( $taxonomy, 'pa_' ) !== 0 ) {
			return $term;
		}

		if ( ! self::is_target_product_import() ) {
			return $term;
		}

		static $last_import_pid = 0;
		$current_pid           = (int) ( \Wpai\WordPress\AttachmentHandler::$importData['pid'] ?? 0 );
		if ( $current_pid !== $last_import_pid ) {
			self::$attribute_value_hint = '';
			$last_import_pid            = $current_pid;
		}

		if ( empty( $term ) || is_wp_error( $term ) ) {
			return $term;
		}

		if ( ! function_exists( 'pll_get_term_language' ) ) {
			return $term;
		}

		$local = self::get_local_from_import_context();
		if ( $local === '' ) {
			return $term;
		}

		$term_id = self::normalize_term_exists_result( $term );
		if ( ! $term_id ) {
			return $term;
		}

		if ( self::looks_like_attribute_display_value( $term_name_or_slug ) ) {
			self::$attribute_value_hint = $term_name_or_slug;
		}

		$term_lang      = pll_get_term_language( $term_id );
		$expected_slug  = self::get_csv_attribute_slug_for_row( $term_name_or_slug );
		$found_term     = get_term( $term_id, $taxonomy );
		$found_slug     = ( $found_term && ! is_wp_error( $found_term ) ) ? $found_term->slug : '';

		if ( $term_lang && $term_lang !== $local ) {
			self::log_attribute_term_message(
				"  skip existing {$taxonomy} #{$term_id} (lang {$term_lang}) for Local {$local}, value \"{$term_name_or_slug}\"\n"
			);

			$resolved = self::resolve_or_create_attribute_term_for_language( $term_id, $taxonomy, $local, $term_name_or_slug );
			if ( $resolved ) {
				return $resolved;
			}

			return false;
		}

		// Язык совпал, но slug из CSV (значение атрибута) другой — не переиспользовать, создать с нужным slug.
		if ( $expected_slug !== '' && $found_slug !== '' && $found_slug !== $expected_slug ) {
			self::log_attribute_term_message(
				"  skip existing {$taxonomy} #{$term_id} slug \"{$found_slug}\" != expected \"{$expected_slug}\" (Local {$local})\n"
			);

			$resolved = self::resolve_or_create_attribute_term_for_language( $term_id, $taxonomy, $local, $term_name_or_slug, $expected_slug );
			if ( $resolved ) {
				return $resolved;
			}

			return false;
		}

		return $term;
	}

	/**
	 * Slug как у WPAI: sanitize_title( значение атрибута из CSV ).
	 *
	 * @param string $name_hint имя или slug из цепочки поиска WPAI
	 * @return string
	 */
	private static function get_csv_attribute_slug_for_row( $name_hint ) {
		$name = self::$attribute_value_hint;

		if ( $name === '' && self::looks_like_attribute_display_value( $name_hint ) ) {
			$name = $name_hint;
		}

		$name = trim( (string) $name );
		if ( $name === '' ) {
			return '';
		}

		return sanitize_title( str_replace( '#', '_', $name ) );
	}

	/**
	 * @param string $value
	 * @return bool
	 */
	private static function looks_like_attribute_display_value( $value ) {
		$value = trim( (string) $value );

		if ( $value === '' ) {
			return false;
		}

		return (bool) preg_match( '/\s/u', $value ) || (bool) preg_match( '/[^\x00-\x7F]/u', $value );
	}

	/**
	 * Перевод pa_* для Local или новый термин с тем же именем и slug -{lang}.
	 *
	 * @param int    $existing_term_id найденный термин «чужого» языка
	 * @param string $taxonomy
	 * @param string $local
	 * @param string $name_hint имя или slug из WPAI
	 * @param string $forced_slug явный slug (если отличается от найденного)
	 * @return array|false массив как у wp_insert_term
	 */
	private static function resolve_or_create_attribute_term_for_language( $existing_term_id, $taxonomy, $local, $name_hint, $forced_slug = '' ) {
		static $cache = array();

		$cache_key = $taxonomy . ':' . (int) $existing_term_id . ':' . $local;
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		if ( function_exists( 'pll_get_term' ) ) {
			$translated_id = (int) pll_get_term( (int) $existing_term_id, $local );
			if ( $translated_id ) {
				$cache[ $cache_key ] = array( 'term_id' => $translated_id );
				self::log_attribute_term_message(
					"  use translation {$taxonomy} #{$translated_id} ({$local}) of #{$existing_term_id}\n"
				);
				return $cache[ $cache_key ];
			}
		}

		$existing = get_term( (int) $existing_term_id, $taxonomy );
		$name     = self::$attribute_value_hint;
		if ( $name === '' && $existing && ! is_wp_error( $existing ) ) {
			$name = $existing->name;
		}
		if ( $name === '' ) {
			$name = $name_hint;
		}
		$name = trim( (string) $name );

		if ( $name === '' ) {
			$cache[ $cache_key ] = false;
			return false;
		}

		$base_slug = sanitize_title( str_replace( '#', '_', $name ) );
		if ( $forced_slug !== '' ) {
			$slug = $forced_slug;
		} else {
			$slug = $base_slug . '-' . $local;
		}

		$by_slug = get_term_by( 'slug', $slug, $taxonomy );
		if ( $by_slug && ! is_wp_error( $by_slug ) ) {
			$found_id = (int) $by_slug->term_id;
			$found_lang = function_exists( 'pll_get_term_language' ) ? pll_get_term_language( $found_id ) : '';
			if ( $found_lang === $local || $found_lang === '' ) {
				if ( $found_lang === '' && function_exists( 'pll_set_term_language' ) ) {
					pll_set_term_language( $found_id, $local );
				}
				$cache[ $cache_key ] = array( 'term_id' => $found_id );
				self::log_attribute_term_message(
					"  reuse {$taxonomy} #{$found_id} slug {$slug} ({$local})\n"
				);
				return $cache[ $cache_key ];
			}
		}

		if ( function_exists( 'pll_insert_term' ) ) {
			$result = pll_insert_term( $name, $taxonomy, $local, array( 'slug' => $slug ) );
		} else {
			$result = null;
		}

		if ( ! is_array( $result ) ) {
			$result = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
			if ( ! is_wp_error( $result ) && function_exists( 'pll_set_term_language' ) ) {
				pll_set_term_language( (int) $result['term_id'], $local );
			}
		}

		if ( is_wp_error( $result ) ) {
			self::log_attribute_term_message(
				'  create ' . $taxonomy . ' for ' . $local . ' failed: ' . $result->get_error_message() . "\n"
			);
			$cache[ $cache_key ] = false;
			return false;
		}

		$cache[ $cache_key ] = $result;
		self::log_attribute_term_message(
			"  created {$taxonomy} #{$result['term_id']} lang {$local} slug {$slug} name \"{$name}\"\n"
		);

		return $result;
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

		if ( self::should_apply_polylang_on_product_create( $post_id, $xml_node, $is_update ) ) {
			$log_message .= "========================================\n";

			$xml_node = (array) $xml_node;
			$local    = self::get_local_from_xml_node( $xml_node );
			$log_message .= 'xml_node: ' . print_r( $xml_node, true ) . "\n";
			$sku = $xml_node['scu'];

			$log_message .= "sku Текущего товара: {$sku}\n";

			self::set_post_language_directly( $post_id, $local );

			self::assign_product_tags_from_import( $post_id, $local, $xml_node );
			self::assign_product_categories_from_import( $post_id, $local, $xml_node );
			self::assign_product_collections_from_import( $post_id, $local, $xml_node );

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

			self::ensure_polylang_taxonomies_for_product( $post_id );
			self::sync_product_cat_terms_by_sku( $post_id, $sku );
			self::sync_product_tag_terms_by_sku( $post_id, $sku );
			self::sync_product_collection_terms_by_sku( $post_id, $sku );
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
	 * @return bool
	 */
	private static function is_product_collection_taxonomy_active() {
		return taxonomy_exists( self::PRODUCT_COLLECTION_TAXONOMY );
	}

	/**
	 * Включена ли опция woo-poly (Settings → WooPoly → Features).
	 *
	 * @param string $feature categories|tags|attributes
	 * @return bool
	 */
	private static function is_woopoly_feature_enabled( $feature ) {
		return 'on' === \Hyyan\WPI\Admin\Settings::getOption(
			$feature,
			\Hyyan\WPI\Admin\Features::getID(),
			'on'
		);
	}

	/**
	 * Добавляет таксономию в переводимые Polylang, если ещё не включена (опция taxonomies).
	 *
	 * @param string $taxonomy
	 * @return bool true, если таксономия была добавлена
	 */
	private static function enable_polylang_translated_taxonomy( $taxonomy ) {
		if ( pll_is_translated_taxonomy( $taxonomy ) ) {
			return false;
		}

		if ( ! function_exists( 'PLL' ) || ! PLL() ) {
			return false;
		}

		$taxonomies = PLL()->options['taxonomies'];
		if ( ! is_array( $taxonomies ) ) {
			$taxonomies = array();
		}

		if ( in_array( $taxonomy, $taxonomies, true ) ) {
			PLL()->model->cache->clean( 'taxonomies' );
			return false;
		}

		PLL()->options['taxonomies'] = array_merge( $taxonomies, array( $taxonomy ) );
		PLL()->model->cache->clean( 'taxonomies' );

		return true;
	}

	/**
	 * Создаёт и назначает product_tag из поля CSV (Тэги), если WPAI не сделал этого.
	 *
	 * @param int    $post_id
	 * @param string $lang_slug
	 * @param array  $xml_node
	 * @return void
	 */
	private static function assign_product_tags_from_import( $post_id, $lang_slug, array $xml_node ) {
		global $log_message;

		if ( ! self::is_woopoly_feature_enabled( 'tags' ) ) {
			return;
		}

		$tag_names = self::get_import_tag_names_from_xml_node( $xml_node );
		if ( empty( $tag_names ) ) {
			return;
		}

		self::enable_polylang_translated_taxonomy( 'product_tag' );

		$term_ids = array();
		foreach ( $tag_names as $name ) {
			$term_id = self::get_or_create_flat_term( 'product_tag', $name, $lang_slug );
			if ( $term_id ) {
				$term_ids[] = $term_id;
			}
		}

		if ( empty( $term_ids ) ) {
			return;
		}

		wp_set_object_terms( $post_id, $term_ids, 'product_tag' );
		$log_message .= "assign_product_tags post_id={$post_id} lang={$lang_slug}: " . implode( ',', $term_ids ) . "\n";
	}

	/**
	 * @param array $xml_node
	 * @return string[]
	 */
	private static function get_import_tag_names_from_xml_node( array $xml_node ) {
		$keys = array( 'tegi', 'tyegi', 'tags', 'tag', 'тэги' );

		foreach ( $xml_node as $key => $value ) {
			$normalized = mb_strtolower( trim( (string) $key ) );
			if ( in_array( $normalized, $keys, true ) && $value !== '' && $value !== null ) {
				return self::parse_import_tag_names( $value );
			}
		}

		return array();
	}

	/**
	 * @param mixed $raw
	 * @return string[]
	 */
	private static function parse_import_tag_names( $raw ) {
		$parts = array_map( 'trim', explode( ',', (string) $raw ) );

		return array_values( array_filter( $parts ) );
	}

	/**
	 * Создаёт и назначает collection из поля CSV (Collection).
	 *
	 * @param int    $post_id
	 * @param string $lang_slug
	 * @param array  $xml_node
	 * @return void
	 */
	private static function assign_product_collections_from_import( $post_id, $lang_slug, array $xml_node ) {
		global $log_message;

		if ( ! self::is_product_collection_taxonomy_active() ) {
			return;
		}

		$collection_names = self::get_import_collection_names_from_xml_node( $xml_node );
		if ( empty( $collection_names ) ) {
			return;
		}

		self::enable_polylang_translated_taxonomy( self::PRODUCT_COLLECTION_TAXONOMY );

		$term_ids = array();
		foreach ( $collection_names as $name ) {
			$term_id = self::get_or_create_flat_term( self::PRODUCT_COLLECTION_TAXONOMY, $name, $lang_slug );
			if ( $term_id ) {
				$term_ids[] = $term_id;
			}
		}

		if ( empty( $term_ids ) ) {
			return;
		}

		wp_set_object_terms( $post_id, $term_ids, self::PRODUCT_COLLECTION_TAXONOMY );
		$log_message .= "assign_product_collections post_id={$post_id} lang={$lang_slug}: " . implode( ',', $term_ids ) . "\n";
	}

	/**
	 * @param array $xml_node
	 * @return string[]
	 */
	private static function get_import_collection_names_from_xml_node( array $xml_node ) {
		$keys = array( 'collection', 'collections', 'коллекция', 'коллекции' );

		foreach ( $xml_node as $key => $value ) {
			$normalized = mb_strtolower( trim( (string) $key ) );
			if ( in_array( $normalized, $keys, true ) && $value !== '' && $value !== null ) {
				return self::parse_import_tag_names( $value );
			}
		}

		return array();
	}

	/**
	 * @param string $taxonomy
	 * @param string $name
	 * @param string $lang_slug
	 * @return int
	 */
	private static function get_or_create_flat_term( $taxonomy, $name, $lang_slug ) {
		global $log_message;

		$existing = get_term_by( 'name', $name, $taxonomy );
		if ( $existing && ! is_wp_error( $existing ) ) {
			$term_lang = pll_get_term_language( $existing->term_id );
			if ( ! $term_lang || $term_lang === $lang_slug ) {
				self::ensure_term_language( (int) $existing->term_id, $lang_slug );
				return (int) $existing->term_id;
			}
		}

		$slug   = sanitize_title( $name );
		$result = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'slug' => $slug,
			)
		);

		if ( is_wp_error( $result ) ) {
			if ( 'term_exists' === $result->get_error_code() ) {
				$term_id   = (int) $result->get_error_data();
				$term_lang = pll_get_term_language( $term_id );
				if ( $term_lang && $term_lang !== $lang_slug ) {
					$result = wp_insert_term(
						$name,
						$taxonomy,
						array(
							'slug' => sanitize_title( $slug . '-' . $lang_slug ),
						)
					);
					if ( is_wp_error( $result ) ) {
						$log_message .= "  {$taxonomy} insert error: " . $result->get_error_message() . "\n";
						return 0;
					}
					$term_id = (int) $result['term_id'];
				}
			} else {
				$log_message .= "  {$taxonomy} insert error: " . $result->get_error_message() . "\n";
				return 0;
			}
		} else {
			$term_id = (int) $result['term_id'];
		}

		self::ensure_term_language( $term_id, $lang_slug );

		return $term_id;
	}

	/**
	 * Создаёт иерархию product_cat и назначает листовую категорию из поля CSV (Category).
	 *
	 * @param int    $post_id
	 * @param string $lang_slug
	 * @param array  $xml_node
	 * @return void
	 */
	private static function assign_product_categories_from_import( $post_id, $lang_slug, array $xml_node ) {
		global $log_message;

		if ( ! self::is_woopoly_feature_enabled( 'categories' ) ) {
			return;
		}

		$category_paths = self::get_import_category_paths_from_xml_node( $xml_node );
		if ( empty( $category_paths ) ) {
			return;
		}

		self::enable_polylang_translated_taxonomy( 'product_cat' );

		$leaf_term_ids = array();
		foreach ( $category_paths as $segments ) {
			$term_id = self::get_or_create_product_cat_path_term( $segments, $lang_slug );
			if ( $term_id ) {
				$leaf_term_ids[] = $term_id;
			}
		}

		if ( empty( $leaf_term_ids ) ) {
			return;
		}

		wp_set_object_terms( $post_id, $leaf_term_ids, 'product_cat' );
		$log_message .= "assign_product_categories post_id={$post_id} lang={$lang_slug}: " . implode( ',', $leaf_term_ids ) . "\n";
	}

	/**
	 * @param array $xml_node
	 * @return string[][] сегменты цепочек «Родитель > … > Лист»
	 */
	private static function get_import_category_paths_from_xml_node( array $xml_node ) {
		$keys = array( 'category', 'categories', 'категория', 'категории' );

		foreach ( $xml_node as $key => $value ) {
			$normalized = mb_strtolower( trim( (string) $key ) );
			if ( in_array( $normalized, $keys, true ) && $value !== '' && $value !== null ) {
				return self::parse_import_category_paths( $value );
			}
		}

		return array();
	}

	/**
	 * @param mixed $raw
	 * @return string[][]
	 */
	private static function parse_import_category_paths( $raw ) {
		$paths  = array_map( 'trim', explode( ',', (string) $raw ) );
		$result = array();

		foreach ( $paths as $path ) {
			if ( $path === '' ) {
				continue;
			}

			$segments = array_map( 'trim', explode( '>', $path ) );
			$segments = array_values( array_filter( $segments ) );
			if ( ! empty( $segments ) ) {
				$result[] = $segments;
			}
		}

		return $result;
	}

	/**
	 * @param string[] $segments
	 * @param string   $lang_slug
	 * @return int ID листового термина
	 */
	private static function get_or_create_product_cat_path_term( array $segments, $lang_slug ) {
		$parent_id = 0;
		$term_id   = 0;

		foreach ( $segments as $name ) {
			$term_id = self::get_or_create_product_cat_term( $name, $lang_slug, $parent_id );
			if ( ! $term_id ) {
				return 0;
			}
			$parent_id = $term_id;
		}

		return $term_id;
	}

	/**
	 * @param string $name
	 * @param string $lang_slug
	 * @param int    $parent_id
	 * @return int
	 */
	private static function get_or_create_product_cat_term( $name, $lang_slug, $parent_id ) {
		global $log_message;

		$existing_terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'name'       => $name,
				'parent'     => $parent_id,
				'hide_empty' => false,
				'number'     => 1,
			)
		);

		if ( ! empty( $existing_terms ) && ! is_wp_error( $existing_terms ) ) {
			$term      = $existing_terms[0];
			$term_lang = pll_get_term_language( $term->term_id );
			if ( ! $term_lang || $term_lang === $lang_slug ) {
				self::ensure_term_language( (int) $term->term_id, $lang_slug );
				return (int) $term->term_id;
			}
		}

		$slug   = sanitize_title( $name );
		$result = wp_insert_term(
			$name,
			'product_cat',
			array(
				'slug'   => $slug,
				'parent' => $parent_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			if ( 'term_exists' === $result->get_error_code() ) {
				$term_id   = (int) $result->get_error_data();
				$term      = get_term( $term_id, 'product_cat' );
				$term_lang = pll_get_term_language( $term_id );

				if ( $term && ! is_wp_error( $term ) && (int) $term->parent === (int) $parent_id ) {
					if ( ! $term_lang || $term_lang === $lang_slug ) {
						self::ensure_term_language( $term_id, $lang_slug );
						return $term_id;
					}
				}

				$result = wp_insert_term(
					$name,
					'product_cat',
					array(
						'slug'   => sanitize_title( $slug . '-' . $lang_slug ),
						'parent' => $parent_id,
					)
				);
				if ( is_wp_error( $result ) ) {
					$log_message .= '  category insert error: ' . $result->get_error_message() . "\n";
					return 0;
				}
				$term_id = (int) $result['term_id'];
			} else {
				$log_message .= '  category insert error: ' . $result->get_error_message() . "\n";
				return 0;
			}
		} else {
			$term_id = (int) $result['term_id'];
		}

		self::ensure_term_language( $term_id, $lang_slug );

		return $term_id;
	}

	/**
	 * Включает в Polylang перевод таксономий товара, если это разрешено соответствующими опциями woo-poly.
	 *
	 * @param int $post_id
	 * @return void
	 */
	private static function ensure_polylang_taxonomies_for_product( $post_id ) {
		global $log_message;

		if ( self::is_woopoly_feature_enabled( 'categories' ) && ! empty( self::get_product_cat_term_ids( $post_id ) ) ) {
			if ( self::enable_polylang_translated_taxonomy( 'product_cat' ) ) {
				$log_message .= "enabled polylang translation for product_cat\n";
			}
		}

		if ( self::is_woopoly_feature_enabled( 'tags' ) && ! empty( self::get_object_term_ids_ordered( $post_id, 'product_tag' ) ) ) {
			if ( self::enable_polylang_translated_taxonomy( 'product_tag' ) ) {
				$log_message .= "enabled polylang translation for product_tag\n";
			}
		}

		if ( self::is_product_collection_taxonomy_active() && ! empty( self::get_object_term_ids_ordered( $post_id, self::PRODUCT_COLLECTION_TAXONOMY ) ) ) {
			if ( self::enable_polylang_translated_taxonomy( self::PRODUCT_COLLECTION_TAXONOMY ) ) {
				$log_message .= 'enabled polylang translation for ' . self::PRODUCT_COLLECTION_TAXONOMY . "\n";
			}
		}

		if ( self::is_woopoly_feature_enabled( 'attributes' ) ) {
			$product = wc_get_product( $post_id );
			if ( $product ) {
				foreach ( $product->get_attributes() as $attribute ) {
					if ( ! $attribute->is_taxonomy() ) {
						continue;
					}

					$taxonomy = $attribute->get_name();
					if ( self::enable_polylang_translated_taxonomy( $taxonomy ) ) {
						$log_message .= "enabled polylang translation for {$taxonomy}\n";
					}
				}
			}
		}
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

		if ( ! self::is_woopoly_feature_enabled( 'categories' ) ) {
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
		if ( ! self::is_woopoly_feature_enabled( 'tags' ) ) {
			return;
		}

		self::sync_flat_translated_terms_by_sku( $post_id, $sku, 'product_tag' );
	}

	/**
	 * Язык и связи переводов collection для всех товаров с тем же SKU.
	 *
	 * @param int    $post_id
	 * @param string $sku
	 * @return void
	 */
	public static function sync_product_collection_terms_by_sku( $post_id, $sku ) {
		if ( ! self::is_product_collection_taxonomy_active() ) {
			return;
		}

		self::sync_flat_translated_terms_by_sku( $post_id, $sku, self::PRODUCT_COLLECTION_TAXONOMY );
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

		if ( ! self::is_woopoly_feature_enabled( 'attributes' ) ) {
			return;
		}

		if ( $sku === '' || $sku === null ) {
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
		if ( $sku === '' || $sku === null ) {
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

		$terms_by_lang   = array();
		$is_pa_attribute = ( strpos( $taxonomy, 'pa_' ) === 0 );

		foreach ( $products_by_lang as $lang => $product_id ) {
			$term_ids = self::get_object_term_ids_ordered( $product_id, $taxonomy );
			if ( empty( $term_ids ) ) {
				continue;
			}

			$resolved_ids  = array();
			$needs_reassign = false;

			foreach ( $term_ids as $term_id ) {
				$term_id = (int) $term_id;
				if ( $is_pa_attribute ) {
					$resolved = self::resolve_term_id_for_product_language( $term_id, $lang );
					if ( $resolved && $resolved !== $term_id ) {
						$needs_reassign = true;
					}
					if ( $resolved ) {
						$term_id = $resolved;
					}
				}

				self::ensure_term_language( $term_id, $lang, $product_id );
				$resolved_ids[] = $term_id;
			}

			$resolved_ids = array_values( array_unique( array_filter( $resolved_ids ) ) );

			if ( $is_pa_attribute && $needs_reassign && ! empty( $resolved_ids ) ) {
				wp_set_object_terms( $product_id, $resolved_ids, $taxonomy );
				$log_message .= "  reassigned {$taxonomy} on product {$product_id} ({$lang}): " . implode( ',', $resolved_ids ) . "\n";
			}

			$terms_by_lang[ $lang ] = $resolved_ids;
			$log_message .= "  product {$product_id} ({$lang}) {$taxonomy}: " . implode( ',', $resolved_ids ) . "\n";
		}

		if ( ! empty( $terms_by_lang ) ) {
			self::link_flat_terms_by_lang( $terms_by_lang, $taxonomy );
		}
	}

	/**
	 * @return string[] таксономии pa_*, включённые в Polylang
	 */
	private static function get_translated_product_attribute_taxonomies() {
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

		$translations = pll_get_post_translations( $post_id );
		if ( is_array( $translations ) ) {
			$products_by_lang = array_filter( $translations );
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
			$lang = pll_get_post_language( $other_id );
			if ( $lang ) {
				$products_by_lang[ $lang ] = (int) $other_id;
			}
		}

		$current_lang = pll_get_post_language( $post_id );
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
	 * @return int 0 если перевода нет
	 */
	private static function resolve_term_id_for_product_language( $term_id, $lang_slug ) {
		if ( ! $term_id || ! $lang_slug || ! function_exists( 'pll_get_term_language' ) ) {
			return (int) $term_id;
		}

		if ( pll_get_term_language( $term_id ) === $lang_slug ) {
			return (int) $term_id;
		}

		if ( function_exists( 'pll_get_term' ) ) {
			$translated = (int) pll_get_term( $term_id, $lang_slug );
			if ( $translated ) {
				return $translated;
			}
		}

		return (int) $term_id;
	}

	/**
	 * @param int    $term_id
	 * @param string $lang_slug
	 * @param int    $context_product_id
	 * @return void
	 */
	private static function ensure_term_language( $term_id, $lang_slug, $context_product_id = 0 ) {
		$current = pll_get_term_language( $term_id );

		if ( $current === $lang_slug ) {
			return;
		}

		if ( $current && $context_product_id && self::term_is_used_on_other_product_with_language( $term_id, $current, $context_product_id ) ) {
			return;
		}

		pll_set_term_language( $term_id, $lang_slug );
	}

	/**
	 * @param int    $term_id
	 * @param string $lang_slug
	 * @param int    $exclude_post_id
	 * @return bool
	 */
	private static function term_is_used_on_other_product_with_language( $term_id, $lang_slug, $exclude_post_id ) {
		$term = get_term( (int) $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return false;
		}

		$object_ids = get_objects_in_term( (int) $term_id, $term->taxonomy );
		if ( is_wp_error( $object_ids ) || empty( $object_ids ) ) {
			return false;
		}

		foreach ( $object_ids as $object_id ) {
			$object_id = (int) $object_id;
			if ( $object_id === (int) $exclude_post_id ) {
				continue;
			}
			if ( get_post_type( $object_id ) !== 'product' ) {
				continue;
			}
			if ( pll_get_post_language( $object_id ) === $lang_slug ) {
				return true;
			}
		}

		return false;
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
			$existing = pll_get_term_translations( $term_id );
			if ( is_array( $existing ) ) {
				$merged = array_merge( $existing, $merged );
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
