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

Хук: `pmxi_saved_post`, только если `! $is_update`.

1. **Язык товара** — из поля CSV `local` (например `ru`, `en`, `de`, `tr`).
2. **Связь переводов товара** — по общему SKU (`scu` в CSV, мета `_sku` в WooCommerce): все товары с одним SKU попадают в одну группу `post_translations`.
3. **Категории `product_cat`** — язык терминов (включая родителей в цепочке) и связи переводов между языками по SKU; иерархия учитывается по глубине (один термин на язык на уровень).
4. **Теги `product_tag`** — язык и связи переводов; для нескольких тегов сопоставление по порядку `term_order` (одинаковое число тегов на всех языках).
5. **Атрибуты `pa_*`** — для всех таксономий атрибутов, включённых в Polylang; та же плоская логика, что у тегов.

Термины **не создаются** add-on: их создаёт и назначает товарам WP All Import (если в настройках импорта разрешено создание терминов).

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

## Логирование

При обработке новых товаров (#1 / #3) пишется лог:

`wp-content/uploads/product_taxonomies.log`

## Ограничения и что не входит в плагин

| Область | Поведение |
|---------|-----------|
| **Обновление существующих товаров** (`$is_update`) | Polylang и таксономии **не** пересобираются; только `_display_with_quantity_attributes`. Поведение при update — [отложено](docs/woo-poly-integration-import-analysis.txt). |
| **Meta woo-poly** (цены, остатки, SKU, …) | Не синхронизируются с ru на переводы (в админке это делает woo-poly). |
| **Вариации variable** | Не создаются переводы вариаций и `_point_to_variation` (только хук-заготовка для импорта #3). |
| **Yoast SEO** | Пересборка indexable после импорта временно отключена в коде. |
| **Создание терминов** | Только WPAI; add-on ставит язык и связи уже существующим терминам на товаре. |

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
