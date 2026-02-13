# Changelog

Всі важливі зміни в проекті D14K Merchant Feed будуть задокументовані в цьому файлі.

## [1.0.19] - 2026-02-12

### Changed

- Moved settings page from WooCommerce submenu to top-level admin menu "GMC Feed".
- Added "GMC Feed" link to the top admin bar for quick access.

## [1.0.18] - 2026-02-12

### Changed

- Rebranded plugin to "GMC Feed for WooCommerce".
- Updated author to MIL SPIL.

## [1.0.17] - 2026-02-12

### Added

- Added `readme.txt` file for WordPress repository compliance.
- Added restriction to prevent plugin operation on domains ending in `.ru`.

## [1.0.16] - 2026-02-12

### Added

- Added support for Simple Products (previously only Variable products were supported).

## [1.0.15] - 2026-02-12

### Added

- Added XML preview for the first valid product in "Test Validation" screen.

## [1.0.14] - 2026-02-12

### Fixed

- Fixed issue where "Test Validation" or "Generate Now" could switch the admin language (e.g., to Russian) and fail to restore it, causing a redirect to the wrong language version.

## [1.0.13] - 2026-02-12

### Added

- Added automatic currency conversion to UAH for Ukrainian ('uk') and Russian ('ru') feeds if base currency is different (requires WCML).

## [1.0.12] - 2026-02-12

### Fixed

- Fixed "Missing sub-attribute [country]" error: Added `<g:country>` to the shipping block, defaulting to the same country as `country_of_origin` (usually 'UA').

## [1.0.11] - 2026-02-12

### Fixed

- Fixed GTIN validation error: Plugin no longer automatically uses SKU as GTIN. GTIN is now only populated if explicitly set in `_gtin` custom field. SKU continues to be mapped to MPN.

## [1.0.10] - 2026-02-12

### Виправлено

- **Критична помилка валідатора** - синхронізовано дефолтне значення `country_of_origin` між валідатором і генератором
- Валідатор тепер правильно визначає наявність поля `country_of_origin` навіть при використанні дефолтного значення 'UA'
- Усунуто розбіжність, через яку валідатор показував поле як відсутнє, хоча воно було присутнє в XML-фіді

### Покращено

- Точність валідації фіду - валідатор тепер відображає реальний стан полів у згенерованому XML

## [1.0.9] - 2026-02-12

### Додано

- **GTIN (штрих-код)** - додано підтримку поля `g:gtin` у фіді
- **MPN (артикул виробника)** - додано підтримку поля `g:mpn` у фіді
- Автоматичне витягування GTIN/MPN з SKU товару
- Підтримка custom fields `_gtin` та `_mpn` для товарів
- Перевірка GTIN, MPN та Google Category у валідаторі

### Виправлено

- **Критична помилка GMC** - відсутність обов'язкових полів GTIN, MPN, google_product_category
- Динамічне оновлення `identifier_exists` на основі наявності GTIN/MPN
- Валідатор тепер перевіряє всі критичні поля GMC

### Покращено

- Відповідність вимогам Google Merchant Center щодо ідентифікаторів товарів
- Точність тестової валідації фіду

## [1.0.8] - 2026-02-12

### Додано

- **Функція тестової валідації фіду** - перевірка перших 10 товарів перед відправкою до GMC
- Візуальне відображення результатів валідації з кольоровим кодуванням (зелений/червоний)
- Статистика по всіх обов'язкових полях Google Merchant Center
- Детальний список товарів з відсутніми полями
- Кнопка "Тестова перевірка (10 товарів)" в адмін-панелі

### Покращено

- Впевненість перед відправкою фіду до Google Merchant Center
- Швидка діагностика проблем з товарами

## [1.0.7] - 2026-02-12

### Додано

- **Атрибут країни походження (Country of Origin)** - обов'язкове поле для Google Merchant Center
- Налаштування країни походження в адмін-панелі з випадаючим списком (10 країн)
- XML-тег `<g:country_of_origin>` у фіді товарів
- Україна встановлена як країна за замовчуванням

### Виправлено

- Відповідність вимогам Google Merchant Center щодо обов'язкових атрибутів

## [1.0.6] - 2026-02-12

### Додано

- Щотижнева генерація фідів (раз на тиждень)
- Щомісячна генерація фідів (раз на місяць)
- Розширені опції розкладу для відповідності можливостям Google Merchant Center

### Змінено

- Оновлено інтерфейс вибору інтервалу генерації
- Додано нові cron schedules для гнучкого налаштування

## [1.0.5] - Попередня версія

### Технічні покращення

- Оптимізація коду

## [1.0.4] - 2026-02-12

### Додано

- Повна підтримка WPML для багатомовних фідів
- Автоматичний фолбек зображень з оригінальної мови
- Валідація товарів відповідно до вимог Google Merchant Center
- Адмін-панель з налаштуваннями та статистикою
- Маппінг категорій WooCommerce → Google Product Categories
- Автоматична генерація за розкладом (cron)
- Підтримка варіативних товарів WooCommerce
- Статистика генерації (кількість товарів, пропущених, помилок)
- Ручна генерація фідів
- Підтримка атрибутів товарів (колір, матеріал)
- Підтримка знижок та періодів їх дії
- Додаткові зображення товарів (до 10)

### Особливості

- Генерація окремих XML-фідів для кожної активної мови
- Автоматичне перемикання контексту мови при генерації
- Використання `item_group_id` для групування варіацій
- Валідація URL, цін, зображень та назв товарів
- Інтервали оновлення: 3, 6, 12 годин або щодня
- Копіювання URL фіду в один клік

### Технічні деталі

- WordPress 5.8+
- PHP 7.4+
- WooCommerce (обов'язково)
- WPML (опціонально)

## [1.0.3] - Попередні версії

### Додано

- Базова функціональність генерації фідів
- Підтримка WooCommerce

---

## Формат

Цей changelog дотримується принципів [Keep a Changelog](https://keepachangelog.com/uk/1.0.0/),
та проект використовує [Semantic Versioning](https://semver.org/lang/uk/).

### Типи змін

- **Додано** - для нових функцій
- **Змінено** - для змін в існуючій функціональності
- **Застаріло** - для функцій, які скоро будуть видалені
- **Видалено** - для видалених функцій
- **Виправлено** - для виправлення помилок
- **Безпека** - для виправлень вразливостей
