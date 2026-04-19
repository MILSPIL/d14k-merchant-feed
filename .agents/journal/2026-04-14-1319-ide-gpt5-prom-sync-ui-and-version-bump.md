## date and time

- `2026-04-14 13:19 Europe/Kiev`

## IDE / agent

- `Codex desktop`

## model

- `gpt-5`

## task goal

- Проаналізувати і поправити дизайн та логіку вкладки `Prom.ua Sync`.
- Прибрати рвані відступи, inline-стилі та плутанину з розкладом експорту.
- Підняти номер збірки, щоб на `strum.biz.ua` було видно свіжу версію.

## what was done

- Перероблено розмітку вкладки `Prom.ua Sync` у `class-admin-settings.php`:
  - API token row переведено на спільний inline-actions pattern
  - import/export/supplier action rows переведено на спільні `d14k-action-bar`
  - статистичні блоки переведено на єдині summary cards
  - footer із кнопкою `Зберегти налаштування Prom` переведено на `d14k-submit-row`
  - додано окремий `Розклад автоекспорту` для Prom export
  - `URL фіду` переведено у вертикальний stack з читабельним auto-URL
- Перероблено JS у вкладці:
  - додано helper `setSyncMessage()`
  - результати import/export/test/supplier actions тепер мають єдиний візуальний стан `pending/success/error`
  - прибрано ручне зафарбовування `span` через inline `css('color', ...)`
- Додано в `class-cron-manager.php` helper `get_next_prom_export_run()`.
- Виправлено save-flow для `Prom export`:
  - додано `prom_export_interval`
  - export cron більше не залежить від `prom_import_interval`
- Оновлено стилі в `assets/admin.css`:
  - уніфіковано стилі для `input[type="url"]` і `input[type="password"]`
  - додано utility classes для action bars, summaries, supplier rows і responsive footer
- Піднято версію плагіна до `4.0.1`.
- Задеплоєно на `strum.biz.ua` тільки змінені файли через `rsync`.
- На сервері перевірено, що `d14k-merchant-feed.php` вже містить `Version: 4.0.1`.

## changed files

- `assets/admin.css`
- `d14k-merchant-feed.php`
- `includes/class-admin-settings.php`
- `includes/class-cron-manager.php`
- `includes/class-supplier-feeds.php`
- `.agents/journal/2026-04-14-1319-ide-gpt5-prom-sync-ui-and-version-bump.md`

## commands / checks / tests

- `php -l d14k-merchant-feed.php`
- `php -l includes/class-admin-settings.php`
- `php -l includes/class-cron-manager.php`
- `rsync --relative ... d14k-merchant-feed.php assets/admin.css includes/class-admin-settings.php includes/class-cron-manager.php includes/class-supplier-feeds.php ...strum.../wp-content/plugins/gmc-feed-for-woocommerce/`
- `ssh techmash "wp --path=/home/u731710222/domains/strum.biz.ua/public_html cache flush"`
- `ssh techmash "grep -n \"Version:\\|D14K_FEED_VERSION\" .../d14k-merchant-feed.php"`

## result

- Вкладка `Prom.ua Sync` тепер має більш цілісний layout і однаковий ритм між секціями.
- `Prom export` отримав власний розклад і власний `next run`.
- На `strum.biz.ua` уже залита збірка `4.0.1`.

## next steps

1. Відкрити вкладку `Prom.ua Sync` в адмінці й перевірити реальний вигляд після hard refresh.
2. Якщо треба, окремо допиляти `Prom import status`, щоб він виводився в UI-блоці, а не через `alert()`.
3. Після цього перейти до наступних рев'ю-пунктів по логіці імпорту та supplier feeds.

## risks / notes

- У локальному репозиторії є багато сторонніх незадеплоєних змін, тому на прод лилися лише вибрані файли.
- Для повної візуальної перевірки потрібен перегляд адмінки вже в браузері після очищення кешу.
