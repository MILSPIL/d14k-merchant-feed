# Сесія: CSV для Horoshop + фікс дати + CF cache purge

**Дата:** 2026-03-30 / 2026-03-31
**Chat ID / Conversation ID:** 8c54e556-f874-438c-a5ec-9c103678bf62

## Що було зроблено

### 1. Автоматизація очищення кешу Cloudflare

- Створено API-токен `Deploy Cache Purge` (Zone → Cache Purge)
- Верифіковано та протестовано `purge_cache` API
- Оновлено `.agents/config.md` та `workflows/gmc-feed-for-woocommerce.md`

### 2. CSV-генератор: повне перемапінгування під Horoshop

В `includes/class-csv-generator.php`:

- `Артикул родительского товара` → `Родительский артикул`
- `Название` / `Название (uk)` → `Название модификации (RU)` / `(UA)`
- `Описание` / `Опис (uk)` → `Описание товара (RU)` / `(UA)`
- Інверсія цін: `Цена` = selling price, `Старая цена` = crossed
- Видалено: `Краткое описание`, `Валюта`
- Розділено: `Изображения` → `Фото` (головне) + `Галерея` (додаткові)

### 3. Баг-фікс: адмінка показувала стару дату для Horoshop

В `includes/class-admin-settings.php` (рядки 312-341):

- Адмінка читала дату/URL/статистику завжди з `$yml_generator`
- Horoshop використовує CSV-генератор → дата не оновлювалась
- Фікс: `$gen = ($ch_key === 'horoshop') ? $this->csv_generator : $this->yml_generator`
- Тепер кожен канал читає зі свого генератора

## Файли змінені

- `includes/class-csv-generator.php` — заголовки + логіка рядків
- `includes/class-admin-settings.php` — правильний генератор для кожного каналу
- `.agents/config.md` — CF API
- `.agents/workflows/gmc-feed-for-woocommerce.md` — деплой-процедура
