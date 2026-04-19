# 2026-04-14 — Add category presets for cable store mapping

## Goal

Додати предналаштовані значення для `default_google_category`, category mapping і custom labels під кабельний каталог, щоб на чистій установці плагіна маппінг уже був заповнений під магазин `СТРУМ`.

## Done

- У `includes/class-admin-settings.php` додано стартову преднастройку маппінгу в `render_page()`.
- Додано helper `apply_category_presets()`:
  - ставить `default_google_category` = `Hardware > Power & Electrical Supplies > Electrical Wires & Cable`
  - задає override для:
    - `Вита пара внутрішня` → `Electronics > Electronics Accessories > Cables > Network Cables`
    - `Вита пара зовнішня` → `Electronics > Electronics Accessories > Cables > Network Cables`
  - заповнює `category_label_0..4_map` для основних кабельних категорій
- Додано guard `should_apply_category_presets()`:
  - преднастройка застосовується тільки якщо маппінг ще порожній
  - вже збережені ручні значення не перетираються

## Changed Files

- `includes/class-admin-settings.php`
- `.agents/journal/2026-04-14-1105-ide-gpt5-category-presets-for-cable-store.md`

## Checks

- `php -l includes/class-admin-settings.php`
- Перевірено helper methods через `nl -ba`

## Result

- На чистому стані маппінгу вкладка `Маппінг` тепер одразу показує підходящі стартові значення для кабельного магазину.
- Логіка пріоритету лишається такою:
  - default Google category як fallback
  - per-category mapping як override
  - custom labels по категоріях для сегментації реклами

## Next Steps

1. Відкрити вкладку `Маппінг` на сайті `СТРУМ` і перевірити, що preset значення підставилися.
2. Натиснути `Зберегти маппінг`, щоб зробити їх постійними в `d14k_feed_settings`.
3. За потреби відкоригувати окремі label значення вже вручну.

## Risks

- Преднастройка орієнтована саме на кабельний каталог.
- Для інших магазинів вона не спрацює, якщо назви категорій не збігаються, або не повинна спрацьовувати, якщо маппінг уже збережений.
