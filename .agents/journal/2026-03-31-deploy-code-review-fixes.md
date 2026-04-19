# Деплой code review фіксів — d14k-merchant-feed v3.0.0

**Дата:** 2026-03-31
**Chat ID:** 378693f7-8bb5-40f7-9efb-0f02cee71bec

## Що задеплоїли

Фікси з попереднього чату (36dc4b9c):
- `class-csv-generator.php` — 3 критичні баги (attribute filtering, category exclusion, WPML batch SQL)
- `class-feed-generator.php` — видалено `verify_environment()`
- `class-product-meta.php` — видалено `global $woocommerce`
- `d14k-merchant-feed.php` — `d14k_every_6_hours` → `daily`
- `class-admin-settings.php` — видалено `cron_interval` з `get_defaults()`

## Що зроблено

### Деплой (Степан)
```bash
rsync → filler-hostinger (6 файлів завантажено)
wp cache flush --skip-plugins=salesdrive  # salesdrive плагін має fatal error в CLI
litespeed-purge all  # LiteSpeed кешував URL фідів як HTML!
cloudflare purge_cache  # "success":true
flush_rewrite_rules(true)  # ВАЖЛИВО: обов'язково після кожного деплою!
```

### ⚠️ Важливий урок: `flush_rewrite_rules` обов'язковий після деплою!

Після rsync rewrite rules залишились старими → запити `?d14k_feed=1` не перехоплювались → WordPress повертав HTML homepage замість XML.

**Правильний URL фідів:**
- GMC: `https://filler.com.ua/?d14k_feed=1&d14k_feed_lang=uk`
- YML: `https://filler.com.ua/?d14k_yml_feed=1&d14k_yml_channel=horoshop`

### Верифікація фідів

| Фід | Статус |
|-----|--------|
| GMC UK | ✅ XML (RSS 2.0 + xmlns:g) |
| GMC RU | ✅ XML (посилання /ru/) |
| Horoshop | ✅ CSV (всі заголовки присутні) |
| Prom | ℹ️ Disabled (навмисно) |
| Rozetka | ℹ️ Disabled (навмисно) |

### Побічна проблема
- `salesdrive` плагін має `Fatal error` при запуску WP-CLI → завжди використовувати `--skip-plugins=salesdrive`
- LiteSpeed кешує фіди — додати URL `?d14k_feed` та `?d14k_yml_feed` в LSCache exclusions (TODO)

## Що залишилось (TODO)
- [ ] Рефакторинг: трейт `D14K_Product_Filter_Trait` для спільних методів
- [ ] Перенести inline CSS з `render_cat_accordion` в `admin.css`
- [ ] Додати URL фідів в LiteSpeed Cache exclusions (щоб не кешував)
- [ ] Оновити деплой-процедуру: додати `flush_rewrite_rules` як крок 4
