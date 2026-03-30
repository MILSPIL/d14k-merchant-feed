# Сесія 2026-03-29 — Рефакторинг v3.0.0 (Маркетплейси + UX + System Status)

**Chat ID:** `0b811bd5-d23c-4c1e-a332-3dea217200e4`
**Дата:** 2026-03-29 → 2026-03-30 00:12

---

## 🎯 Що було зроблено

### 1. WebP → JPG конвертація (Production-Ready)

**Файл:** `includes/class-yml-generator.php` (рядки 658–740)

- Метод `maybe_convert_webp_url()` — конвертація через PHP GD
- MD5-кешування (не конвертує повторно)
- Memory guard 32MB — якщо залишилось менше, пропускає конвертацію
- Перевірка `enable_webp_conversion` в налаштуваннях
- Error logging через `error_log()`

### 2. Сторінка «Фіди» — повний UX редизайн

**Файл:** `includes/class-admin-settings.php`

#### Маркетплейсова таблиця (рядки ~253–360)

- **Toggle switches** для кожного каналу (Horoshop / Prom / Rozetka)
- **Per-channel Generate** кнопки через AJAX
- **URL фіду** з кнопкою «Копіювати» (з'являється тільки коли канал увімкнений)
- **WebP бейдж** — зелений ✓ якщо конвертація увімкнена
- **WebP warnings** під каналами:
  - **Horoshop**: ⚠️ warning (не підтримує WebP)
  - **Rozetka**: ⚠️ warning (офіційно тільки JPEG, PNG, GIF)
  - **Prom.ua**: 💡 info (рекомендовано JPG/PNG, але WebP не заборонений)
- Warnings показуються **тільки** якщо конвертація вимкнена

#### System Status карточка (рядки ~363–454)

- PHP версія (≥ 7.4)
- Пам'ять (≥ 256M рекомендовано, мін. 128M)
- PHP GD (обов'язково для конвертації)
- GD WebP підтримка (обов'язково для WebP → JPG)
- Час виконання (≥ 120s)
- Запис в uploads (обов'язково)
- Traffic-light індикатори: 🟢/🟡/🔴

#### AJAX endpoints (рядки ~1248–1335)

- `d14k_toggle_channel` — увімкнення/вимкнення каналу
- `d14k_generate_channel` — генерація конкретного каналу
- Nonce: `d14k_ajax_nonce`, capability: `manage_woocommerce`

### 3. CSS стилі

**Файл:** `assets/admin.css` (рядки 829–982)

- Toggle switches (`.d14k-toggle-*`)
- Inline notices (`.d14k-notice-inline`, `.d14k-notice-warning`, `.d14k-notice-info`)
- WebP badge (`.d14k-webp-badge`)
- Generate button spinner + animation
- Copy button success state (`.d14k-copy--success`)
- Generating state (`.d14k-generating`)

### 4. JavaScript

**Файл:** `assets/admin.js` (повний перепис)

- **Clipboard fallback** — `document.execCommand('copy')` для HTTP (Local WP)
- **Анімація генерації** — spinner для «Згенерувати зараз», «Тестова перевірка», per-channel
- **AJAX toggle** — переключення каналів
- **AJAX generate** — per-channel генерація з фідбеком

### 5. Документація

- Оновлено `marketplace-specs.md` — додані формати зображень для кожного маркетплейсу
- Створено `feed-comparison-report.md`

---

## 🔴 ВІДОМИЙ БАГ: Генерація редіректить на головну

**Проблема:** При натисканні «Згенерувати зараз» (main form submit) — редіректить на головну сторінку WP замість генерації.

**Причина (ймовірно):** Форма відправляє POST на `admin-post.php` з `action=d14k_generate_now`, але хук `admin_post_d14k_generate_now` може не бути зареєстрований, або є конфлікт з Local WP routing.

**Де шукати:**

```php
// class-admin-settings.php, рядок ~21-30 — реєстрація хуків
add_action('admin_post_d14k_generate_now', array($this, 'handle_generate_now'));

// Метод handle_generate_now — перевірити nonce, capability, redirect назад
```

**Що перевірити:**

1. Чи зареєстрований хук `admin_post_d14k_generate_now`
2. Чи правильний redirect після генерації (`wp_redirect` назад на сторінку плагіну)
3. Чи не конфліктує з іншими плагінами

---

## 📂 Змінені файли (відносно репозиторію)

| Файл | Зміни |
|------|-------|
| `includes/class-yml-generator.php` | WebP конвертація, $settings property |
| `includes/class-admin-settings.php` | Маркетплейсова таблиця, toggle, AJAX, System Status, WebP warnings |
| `assets/admin.css` | Toggle switches, notices, badges, spinners, success states |
| `assets/admin.js` | Повний перепис: clipboard fallback, animations, AJAX |
| `.agents/references/marketplace-specs.md` | Формати зображень per-channel |

---

## 📋 Pending Tasks (не виконані)

1. **Фікс генерації** — головний баг, редірект на головну
2. `brand_mode=attribute` в налаштуваннях (проблема FILLER.COM.UA як бренд)
3. `group_id` для Prom.ua каналу
4. Імпорт/виключення лінійки Renew cosmetics
5. Деплой на продакшн (після фіксу генерації)

---

## 🗂️ Синхронізація з Local WP

Файли синхронізовані через rsync:

```
/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/ → /Users/user/Local Sites/filler-com-ua/app/public/wp-content/plugins/d14k-merchant-feed/
```

Синхронізовані файли: `class-admin-settings.php`, `admin.css`, `admin.js`
