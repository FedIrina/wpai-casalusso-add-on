# План развития импорта (wpai-casalusso-add-on)

Дата фиксации: 2026-05-27  
Контекст: импорт товаров WooCommerce + Polylang (ru, en, de, tr), variable (`first_is_variation`), alt-variations (`vars_info`).

---

## Текущее состояние (сделано)

- **Импорт #3 — привязка вариаций к родителю своего языка**  
  Хук `pmxi_after_post_import` → `fix_variation_parent_after_row` → `apply_variation_parent_fix`.  
  Родитель: `Parent_SCU` + `Local` (колонка CSV или суффикс `unique_key`, например `119070en`).  
  Запись `post_parent` — напрямую в `wp_posts` (не через `WC_Product_Variation::save()`).

- **Временно отключено (закомментировано):**  
  `pmwi_product_parent_post_id` — контрольный прогон показал, что финальный fix достаточен.

- **Удалено:**  
  хук `wp_all_import_variable_product_imported` (был только диагностический лог).

- **Этап 2 (2026-05-27):** убрана привязка к номерам импортов — условия по `Local`, `Parent_SCU`, типу поста (`should_fix_variation_parent`, `should_apply_polylang_on_product_create`).

- **Реализовано для простых товаров:**  
  `vars_info` через `wpai_casalusso_make_vars_string()` в отдельном импорте (#5, update), формат `Alt_variations` — см. README.

- **Этап 1:** `vars_info` для variable parent — отдельный update-импорт + `alt_variations_CL-import-variative-parents-2026-05-27.csv` (на dev проверен).

- **Не реализовано:**  
  `_point_to_variation` / дублирование вариаций woo-poly; уборка закомментированного кода и логов (этап 3).

---

## Принципы (целевая архитектура)

Не привязывать логику к **номеру импорта** (`#1`, `#3`, `#5`) — на другом окружении ID другие.

| Поведение | Когда включать | Когда не трогать (обычный WPAI) |
|-----------|----------------|--------------------------------|
| Fix `post_parent` вариаций | `product_variation` + есть язык строки + есть `Parent_SCU` | нет `Local` / языка из `unique_key`, нет `_parent_sku` |
| Polylang, теги, категории, collection | `product` + есть `local` в строке | нет колонки `Local` |
| `vars_info` | родитель **variable** + язык + данные `Alt_variations` | нет поля / пусто — не падать |

**Шаблон / `friendly_name`** (например `Variable_products`) — опциональная страховка, не единственный триггер.  
Основа — **признаки строки и настроек импорта** (наличие `Local`, custom field с `make_vars_string`).

**`vars_info`:** мета пишется на **variable parent**, не на строки вариаций (L/XL).

---

## Порядок работ (согласован)

### Этап 1 — `vars_info` для variable parent ✓

**Цель:** записывать `vars_info` на родителя каждой языковой версии (отдельный update-импорт, как #5 для простых).

**Подшаги (выполнено / в работе на dev):**

1. Убедиться на dev, что woo-alt-variations читает `vars_info` с **родителя** variable (не с variation).
2. Добавить в CSV/шаблон колонку `Alt_variations` на **строках родителя** (`119063ru`, `119063en`, …), не на L/XL — если колонки ещё нет.
3. Прописать в шаблоне импорта #3 custom field на родителя, например:  
   `vars_info = [wpai_casalusso_make_vars_string({alt_variations[1]}, {local[1]})]`  
   Либо хук только для строки-родителя (`product` + variable), если custom field на parent в WPAI неудобен.
4. Переиспользовать `wpai_casalusso_make_vars_string()` и резолв языка (SKU + `Local`).
5. **Контрольный прогон:**  
   - вариации: `post_parent` = родитель своего языка;  
   - родители: заполнен `vars_info`, в JSON — `post_id` товаров **этого** языка.

**Лог:** `wp-content/import_alt_variatios.log` (как у импорта #5).

---

### Этап 2 — убрать привязку к ID импортов ✓

**Сделано в коде:** `row_has_language_context()`, `row_has_parent_scu()`, `should_fix_variation_parent()`, `should_apply_polylang_on_product_create()`, `is_woocommerce_product_import_context()`.

**Контрольный прогон:** полный variable CSV + импорт с `Local` (номер импорта не важен).

---

### Этап 3 — опционально, позже

- Профиль импорта по имени шаблона / `friendly_name` для `vars_info`.
- woo-poly: `_point_to_variation`, дублирование вариаций между языками (отдельно от fix `post_parent`).
- Удалить закомментированный `pmwi_product_parent_post_id` и диагностический лог `wpai_casalusso_variations_parent_fix.log` (когда согласуем).
- Обновить README: убрать жёсткие «#3», «#5», описать условия включения по колонкам.

---

## Справка: что уже не используем

| Элемент | Статус |
|---------|--------|
| `wp_all_import_variable_product_imported` | удалён |
| `pmwi_product_parent_post_id` | закомментирован, не удалять пока |
| Fix только через `variation->save()` | не использовать |

---

## Тестовые артефакты (dev)

- Variable CSV: `wp-content/uploads/wpallimport/files/CL-import-variative-119063-test.csv`
- Лог fix вариаций: `wp-content/uploads/wpai_casalusso_variations_parent_fix.log`
- Лог vars_info: `wp-content/import_alt_variatios.log`
- Простые + vars_info: `alt_variations_simple_products_3.csv` (импорт #5, отдельный сценарий)

---

## Сообщение для коммита (этап fix вариаций, уже сделан)

**Исправлена привязка вариаций языковых версий к родительским товарам при импорте #3**

(Детали реализации — в истории коммита; смысл для пользователя — один.)
