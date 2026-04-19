# Брифінг: зміни в плагіні d14k-merchant-feed (з іншого чату)

> **Контекст:** В рамках роботи з Horoshop-міграцією (chat `2bb81ba2-17c8-485c-9037-768ee0cbf2a0`, проєкт fillerS.com.ua) були внесені зміни в плагін d14k-merchant-feed. Цей файл — інструкція для деплою і push на GitHub.
>
> **Не видаляй цей файл** після виконання — він служить документацією змін.

---

## 1. Що змінилось і чому

### Рефакторинг: білінгвальний single-feed (v3.0)

**Було:** окремий YML-файл для кожної мови (`yml-horoshop-uk.xml`, `yml-horoshop-ru.xml`).
**Стало:** один файл на канал з вбудованими перекладами (`<name_ua>`, `<description_ua>`).

**Причина:** YML-стандарт (Prom.ua, Rozetka, Horoshop) вимагає RU як основну мову, а UA — як переклад. Підтримується нативно: `<name>` = RU, `<name_ua>` = UA. Два окремі файли були зайвими і ускладнювали маппінг.

### Змінені файли (5 штук)

#### `includes/class-yml-generator.php`

- Видалено `$lang` параметр з `generate()` — тепер завжди білінгвальний
- Рядок ~64: примусово `$this->wpml->switch_language('ru')` (base language для YML)
- `get_translated_value($product, 'name')` — отримує UA переклад через WPML і додає тег `<name_ua>`
- Аналогічно для `<description_ua>`
- Файл зберігається як `yml-{channel}.xml` (без мовного суфікса)

#### `d14k-merchant-feed.php`

- URL спрощено: **було** `/marketplace-feed/{channel}/{lang}/` → **стало** `/marketplace-feed/{channel}/`
- Оновлені rewrite rules (видалено regex для мови)
- Один handler замість двох

#### `includes/class-cron-manager.php`

- Видалено цикл по мовах (`foreach $languages as $lang`)
- Тепер: один виклик `$yml_generator->generate($channel)` на кожен ввімкнений канал

#### `includes/class-admin-settings.php`

- Один URL на канал замість двох (RU+UA)
- Лейбл змінено на "Білінгвальний фід (RU + UA)"

#### `includes/class-wpml-handler.php`

- **Додано новий метод** `get_default_language()`:

```php
public function get_default_language() {
    return apply_filters( 'wpml_default_language', null );
}
```

- Без нього був **fatal error** при генерації фіду

### Git коміти (вже в main, ще не запушені на remote)

```
d9f1eb1 — fix: force RU as base language for marketplace feeds
b28e8bb — fix: add missing get_default_language() to WPML handler
b632a17 — refactor: single bilingual feed per marketplace channel (v3.0.0)
```

---

## 2. Деплой: profile-driven інструкція

### Важливе правило перед деплоєм

- Є стабільна лінія плагіна, яка вже розійшлась по бойових сайтах із GitHub.
- Є окрема експериментальна лінія на `strum.biz.ua`, де доробляються supplier feeds і новий Prom import/export.
- Новий функціонал спершу доходить до стабільного стану на `strum`, потім повертається в GitHub, і лише після цього вважається спільною версією для інших сайтів.

### Локальна копія плагіна

```
/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/
```

### Сайт 1: filler.com.ua (Hostinger)

- Env profile: `filler-production`
- Формат фіду: `csv`

```bash
# Smoke
D14K_ENV_PROFILE=filler-production ./scripts/checks/run-smoke-by-profile.sh

# Deploy
D14K_ENV_PROFILE=filler-production D14K_DEPLOY_PRODUCTION_CONFIRM=DEPLOY ./scripts/deploy/deploy-by-profile.sh
```

Cloudflare purge вже вбудований у post-deploy flow профілю.

### Сайт 2: 14karat / diamonds14k.com (ChemiCloud/cPanel)

- Env profile: `14karat-production`
- Домен: `https://diamonds14k.com`
- Плагін: `gmc-feed-for-woocommerce`
- Цільовий сценарій: Google Merchant Center + Prom.ua
- Поточний status:
  - `merchant-feed/uk/` → `200`
  - `merchant-feed/ru/` → `200`
  - `marketplace-feed/prom/` → `403`

