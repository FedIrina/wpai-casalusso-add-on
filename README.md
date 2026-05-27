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

### Импорт альт. вариаций (`vars_info`)

Отдельный импорт WPAI (**#5**, `alt_variations_simple_products_3`): только обновление существующих простых товаров, запись мета `vars_info` для плагина **woo-alt-variations**. Polylang-хуки add-on при этом не запускаются.

Функция `wpai_casalusso_make_vars_string()` преобразует поле `Alt_variations` из CSV в JSON. В настройках импорта custom field:

```text
vars_info = [wpai_casalusso_make_vars_string({alt_variations[1]}, {local[1]})]
```

Второй аргумент `{local[1]}` **обязателен**: WPAI разбирает custom fields до цикла по строкам, без контекста текущей записи язык из XML не подставится.

Отладочный лог: `wp-content/import_alt_variatios.log`.

## Ожидаемый формат CSV

### Импорты #1 и #3 (создание товаров)

| Поле   | Назначение                                      |
|--------|--------------------------------------------------|
| `scu`  | SKU товара (общий для всех языковых версий)     |
| `local`| Код языка Polylang (`ru`, `en`, `de`, `tr`, …)  |

Остальные поля обрабатывает WP All Import / WooCommerce Add-On (название, описание, категории, атрибуты, цены и т.д.).

### Импорт #5 — редактирование альт. вариаций

Режим: **обновить существующие записи**. Сопоставление строки с товаром — по **Title** (название на языке строки; у вариантов одной модели Title разный, поэтому автогруппировка по Title не используется).

| Колонка CSV       | Обязательно | Назначение |
|-------------------|-------------|------------|
| `Title`           | да          | Название товара на языке `Local`; ключ для поиска записи в WPAI |
| `SKU`             | да          | Артикул **редактируемого** товара (тот же, что `_sku` в WooCommerce) |
| `Local`           | да          | Код языка Polylang: `ru`, `en`, `de`, `tr` |
| `Alt_variations`  | да          | Связи альт. вариаций для этого товара (см. формат ниже) |
| `ID`              | нет         | Справочный SKU; на импорт не влияет |

**Формат `Alt_variations`** — одна строка на товар:

```text
ИмяАтрибута|SKU1,Значение1;SKU2,Значение2;SKU3,Значение3
```

- До `|` — подпись атрибута в админке woo-alt-variations (например `Цвет`, `Color`).
- После `|` — пары через `;`, в каждой паре: **SKU связанного товара**, запятая, **подпись варианта** (`var_attr_value`).
- В списке должен быть SKU **текущего** товара с подписью его цвета/варианта.
- В `Alt_variations` указываются **SKU**, не `post_id`. Плагин ищет товар по `_sku` / `wc_product_meta_lookup` и оставляет только пост с языком `Local` из той же строки.

**Пример (ru):**

```text
Цвет|118721,Голубиный серый;118752,Папирус;118738,Арагон
```

Для строки с `SKU=118752` и `Local=ru` в `vars_info` попадут `post_id` товаров 118721, 118752, 118738 **на русском**, а не en/de/tr.

**Правила:**

1. Одна строка CSV = один товар = один язык. Для той же модели на `en` — отдельная строка с переводами в `Title` и `Alt_variations` и SKU **английских** постов.
2. SKU в списке должны существовать в WooCommerce на языке `Local`; иначе пара пропускается (см. лог).
3. Сначала импорт **#1** (товары созданы), затем **#5**.
4. Кодировка файла: **UTF-8**; разделитель — как в настройках импорта #5 (на dev: `;`).
5. Поле `Alt_variations` с запятыми и `;` в Excel — в кавычках.

Файл на dev: `wp-content/uploads/wpallimport/files/alt_variations_simple_products_3.csv`.

## Рекомендуемый порядок импорта

1. Сначала язык по умолчанию (**ru**), затем остальные (**en**, **de**, **tr**).
2. В каждой строке CSV — свои переводимые значения (название, категории, теги, термины `pa_*`).
3. Для **нескольких** тегов или значений одного атрибута на товаре — **одинаковый порядок** значений во всех языковых строках с тем же SKU.
4. В **Settings → WooPoly → Features** включите **Translate Categories**, **Translate Tags** и **Translate Attributes** для тех таксономий, которые нужно обрабатывать при импорте. Если опция выключена, add-on не включает таксономию в Polylang и не синхронизирует её термины.
5. После импорта **#1** (и при необходимости **#3**) — импорт **#5** для `vars_info` (формат CSV см. выше).

## Логирование

При обработке новых товаров (#1 / #3) пишется лог:

`wp-content/uploads/product_taxonomies.log`

В лог попадают включение таксономий в Polylang (`enabled polylang translation for …`) и результаты синхронизации терминов.

## Ограничения и что не входит в плагин

| Область | Поведение |
|---------|-----------|
| **Обновление существующих товаров** (`$is_update`) | Polylang и таксономии **не** пересобираются; только `_display_with_quantity_attributes`. Поведение при update — [отложено](docs/woo-poly-integration-import-analysis.txt). |
| **Meta woo-poly** (цены, остатки, SKU, …) | Не синхронизируются с ru на переводы (в админке это делает woo-poly). |
| **Вариации variable** | Не создаются переводы вариаций и `_point_to_variation`. |
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
3. Настроить импорты WPAI #1 (простые товары) и #3 (вариативные) с полями `local` и `scu`; при необходимости #5 — по разделу «Импорт #5 — редактирование альт. вариаций».

## Автор

Irina Feodorova

## Лицензия

[GNU General Public License v2.0 or later (GPL-2.0-or-later)](https://www.gnu.org/licenses/gpl-2.0.html) — стандартная лицензия для плагинов WordPress. Полный текст: [`LICENSE`](LICENSE).

Copyright © 2026 Irina Feodorova
