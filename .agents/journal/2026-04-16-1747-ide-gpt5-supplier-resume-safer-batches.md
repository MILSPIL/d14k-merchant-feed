# Ціль

Прибрати повторне зависання supplier background import і додати реальне продовження після `failed` або `cancelled`, а не старт із нуля.

## Що зроблено

- `start_background_import()` більше не завжди скидає старий state.
- Якщо supplier import був у `failed` або `cancelled` і ще не завершений повністю, новий запуск:
  - підхоплює `current_feed/current_offset`;
  - зберігає вже оброблені результати;
  - продовжує з того місця, де зупинився.
- Додано `can_resume` у background status, щоб UI міг показувати `Продовжити у фоні`.
- Змінено логіку batch size для background import:
  - для важчих оновлень з картинками, описами або характеристиками batch зменшується сильніше;
  - для update-only прогонів без важкого контенту batch теж зменшується до безпечнішого значення;
  - при resume batch size ще трохи зменшується, щоб не впертись у той самий timeout повторно.
- При `failed` або `cancelled` тимчасові dataset-файли більше не видаляються одразу, щоб resume міг реально продовжити роботу.
- У JS:
  - кнопка показує `Продовжити у фоні`, якщо є resumable state;
  - стартове повідомлення для resume відрізняється від нового запуску;
  - після reload користувач бачить, що імпорт можна продовжити з цього місця.

## Змінені файли

- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-supplier-feeds.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js`

## Перевірки

- `php -l /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-supplier-feeds.php`
- `node -e "const fs=require('fs'); new Function(fs.readFileSync('/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js','utf8')); console.log('admin.js syntax OK')"`
- `rsync` змінених файлів на production
- `ssh -p 65002 -i ~/.ssh/hostinger_techmash techmash "wp --path=/home/u731710222/domains/strum.biz.ua/public_html cache flush"`
- Live перевірка через `chrome-devtools`:
  - після reload кнопка показувала `Продовжити у фоні`;
  - status пояснював, що import можна продовжити з цього місця;
  - після кліку resume не стартував з нуля, а продовжився з уже збережених `425` оброблених оферів;
  - далі live status дійшов до `Оброблено: 1025`, `Оновлено: 282`, `Пропущено: 743`, тобто resume реально пішов вперед.

## Результат

- Поточний supplier import тепер можна не тільки скасувати, а й продовжити.
- Продовження вже перевірене на live і не стартує заново з першого батча.
- Ризик повторного timeout зменшено через менший background batch size для таких запусків.

## Next steps

- Дочекатися завершення поточного resumed run і перевірити, чи проходить він до кінця без нового timeout.
- Якщо навіть із меншим batch size буде ще одне падіння, зменшити batch ще на крок або винести category update в окремий легший прохід.
- Після стабілізації background run можна додати окрему кнопку `Почати спочатку`, щоб користувач мав вибір між resume і fresh start.

## Ризики

- Resume спирається на збережений dataset та offset. Якщо feed між падінням і продовженням радикально зміниться, логіка все одно продовжить від старого offset.
- Тимчасові dataset-файли тепер живуть довше для підтримки resume, тому їх треба буде акуратно прибирати після завершення або при явному fresh start.
