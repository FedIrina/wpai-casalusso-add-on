# WP All Import — Casalusso Add-On

Add-on для [WP All Import](https://www.wpallimport.com/) на сайте [Casalusso](https://casalusso.ru): связывает импортированные товары WooCommerce с Polylang и настраивает переводимые таксономии при создании новых товаров.

> **Не универсальный плагин.** Это индивидуальная разработка под конкретные требования заказчика и конфигурацию проекта Casalusso: номера и настройки импортов WPAI, поля CSV (`scu`, `local`), набор языков, атрибуты `pa_*`, связки с woo-poly-integration и woo-alt-variations. Для других сайтов на WooCommerce и Polylang плагин **не подходит** без переработки под их импорты, таксономии и бизнес-логику.

Плагин дополняет стандартный импорт WPAI и WooCommerce Add-On: Polylang и woo-poly-integration при импорте через WP All Import не вызываются автоматически (в отличие от создания перевода в админке).

## Требования

- WordPress 6.2+
- PHP 7.4+
- [WooCommerce](https://woocommerce.com/)
- [Polylang](https://polylang.pro/) (или Polylang Pro)
- [WP All Import Pro](https://www.wpallimport.com/) или WP All Import + [WooCommerce Add-On](https://www.wpallimport.com/woocommerce/)
- [woo-poly-integration_enhanced]
- [WooCommerce Alternative Variations] (мета `_display_with_quantity_attributes`)

Без активных зависимостей плагин сам деактивируется и показывает уведомление в админке.

## Что делает плагин

### При импорте новых товаров 

Хук: `pmxi_saved_post`, только если `! $is_update`, импорты **#1** и **#3**.

1. **Язык товара** — из поля CSV `local` (например `ru`, `en`, `de`, `tr`).
2. **Связь переводов товара** — по общему SKU (`scu` в CSV, мета `_sku` в WooCommerce): все товары с одним SKU попадают в одну группу `post_translations`.
3. **Подготовка таксономий в Polylang** (`ensure_polylang_taxonomies_for_product`) — если у товара есть термины/атрибуты и в woo-poly включена соответствующая опция (**Settings → WooPoly → Features**):
   - **Translate Categories** → `product_cat` добавляется в переводимые таксономии Polylang, если ещё не включена;
   - **Translate Tags** → то же для `product_tag`;
   - **Translate Attributes** → то же для каждого `pa_*`, назначенного товару;
   - **`collection`** (таксономия темы Casalusso) → включается автоматически, если зарегистрирована и у товара есть коллекция.
   
   Add-on **не создаёт термины** и **не назначает** их товару — только программно ставит «галочку» перевода таксономии в Polylang (аналог **Языки → Настройки → Custom taxonomies**). Новые `pa_*`, появившиеся при импорте, включаются автоматически — вручную в Polylang отмечать их не нужно.
4. **Создание и назначение терминов из CSV** (если WPAI не сделал этого) — при включённой опции woo-poly или для `collection`:
   - **Тэги** / **Tags** → `product_tag`;
   - **Category** → `product_cat` (цепочка `Родитель > … > Лист`);
   - **Collection** → `collection`.
5. **Синхронизация таксономий** — только если включена **та же** опция woo-poly (или таксономия `collection` активна) и таксономия переводимая в Polylang (`pll_is_translated_taxonomy`):
   - **`product_cat`** — язык терминов (включая родителей в цепочке) и связи переводов между языками по SKU; иерархия по глубине (один термин на язык на уровень);
   - **`product_tag`** — язык и связи переводов; для нескольких тегов сопоставление по `term_order` (одинаковое число тегов на всех языках);
   - **`collection`** — плоская логика, как у тегов;
   - **`pa_*`** — для всех переводимых таксономий атрибутов на товаре; та же плоская логика, что у тегов.

Термины атрибутов **не создаёт** add-on: их создаёт WPAI. Теги, категории и коллекции add-on создаёт из CSV (`Тэги` / **Tags**, **Category**, **Collection**). Значения атрибутов в CSV должны быть **на каждом языке** — add-on не копирует термины с ru, как woo-poly в админке.

**Порядок при импорте одного товара:** язык и `post_translations` → `ensure_*` (Polylang) → `sync_*` (язык терминов + связи).

### При любом импорте товара (create и update)

- Мета `_display_with_quantity_attributes` для атрибута `pa_czvet` (интеграция с темой / alt-variations).

### Вспомогательная функция

`wpai_casalusso_make_vars_string()` — преобразование строки alt_variations из CSV в JSON для мета `vars_info`. Можно вызывать из WPAI: `[wpai_casalusso_make_vars_string({поле})]`. Используется отдельным импортом (#5), не связана с Polylang.

## Ожидаемый формат CSV

| Поле   | Назначение                                      |
|--------|--------------------------------------------------|
| `scu`  | SKU товара (общий для всех языковых версий)     |
| `local`| Код языка Polylang (`ru`, `en`, `de`, `tr`, …)  |

Остальные поля обрабатывает WP All Import / WooCommerce Add-On (название, описание, категории, атрибуты, цены и т.д.).

## Рекомендуемый порядок импорта

1. Сначала язык по умолчанию (**ru**), затем остальные (**en**, **de**, **tr**).
2. В каждой строке CSV — свои переводимые значения (название, категории, теги, термины `pa_*`).
3. Для **нескольких** тегов или значений одного атрибута на товаре — **одинаковый порядок** значений во всех языковых строках с тем же SKU.
4. В **Settings → WooPoly → Features** включите **Translate Categories**, **Translate Tags** и **Translate Attributes** для тех таксономий, которые нужно обрабатывать при импорте. Если опция выключена, add-on не включает таксономию в Polylang и не синхронизирует её термины.

## Логирование

При обработке новых товаров (#1 / #3) пишется лог:

`wp-content/uploads/product_taxonomies.log`

В лог попадают включение таксономий в Polylang (`enabled polylang translation for …`) и результаты синхронизации терминов.

## Ограничения и что не входит в плагин

| Область | Поведение |
|---------|-----------|
| **Обновление существующих товаров** (`$is_update`) | Polylang и таксономии **не** пересобираются; только `_display_with_quantity_attributes`. Поведение при update — [отложено](docs/woo-poly-integration-import-analysis.txt). |
| **Meta woo-poly** (цены, остатки, SKU, …) | Не синхронизируются с ru на переводы (в админке это делает woo-poly). |
| **Вариации variable** | Не создаются переводы вариаций и `_point_to_variation` (только хук-заготовка для импорта #3). |
| **Yoast SEO** | Пересборка indexable после импорта временно отключена в коде. |
| **Создание терминов** | WPAI или add-on: **теги**, **категории**, **collection** (CSV) — add-on; атрибуты `pa_*` — WPAI; add-on ставит язык и связи. |
| **Опции woo-poly** | Синхронизация cat/tag/`pa_*` только при включённых **Translate Categories / Tags / Attributes** (Settings → WooPoly → Features). |
| **Новые `pa_*` при импорте** | Add-on автоматически включает таксономию в Polylang при первом импорте товара с этим атрибутом (create). Для уже импортированных товаров без повторного create — нет. |

Подробный разбор отличий от сценария «Добавить перевод» в админке + woo-poly: [`docs/woo-poly-integration-import-analysis.txt`](docs/woo-poly-integration-import-analysis.txt).

## Структура

```
wpai-casalusso-add-on/
├── wpai-casalusso-add-on.php          # bootstrap
├── includes/
│   ├── class-wpai-casalusso-import.php
│   ├── class-wpai-casalusso-dependencies.php
│   └── wpai-casalusso-functions.php
└── docs/
    └── woo-poly-integration-import-analysis.txt
```

## Установка

1. Склонировать репозиторий в `wp-content/plugins/wpai-casalusso-add-on/`.
2. Активировать плагин в WordPress после установки всех зависимостей.
3. Настроить импорты WPAI #1 (простые товары) и #3 (вариативные) с полями `local` и `scu`.

## Автор

Irina Feodorova

## Лицензия

[GNU General Public License v2.0 or later (GPL-2.0-or-later)](https://www.gnu.org/licenses/gpl-2.0.html) — стандартная лицензия для плагинов WordPress. Полный текст: [`LICENSE`](LICENSE).

Copyright © 2026 Irina Feodorova
