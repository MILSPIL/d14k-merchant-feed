# GMC Feed for WooCommerce

Плагін для генерації фідів для Google Merchant Center (а в майбутньому prom.ua та Rozetka)

- **Клієнт/Проєкт:** Власна розробка (MIL SPIL)
- **Сайт:** 14karat, Techmachagro, Filler (використовується на бойових проєктах)

## Етапи розробки

- [x] v1.0.0 – Базовий функціонал генерації XML
- [x] v2.0.0 – Глобальний рефакторинг та оптимізація CRON
- [x] v2.1.0 – SaaS UI, Custom Labels 0-4, розширена фільтрація (оновлено 27.03.2026)
- [ ] **Наступний крок:** Додати можливість експорту фіду на маркетплейси **prom.ua** та **rozetka.com.ua**.

## Історія рішень

- Перехід від Google Fonts на локально завантажений Montserrat для швидкості (планується).
- Відмова від стандартних UI WordPress на користь власного "SaaS" дизайну.

## Файлова архітектура

- `includes/class-admin-settings.php` — Адмін-інтерфейс.
- `includes/class-feed-generator.php` — Генератор XML.
- `includes/class-scheduler.php` — CRON для автогенерації.
- `assets/` — Власні стилі та скрипти (admin.css).
