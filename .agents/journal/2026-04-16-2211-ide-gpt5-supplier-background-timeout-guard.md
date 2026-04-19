# Ціль

Посилити supplier background import проти повторних timeout на shared hosting і зменшити втрату прогресу всередині батча.

# Що зроблено

- У `includes/class-supplier-feeds.php` додано `BACKGROUND_STEP_SOFT_LIMIT = 20`.
- Додано helper-и:
  - `get_import_step_deadline()`
  - `should_yield_import_step()`
  - `build_import_step_response()`
  - `checkpoint_background_import_progress()`
- `run_import_step()` тепер:
  - рахує soft deadline для background mode;
  - після category sync може повернути step раніше, якщо серверний бюджет часу майже вичерпано;
  - після кожного offer оновлює `current_offset` і робить checkpoint state;
  - при досягненні soft deadline повертає partial batch з повідомленням про безпечне продовження з цього ж місця.
- Логіку зроблено так, щоб при завершенні feed не було зайвого порожнього step.
- Файл задеплоєно на production `strum.biz.ua`, syntax-check на сервері пройшов, кеш очищено.

# Змінені файли

- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-supplier-feeds.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/journal/2026-04-16-2211-ide-gpt5-supplier-background-timeout-guard.md`

# Перевірки

- `php -l includes/class-supplier-feeds.php`
- `php -l` того ж файлу на production
- backup серверного файлу перед upload
- `wp cache flush` на production
- `wp option get d14k_supplier_background_import_state --format=json`

# Результат

- Background worker став більш "порційним" не тільки на рівні batch size, а й на рівні часу виконання step.
- Resume тепер має опиратися на частіший checkpoint, а не тільки на збереження після завершення всього батча.
- Live run ще не перезапускався в межах цієї сесії, тому фактичну стабільність треба підтвердити окремим прогоном `Logic Power`.

# Next Steps

- Запустити `Logic Power` resume і поспостерігати, чи зникає повторне падіння на тому ж місці.
- Якщо падіння повториться, профілювати один конкретний offer або окремо відсікати важкі image/title/description runs у background.

# Ризики

- Якщо bottleneck сидить у одному довгому WooCommerce або media-запиті, soft deadline зможе спрацювати лише між offer, а не посередині одного важкого import.
- Часті checkpoint-и збільшують кількість записів у `options`, але це свідомий обмін продуктивності на стійкість.
