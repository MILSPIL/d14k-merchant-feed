# Ціль

Додати у supplier feeds окремий безпечний preview-етап перед реальним імпортом товарів у WooCommerce.

# Зроблено

- У `includes/class-supplier-feeds.php` додано safe analysis API:
  - `analyze_all_feeds()`
  - `analyze_feed()`
  - `analyze_offer_import()`
- Preview використовує ту саму matching-логіку, що і live import:
  - спершу `external_key`
  - потім `SKU`
- Додано окремі risk-classification:
  - `sku_existing_product`
  - `sku_other_supplier`
  - `sku_same_supplier`
  - `missing_identifiers`
- Додано sample-рядки для preview, щоб у UI було видно реальні приклади створень і оновлень.
- У `includes/class-admin-settings.php`:
  - підключено AJAX handler `d14k_supplier_feeds_analyze`
  - додано рендер HTML-звіту preview
  - додано кнопку `Аналізувати фіди` і helper-text у вкладці `Постачальники`
- У `assets/admin.js`:
  - додано окремий фронтовий action для preview
- У `assets/admin.css`:
  - додано стилі для analysis-report блоків
- Зміни задеплоєно на `strum.biz.ua` і перевірено через браузер.

# Змінені файли

- `includes/class-supplier-feeds.php`
- `includes/class-admin-settings.php`
- `assets/admin.js`
- `assets/admin.css`
- `.agents/journal/2026-04-15-0021-ide-gpt5-supplier-feed-analysis-preview.md`

# Перевірки

- `php -l includes/class-supplier-feeds.php`
- `php -l includes/class-admin-settings.php`
- deploy на production
- `wp cache flush` на production
- browser test кнопки `Аналізувати фіди` на live `tab=suppliers`

# Результат

Supplier feeds тепер мають окремий preview перед імпортом. На live можна побачити:

- скільки оферів у feed
- скільки товарів буде створено
- скільки буде оновлено
- приклади create/update
- ризики по `SKU-match` і відсутніх ідентифікаторах

Для feed LogicPower preview на live показав:

- `1591` оферів
- `63` категорії
- `1500` буде створено
- `91` буде оновлено
- `0` пропущено

# Next steps

- За потреби додати hard-guard, який вимагатиме preview перед live import.
- За потреби додати окремий список `оновиться через SKU-match`.
- За потреби додати режим створення нових supplier товарів у `draft`.

# Ризики

- Preview не є транзакційним dry-run на рівні БД, а лише безпечним read-only розрахунком на основі поточного стану WooCommerce.
- Між preview і реальним import стан сайту може змінитися, тому числа можуть трохи відрізнятися, якщо хтось паралельно редагує каталог.
