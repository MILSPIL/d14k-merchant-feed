# Ціль

Заборонити supplier import створювати або оновлювати товари без картинки і товари не в наявності, а також показувати ці пропуски у preview на вкладці `Постачальники`.

# Зроблено

- У `includes/class-supplier-feeds.php` додано централізовану перевірку допуску офера в import:
  - `get_offer_skip_reason()`
  - `offer_has_valid_image()`
- Офер тепер пропускається, якщо:
  - порожня назва або ціна `<= 0`
  - `available = false`
  - немає жодної валідної картинки `http/https`
- Одна й та сама перевірка використовується в:
  - `analyze_offer_import()`
  - `import_product_from_offer()`
- Це вирівняло preview і live import: те, що preview показує як `пропущено`, live import теж не повинен записувати у WooCommerce.
- У `includes/class-admin-settings.php`:
  - оновлено helper-text на `tab=suppliers`
  - додано рендер блоку `Приклади пропущених оферів`
- Зміни задеплоєно на production і перевірено через live browser analysis.

# Змінені файли

- `includes/class-supplier-feeds.php`
- `includes/class-admin-settings.php`
- `.agents/journal/2026-04-15-0033-ide-gpt5-supplier-feed-skip-no-image-stock.md`

# Перевірки

- `php -l includes/class-supplier-feeds.php`
- `php -l includes/class-admin-settings.php`
- deploy на production
- `wp cache flush`
- live click test `Аналізувати фіди`

# Результат

На live preview для LogicPower feed отримано новий розклад:

- оферів: `1591`
- буде створено: `441`
- буде оновлено: `34`
- пропущено: `1116`

Окремий parse feed підтвердив breakdown:

- `out_of_stock`: `1116`
- `missing_image`: `0`
- `importable`: `475`

Отже, на поточному feed масове відсікання спрацьовує через відсутність наявності, а не через картинки.

# Next steps

- За потреби додати окремі counters по причинах пропуску прямо в UI preview.
- За потреби додати hard-lock перед live import без preview.
- За потреби додати окремий cleanup-flow для вже створених supplier товарів, які були імпортовані до цієї правки.

# Ризики

- Правка не чистить уже створені товари автоматично.
- Якщо товар у feed стане `out_of_stock`, він тепер пропускається, а не синхронізується назад як `outofstock`.
