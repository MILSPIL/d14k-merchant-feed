## Ціль задачі

Прибрати плутанину зі збереженням supplier feed, додати окреме збереження для кожного постачальника, зробити UI рядка більш цілісним і перевірити це на production без випадкового запуску імпорту.

## Зроблено

- Додано новий AJAX handler `d14k_supplier_feed_save_row`.
- Винесено спільну санітизацію одного supplier feed у `sanitize_supplier_feed_row()` і підключено її як для повного save форми, так і для row-save.
- На вкладці `Постачальники` змінено layout рядка feed:
  - явні підписи `URL фіду`, `Назва постачальника`, `Націнка %`
  - локальний footer для дій по рядку
  - окрема кнопка `Зберегти feed`
  - окремий inline-результат для цього feed
  - `Увімкнений` і `Видалити` перенесені в meta-блок рядка
- Нижню кнопку перейменовано на `Зберегти розклад і весь список постачальників`, щоб вона не виглядала як єдиний спосіб зберегти один feed.
- У JS додано:
  - збір payload одного рядка
  - AJAX-save тільки цього feed
  - локальні повідомлення `pending/success/error`
  - dirty-state `Є незбережені зміни в цьому feed.`
- У CSS оновлено layout supplier card, footer actions і візуальне виділення dirty-state.
- На production задеплоєно `class-admin-settings.php`, `admin.js`, `admin.css`.
- Через Chrome DevTools перевірено живий клік по `Зберегти feed`:
  - пішов тільки `POST admin-ajax.php`
  - action у request body: `d14k_supplier_feed_save_row`
  - background import не стартував
  - на сторінці з'явилось `Налаштування цього постачальника збережено.`

## Змінені файли

- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.css`

## Перевірки

- `php -l /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `node -e "const fs=require('fs'); new Function(fs.readFileSync('/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js','utf8')); console.log('admin.js syntax OK')"`
- `rsync` трьох змінених файлів на production
- `php -l` remote `class-admin-settings.php`
- md5 local/remote для `class-admin-settings.php`, `admin.js`, `admin.css`
- Chrome DevTools:
  - reload suppliers tab
  - snapshot нового layout
  - live click по `Зберегти feed`
  - перевірка request body для `admin-ajax.php`

## Результат

- Для кожного supplier feed тепер є окреме збереження без запуску імпорту.
- `Націнка %` більше не виглядає як незрозумілий порядковий номер.
- Рядок feed став більш зібраним і зрозумілим по діях.
- Bulk save внизу лишився тільки для розкладу і масового збереження всього списку.

## Next steps

- Після цього можна спокійно задати `Корінь категорій` для LogicPower і зберегти тільки цей feed.
- Далі запустити import і перевірити, що дерево категорій поїхало під root.
- Якщо UI ще хочеться спростити, наступним кроком можна додати presets типу `Швидке`, `Повне`, `Власне` для блоку полів оновлення.

## Ризики

- Row-save зберігає тільки один feed, але глобальні поля `увімкнення обробки` і `розклад` як і раніше зберігаються нижньою bulk-кнопкою.
- Якщо змінити кілька feed і не натискати їхні локальні кнопки, ці зміни залишаться лише в DOM до bulk-save або reload.
