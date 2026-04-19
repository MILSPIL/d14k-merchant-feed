## date and time

- `2026-04-14 11:23 Europe/Kiev`

## IDE / agent

- `Codex desktop`

## model

- `gpt-5`

## task goal

- Зробити повне рев'ю плагіна `gmc-feed-for-woocommerce` і знайти болючі місця, неактивний або мертвий код, а також конфлікти між модулями.

## what was done

- Прочитано `PROJECT-INFO.md` і останні журнали плагіна.
- Прогнано `php -l` по всіх PHP-файлах плагіна.
- Перевірено основні модулі:
  - `d14k-merchant-feed.php`
  - `includes/class-admin-settings.php`
  - `includes/class-cron-manager.php`
  - `includes/class-prom-api.php`
  - `includes/class-prom-importer.php`
  - `includes/class-prom-exporter.php`
  - `includes/class-supplier-feeds.php`
  - `includes/class-feed-generator.php`
  - `includes/class-yml-generator.php`
  - `includes/class-csv-generator.php`
  - `includes/class-wpml-handler.php`
- Виділено головні проблеми:
  - `prices`-режим Prom імпорту досі використовує стару page-based пагінацію, хоча API вже переключено на `last_id`.
  - `prom_set_external_id` у Prom importer недороблений: використовується undefined `$settings`, а сам прапорець ніде не задається.
  - supplier feeds досі ходять через `wp_remote_get`, хоча в цьому ж плагіні для Hostinger уже введений обхід через cURL.
  - `custom_rules` із вкладки `Фільтри` не застосовуються до Horoshop CSV.
  - адмінка і save-flow напряму звертаються до `icl_translations` без перевірки наявності WPML.
  - деактивація плагіна не чистить Prom / supplier cron hooks.
  - fallback-апдейт supplier feed по `post_title` може оновити не той товар.

## changed files

- `.agents/journal/2026-04-14-1123-ide-gpt5-full-code-review.md`

## commands / checks / tests

- `find ... -name '*.php' | xargs php -l`
- `rg -n` по hooks, cron, HTTP calls, custom rules, WPML queries, external_id
- `nl -ba ... | sed -n ...` для точкової перевірки рядків у критичних класах

## result

- Знайдено кілька живих багів, які вже зараз можуть ламати прод:
  - зламаний `prices`-режим імпорту Prom
  - недороблений `external_id` sync
  - ненадійний transport layer у supplier feeds на Hostinger
- Також є 3 системні зони конфлікту:
  - різна логіка фільтрації між GMC/YML і CSV
  - пряма залежність адмінки від таблиць WPML
  - orphan cron events після деактивації

## next steps

1. Першим фіксити Prom import pagination у `update_prices_and_stock()` і прибрати старий page-based підхід із live-шляхів.
2. Або доробити `prom_set_external_id` до кінця, або видалити цю мертву гілку.
3. Перевести supplier feeds на той самий cURL transport, що вже використовується в Prom API / image download.
4. Уніфікувати `custom_rules` для CSV, щоб вкладка `Фільтри` працювала однаково для всіх каналів.
5. Обгорнути WPML SQL у перевірку на наявність таблиці `icl_translations`.
6. Додати повну очистку всіх cron hooks при деактивації.

## risks / notes

- Це було review без внесення кодових фіксів.
- Синтаксичних помилок у PHP не знайдено, але логічні помилки є.
- Частина проблем уже зачіпає production сценарії, а не тільки крайні випадки.
