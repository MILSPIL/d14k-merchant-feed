# Cloudflare Cache Purge API — автоматизація очищення кешу

**Conversation ID:** 8c54e556-f874-438c-a5ec-9c103678bf62
**Дата:** 2026-03-31

## Що зроблено

1. **Створено Custom API Token** в Cloudflare Dashboard:
   - Назва: `Deploy Cache Purge`
   - Права: Zone → Cache Purge → Purge (All zones)
   - Токен: `cfut_Y5TA1FmNjSOOCBDRRf0eMbval72vo5JH9lcz1XD456e3a436`

2. **Отримано Zone ID** для filler.com.ua: `44d2bc486baf0d5a3789482d80b5a963`

3. **Протестовано API** — purge_cache працює (`"success": true`)

4. **Оновлено `.agents/config.md`**:
   - Додано секцію Cloudflare з токеном і Zone ID
   - Додано повні деплой-команди для filler.com.ua (rsync + wp cache flush + CF purge)

## Деплой filler.com.ua тепер включає

```bash
# 1. rsync
# 2. wp cache flush
# 3. curl Cloudflare purge_cache API
```

## Діагностика Cloudflare (з цієї ж сесії)

- Адмінка WordPress (`/wp-admin/*`) НЕ кешується CF (cf-cache-status: DYNAMIC)
- Development Mode вимкнено — це нормально
- Page Rule `Cache Everything` кешує тільки фронт

## Також в цій сесії

- Створено CSV-генератор для Хорошоп (`class-csv-generator.php`)
- Рефакторинг: видалено мертвий код horoshop з YML-генератора
- Code review та очищення описів (strip_newlines, wp_strip_all_tags)
