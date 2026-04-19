# Ціль

Завершити compact-pass для блоку `Бренд` у вкладці `Google`, прибрати стрибки layout при перемиканні режиму бренду і перевірити live після publish.

# Зроблено

- Перебудовано логіку `Бренд` так, щоб обидва панелі були видимі постійно:
  - поле `Власний бренд` лишається поруч зі своїм radio
  - select `З атрибуту товару` теж лишається поруч зі своїм radio
- При перемиканні режиму тепер змінюється тільки active і inactive стан рядків, без hide/show.
- Прибрано використання `d14k-brand-mode-panel--hidden` для brand-mode панелей.
- Додано стани `is-active` і `is-inactive` для рядків бренду.
- Під час live-перевірки знайдено причину хибного враження, що зміна не накатилось:
  - PHP на сервері вже був новий
  - браузерна сторінка тримала старий `admin.js`
  - після явного reload сторінки нова логіка підтвердилась

# Змінені файли

- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.css`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js`

# Перевірки

- `php -l /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `node -c /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js`
- rsync deploy `class-admin-settings.php`, `admin.css`, `admin.js` на production
- remote:
  - `php -l /home/u731710222/domains/strum.biz.ua/public_html/wp-content/plugins/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
  - `wp --path=/home/u731710222/domains/strum.biz.ua/public_html cache flush`
- Chrome DevTools live-check:
  - до reload сторінка ще тримала старий hidden-class через старий JS
  - після reload обидва wrap стоять `display: grid`
  - при `custom` active custom-row, attr-row inactive
  - при `attribute` active attr-row, custom-row inactive
  - console errors і warnings відсутні

# Результат

Блок `Бренд` працює так, як попросив користувач: обидва поля стоять поруч постійно, layout не стрибає, а перемикач тільки міняє активний і неактивний стан.

# Next Steps

- Продовжити точковий compact-pass для `Базові налаштування` у вкладці `Google`
- Перевірити, чи треба ще сильніше ущільнити `Країна походження` або нижній save-row
- Продовжити візуальне вирівнювання інших секцій `Google` після accordion-pass

# Ризики

- Якщо браузер довго тримає стару вкладку адмінки без reload, користувач може ще бачити попередній JS до жорсткого оновлення сторінки.
