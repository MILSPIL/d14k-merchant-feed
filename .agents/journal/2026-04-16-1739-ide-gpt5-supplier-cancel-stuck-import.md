# Ціль

Додати можливість зупиняти завислий background import supplier feed і зробити статус чесним, якщо Action Scheduler step уже помер або був скасований.

## Що зроблено

- Додано окремий AJAX endpoint `d14k_supplier_feeds_background_cancel` і кнопку `Скасувати імпорт` у supplier action bar.
- У `D14K_Supplier_Feeds` додано `cancel_background_import()`:
  - знімає queued async actions для supplier import;
  - ставить state у `cancelled`;
  - записує повідомлення `Імпорт скасовано користувачем.`
- У worker додано захист на випадок, коли cancel натиснули під час уже запущеного batch:
  - поточний batch завершується;
  - наступний не планується;
  - state зберігається як `cancelled`.
- Додано reconcile для background state:
  - якщо state ще `queued/running`, але активних Action Scheduler jobs уже немає і `updated_at` давно не рухався, state переводиться у `failed`;
  - з state прибирається фальшиве враження, що імпорт ще живий.
- У `admin.js` додано:
  - статус `cancelled`;
  - керування кнопками `Оновити у фоні` / `Скасувати імпорт`;
  - окремий запит на cancel;
  - коректний рендер карточки після cancel або fail.

## Змінені файли

- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-supplier-feeds.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js`

## Перевірки

- `php -l /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-supplier-feeds.php`
- `php -l /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `node -e "const fs=require('fs'); new Function(fs.readFileSync('/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js','utf8')); console.log('admin.js syntax OK')"`
- `rsync` змінених файлів на production
- `ssh -p 65002 -i ~/.ssh/hostinger_techmash techmash "wp --path=/home/u731710222/domains/strum.biz.ua/public_html cache flush"`
- Live перевірка через `chrome-devtools`:
  - на `Постачальники` видно кнопку `Скасувати імпорт`;
  - завислий run `Logic Power • Батч 17 з 64` був зупинений через кнопку;
  - після кліку UI перейшов у `Фоновий імпорт скасовано`, стадія `Скасовано`, кнопка `Оновити у фоні` знову активна.

## Результат

- Користувач тепер може вручну зупинити завислий background import без перезавантаження всієї логіки.
- UI більше не зобов'язаний назавжди висіти у `running`, якщо фактичний step уже помер.
- Для поточного завислого імпорту зафіксований підсумок на момент зупинки:
  - `Батч 17 з 64`
  - `Оферів: 1590`
  - `Оброблено: 425`
  - `Оновлено: 63`
  - `Пропущено: 362`

## Next steps

- Зменшити фактичний batch size background import або адаптувати його для update-only run, щоб не впиратись у timeout 300 секунд.
- Додати у статус більше явної інформації про timeout, якщо step впав саме так.
- Після цього ще раз прогнати Logic Power з полями `ціна + наявність + категорія` і перевірити, що run проходить без зависання.

## Ризики

- Cancel не вбиває вже виконуваний PHP request посередині. Якщо batch уже стартував, він догорить до кінця, і тільки після цього імпорт остаточно стане `cancelled`.
- Reconcile у `failed` спирається на відсутність активних jobs і затримку за `updated_at`, тому для дуже нестандартного серверного середовища поріг може потребувати окремого тюнінгу.
