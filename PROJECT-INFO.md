# MIL SPIL Feed Generator (d14k-merchant-feed)

Плагін для генерації фідів для Google Merchant Center та українських маркетплейсів (Horoshop, Prom.ua, Rozetka)

- **Клієнт/Проєкт:** Власна розробка (MIL SPIL)
- **Сайт:** 14karat (`diamonds14k.com`), Techmachagro, Filler, beautyfill.shop (використовується на бойових проєктах)
- **Окремі staging-сайти:** `https://staging.strum.biz.ua`, `https://staging.diamonds14k.com`
- **14karat профіль використання:** Google Merchant Center + Prom.ua. Horoshop і supplier feeds для цього сайту не цільові.
- **Strum профіль використання:** єдиний полігон для Prom import/export і supplier feeds, поки цей функціонал не дозріє до спільного релізу.

## Модель релізів

- **Стабільна лінія:** фінальна версія з GitHub, яка розходиться по бойових сайтах, де плагін уже потрібен.
- **Експериментальна лінія:** розробка і перевірка нового функціоналу тільки на `strum.biz.ua` і `staging.strum.biz.ua`.
- **Що вважається стабільним зараз:** Google Merchant Center і вже усталений базовий feed flow.
- **Що зараз живе тільки на Strum:** supplier feeds, Prom import/export у новому вигляді, пов'язані background flows і smoke.
- **Правило публікації:** спершу новий функціонал дозріває на Strum, потім повертається в GitHub як нова спільна фінальна версія, і лише після цього розходиться на всі сайти, де встановлено плагін.
- **Правило використання:** не всі сайти користуються всіма блоками, але фінальна версія плагіна всюди має бути однаково найкраща. Різниться не кодова база, а фактично задіяні можливості й налаштування.
- **Наступний спільний етап після цього циклу:** Facebook і Rozetka.
- **GitHub правило:** `main` тримає стабільний стан, а проміжна Strum-розробка живе в окремій гілці до підтвердження.

## Етапи розробки

- [x] v1.0.0 – Базовий функціонал генерації XML
- [x] v2.0.0 – Глобальний рефакторинг та оптимізація CRON
- [x] v2.1.0 – SaaS UI, Custom Labels 0-4, розширена фільтрація (27.03.2026)
- [x] v3.0.0 – Мультиплатформний експорт: білінгвальний YML для Horoshop/Prom/Rozetka (29.03.2026)
- [ ] **Наступний крок:** Тестування Prom.ua та Rozetka каналів на реальних даних. Деплой v3.0.0 на всі сайти.

## Історія рішень

- Перехід від Google Fonts на локально завантажений Montserrat для швидкості (планується).
- Відмова від стандартних UI WordPress на користь власного "SaaS" дизайну.
- Білінгвальний single-feed замість окремих файлів на мову: RU = base, UA = `name_ua`/`description_ua` (YML-стандарт).
- Видалено sub-daily cron інтервали (3h/6h/12h) — GMC забирає фід max раз на добу.

## Staging

- URL: `https://staging.strum.biz.ua`
- WP path: `/home/u731710222/domains/strum.biz.ua/public_html/staging`
- Це головний staging для експериментальної лінії.
- Другий staging: `https://staging.diamonds14k.com`
- WP path для `diamonds14k` staging: `/home/diamond2/staging.diamonds14k.com`
- Env profile для `diamonds14k` staging: `diamonds14k-staging`
- Поточний status `diamonds14k` staging: `gmc-feed-for-woocommerce` активний, `merchant-feed/uk/` і `merchant-feed/ru/` віддають `200`, а `marketplace-feed/prom/` зараз віддає `403`.
- `diamonds14k` staging потрібен для перевірки стабільної лінії сайту `14karat`, а не для supplier-лабораторії.
- Для staging є окремий runbook: `STAGING-RUNBOOK.md`
- Для швидкої перевірки є:
  - `scripts/checks/run-staging-smoke.sh`
  - `D14K_ENV_PROFILE=diamonds14k-staging ./scripts/checks/run-smoke-by-profile.sh`
  - `scripts/smoke/import-readiness.php`
  - `scripts/smoke/supplier-large-feed.php`
  - `scripts/smoke/background-single-feed.php`

## Файлова архітектура

- `d14k-merchant-feed.php` — Головний файл (endpoints, ініціалізація, handlers).
- `includes/class-admin-settings.php` — Адмін-інтерфейс (SaaS UI з табами).
- `includes/class-feed-generator.php` — Генератор XML для Google Merchant Center.
- `includes/class-yml-generator.php` — Генератор YML для маркетплейсів (v3.0.0, 886 рядків).
- `includes/class-cron-manager.php` — CRON планувальник (GMC + YML).
- `includes/class-wpml-handler.php` — Обробник мультимовності WPML.
- `includes/class-feed-validator.php` — Валідація фідів.
- `includes/class-product-meta.php` — Custom Labels та продуктові мета-поля.
- `assets/admin.css` — Стилі адмін-панелі (SaaS design system).
