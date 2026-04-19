# Ціль

Додати в supplier background import підтримку запуску одного feed окремо та автопродовження після збою без участі користувача.

# Що зроблено

- `includes/class-supplier-feeds.php`:
  - `start_background_import()` тепер приймає `requested_feeds` і `auto_continue`;
  - додано scope state: `scope_mode`, `scope_feed_urls`, `scope_label`;
  - додано watchdog hook `d14k_supplier_background_import_watchdog`;
  - додано авто-continue policy з лімітом `24` спроб у тій самій точці;
  - при `failed` state тепер можна не тільки чекати ручного `resume`, а й автоматично поставити новий step у чергу.
- `includes/class-admin-settings.php`:
  - background AJAX старт приймає `feed[...]` для запуску одного рядка;
  - зберігається setting `supplier_background_auto_continue`.
- `includes/class-cron-manager.php`:
  - cron-start supplier import тепер передає в background worker збережений прапорець автопродовження.
- `assets/admin.js`:
  - додано row-level background run button;
  - додано збір `feed[...]` payload для одного постачальника;
  - додано `#d14k-supplier-auto-continue`;
  - row buttons тепер знають, коли черга зайнята, а коли це саме їхній resumable single-feed run.
- `assets/admin.css`:
  - додано стиль для badge-like чекбокса автопродовження.
- Правку задеплоєно на production `strum.biz.ua`.

# Змінені файли

- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-supplier-feeds.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-cron-manager.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.css`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/journal/2026-04-16-2226-ide-gpt5-supplier-single-feed-and-auto-continue.md`

# Перевірки

- `php -l includes/class-supplier-feeds.php`
- `php -l includes/class-admin-settings.php`
- `php -l includes/class-cron-manager.php`
- `node -e ... admin.js syntax OK`
- server-side `php -l` для тих самих PHP файлів
- `wp cache flush` на production
- live snapshot supplier tab через `chrome-devtools`

# Результат

- Feature gap із single-feed background run закрито.
- Feature gap із unattended resume теж закрито на рівні architecture: тепер є watchdog і auto-continue state.
- Реальний single-feed import я не стартував автоматично в цій сесії, тому live business-ефект ще треба підтвердити окремим запуском.

# Next Steps

- Перевірити live сценарій:
  - увімкнути `Автопродовження після збою`;
  - запустити `Logic Power` тільки по рядку;
  - подивитися, чи після падіння step система сама ставить resume без ручного кліку.
- Якщо знадобиться, додати row-level `fresh start`.

# Ризики

- Watchdog реагує після server-side timeout в наступне вікно перевірки, а не миттєво.
- Якщо feed payload із UI відрізняється від збереженого global settings, row-start свідомо поїде саме з UI-значеннями цього рядка.
