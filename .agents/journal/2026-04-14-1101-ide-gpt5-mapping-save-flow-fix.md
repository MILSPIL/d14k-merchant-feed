# 2026-04-14 — Fix mapping save flow and move default Google category

## Goal

Прибрати конфлікт між вкладками `Налаштування` і `Маппінг`, через який `default_google_category`, `category_map` і `category_label_0..4_map` перетирали одне одного при збереженні.

## Done

- Проаналізовано `includes/class-admin-settings.php` і `includes/class-feed-generator.php`.
- Підтверджено, що fallback-логіка в генераторі працює правильно:
  - спочатку `category_map`
  - якщо маппінг для категорії порожній, тоді `default_google_category`
- Знайдено корінь проблеми в `save_settings()`:
  - кожне збереження збирало `d14k_feed_settings` майже з нуля
  - поля з інших вкладок, яких не було в поточному POST, ставали порожніми
- Виправлено UI:
  - прибрано поле `Google Product Category (за замовчуванням)` з вкладки `Налаштування`
  - перенесено його у вкладку `Маппінг`
  - додано пояснення про пріоритет:
    - спочатку значення за замовчуванням
    - потім окремий маппінг категорії, якщо він заданий
- Виправлено save-flow:
  - `save_settings()` тепер стартує з already saved settings через `wp_parse_args(..., $this->get_defaults())`
  - кожен таб оновлює тільки свої ключі:
    - `settings`
    - `mapping`
    - `filters`
    - `prom_sync`
  - інші ключі більше не перезаписуються порожніми значеннями

## Changed Files

- `includes/class-admin-settings.php`
- `.agents/journal/2026-04-14-1101-ide-gpt5-mapping-save-flow-fix.md`

## Checks

- `php -l includes/class-admin-settings.php`
- Перевірено змінені ділянки коду через `nl -ba`

## Result

- `default_google_category` тепер живе тільки у вкладці `Маппінг`
- ручний category mapping і custom labels більше не мають стиратись після збереження інших вкладок
- логіка стала узгодженою з реальним сценарієм:
  - default category як fallback
  - per-category mapping як вищий пріоритет

## Next Steps

1. Ручна перевірка в адмінці:
   - зберегти `default_google_category`
   - зберегти `category_map`
   - зберегти `custom labels`
   - перейти на `Налаштування` і зберегти будь-яке інше поле
   - перевірити, що маппінг і labels лишилися
2. За потреби окремо винести tab-specific save logic в helper methods, щоб зменшити розмір `save_settings()`.

## Risks

- Автоматичні тести для адмін-форми відсутні, тому фінальна перевірка має бути ручною в WP admin.