```bash
# Smoke
D14K_ENV_PROFILE=14karat-production ./scripts/checks/run-smoke-by-profile.sh

# Deploy
D14K_ENV_PROFILE=14karat-production D14K_DEPLOY_PRODUCTION_CONFIRM=DEPLOY ./scripts/deploy/deploy-by-profile.sh
```

> ⚠️ Для `14karat` не треба міряти здоров'я через Horoshop або supplier feeds. Для нього правильний контур це GMC + Prom. На production активний slug `gmc-feed-for-woocommerce`, а `d14k-merchant-feed.OLD` лежить як старий хвіст.

### Сайт 3: staging.diamonds14k.com (ChemiCloud/cPanel)

- Env profile: `diamonds14k-staging`
- Домен: `https://staging.diamonds14k.com`
- Плагін: `gmc-feed-for-woocommerce`
- Цільовий сценарій: Google Merchant Center + Prom.ua
- Поточний status:
  - `merchant-feed/uk/` → `200`
  - `merchant-feed/ru/` → `200`
  - `marketplace-feed/prom/` → `403`

```bash
# Smoke
D14K_ENV_PROFILE=diamonds14k-staging ./scripts/checks/run-smoke-by-profile.sh

# Deploy
D14K_ENV_PROFILE=diamonds14k-staging ./scripts/deploy/deploy-by-profile.sh
```

> ⚠️ Це staging для `14karat`, але зараз він живе не на `d14k-merchant-feed`, а на `gmc-feed-for-woocommerce`. Для нього теж не потрібен Horoshop. Тут треба довести до ладу саме Prom.

### Сайт 4: beautyfill.shop (Hostinger, той самий сервер що filler)

- Env profile: `beautyfill-staging`
- Поточний публічний status feed URL: повертає home page замість feed

```bash
# Smoke
D14K_ENV_PROFILE=beautyfill-staging ./scripts/checks/run-smoke-by-profile.sh

# Deploy
D14K_ENV_PROFILE=beautyfill-staging ./scripts/deploy/deploy-by-profile.sh
```

> ⚠️ beautyfill.shop — staging/тест. Перевір чи плагін взагалі активний там.

---

## 3. GitHub push

```bash
cd /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce

# Перевірити стан
git status
git log -5 --oneline

# Push (коміти вже є в main)
git push origin main

# Опціонально: тег версії
git tag -a v3.0.0 -m "feat: multi-platform marketplace feed export (Horoshop/Prom/Rozetka) — bilingual single-feed architecture [testing]"
git push origin v3.0.0
```

---

## 4. Верифікація після деплою (чеклист)

Для **кожного** сайту перевірити:

- [ ] `D14K_ENV_PROFILE=<profile> ./scripts/checks/run-smoke-by-profile.sh` → зелений
- [ ] Відкрити URL в браузері → валідний XML з `<yml_catalog>`
- [ ] Перевірити `<offers>` — є товари
- [ ] WP Admin → GMC Feed Settings → секція Feed Channels видима
- [ ] Flush rewrite rules: WP Admin → Settings → Permalinks → Save (без змін)

### Еталон — результат з filler.com.ua

| Метрика | Значення |
|---------|---------|
| Feed URL | `https://filler.com.ua/marketplace-feed/horoshop/` |
| HTTP | 200 OK |
| Offers | 134 |
| `name_ua` tags | 131 |
| Base `<name>` | Руською ✅ |
| `<name_ua>` | Українською ✅ |

---

## 5. Відоме: що ще не реалізовано

- Канали **Prom.ua** та **Rozetka** — є в архітектурі, але не ввімкнені (потрібно увімкнути в адмінці)
- Admin UI: toggle per channel — працює, але Prom/Rozetka ще не тестовані на реальних даних
- Характеристики (`<param>`) — підтягуються з WC attributes, але не перевірені на відповідність вимогам Rozetka (мін. 3 обов'язкових)
