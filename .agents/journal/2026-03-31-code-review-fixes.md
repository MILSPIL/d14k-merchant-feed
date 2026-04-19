# Code Review & Bug Fixes — d14k-merchant-feed v3.0.0

**Дата:** 2026-03-31
**Chat ID:** 36dc4b9c-c1b6-4a0c-a623-ddc42f8dcc3c

## Що зроблено

### Повний аудит коду (12 файлів, ~6200 рядків)
- Перевірено security, мертвий код, дублювання логіки

### 🔴 Пофікшено 3 критичні баги в `class-csv-generator.php`:
1. **`is_excluded_by_attributes`** — повністю переписано з string-based на array-based (фільтрація по атрибутах не працювала взагалі)
2. **`is_in_excluded_categories`** — додано перевірку ancestors + strict `in_array`
3. **`expand_excluded_with_translations`** — переписано з WPML filter на batch SQL (як у YML генераторі)

### 🟡 Видалено 5 одиниць мертвого коду:
- `create-csv.js`, `parse-log.js` — одноразові dev-скрипти
- `d14k_every_6_hours` → `daily` у activation hook (`d14k-merchant-feed.php`)
- `verify_environment()` з `class-feed-generator.php` (обфускований domain check)
- `global $woocommerce` з `class-product-meta.php`
- `cron_interval` з `get_defaults()` в `class-admin-settings.php`

### ✅ Security — підтверджено:
- Nonces, capability checks, sanitization, escaping — всюди на рівні

## Що залишилось (для наступного чату)
- [ ] Рефакторинг: винести спільні методи в трейт `D14K_Product_Filter_Trait`
- [ ] Перенести inline CSS з `render_cat_accordion` в `admin.css` (~170 рядків)
- [ ] Деплой на прод і перевірка фідів (GMC, Horoshop, Prom, Rozetka)
