## date and time

- `2026-04-14 17:05 Europe/Kiev`

## IDE / agent

- `Codex desktop`

## model

- `gpt-5`

## task goal

- Почати реальний refactor адмінки плагіна за новим UI-планом.
- Розділити канали по вкладках і прибрати найбільшу плутанину в `Prom.ua Sync`.

## what was done

- У `includes/class-admin-settings.php` додано нову top-level вкладку `Огляд`.
- Стару логіку `Фіди` рознесено:
  - Google feed-частина винесена у вкладку `Google`
  - marketplace/system summary перенесено в `Огляд`
- `Prom.ua Sync` розділено на дві окремі вкладки:
  - `Prom`
  - `Постачальники`
- Додано окремі placeholder-вкладки:
  - `Rozetka`
  - `Facebook`
- Для зворотної сумісності додано alias:
  - `feeds` -> `overview`
  - `prom_sync` -> `prom`
- Оновлено save-flow:
  - вкладка `prom` зберігає тільки Prom-related налаштування
  - вкладка `suppliers` зберігає тільки supplier feed settings
  - старий `prom_sync` лишено як backward-compatible fallback
- У refactored секціях прибрано частину емодзі з заголовків, кнопок, summary і AJAX status messages.
- У `assets/admin.css` додано нові reusable UI-елементи:
  - `d14k-overview-grid`
  - `d14k-overview-item`
  - `d14k-empty-state`
  - `d14k-badge--warning`

## changed files

- `includes/class-admin-settings.php`
- `assets/admin.css`
- `.agents/journal/2026-04-14-1705-ide-gpt5-admin-tabs-first-refactor.md`

## commands / checks / tests

- `php -l includes/class-admin-settings.php`
- `php -l assets/admin.css`
- `grep -n "prom_sync\|feeds\|✅\|⚠\|🔄\|⬇️\|⬆️\|💾" includes/class-admin-settings.php`

## result

- Адмінка вже має більш правильну структуру під кілька каналів.
- `Prom` і `Постачальники` більше не змішані в одній вкладці.
- Закладено основу для окремих вкладок `Rozetka` і `Facebook`.

## next steps

1. Перенести `Маппінг` у `Google`.
2. Перенести `Фільтри` у `Google` або `Налаштування`.
3. Прибрати решту емодзі з усього `class-admin-settings.php`.
4. Винести render логіку вкладок у helper methods.
5. Уніфікувати `System Status` і `Validation` під спільні UI-компоненти без inline-style.

## risks / notes

- Це перший етап рефакторингу, не фінальна структура.
- `Маппінг` і `Фільтри` ще лишилися top-level вкладками до наступного проходу.
- Візуальну перевірку в браузері після деплою ще треба зробити окремо.
