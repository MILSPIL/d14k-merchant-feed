# Ціль
Опублікувати погоджений пас правок для supplier feed cards у вкладці `Постачальники`: зібрати верхній header в один ряд, прибрати host-рядок зверху і допиляти блок `Джерело`, щоб `Націнка` візуально збігалася з сусідніми полями.

# Зроблено
- Прибрано верхній host / eyebrow-рядок із header feed-картки.
- Зібрано верхній header у один ряд: назва feed, schedule badges, toggle.
- Перебудовано поле `Націнка`:
  - label скорочено до `Націнка`
  - додано текстову одиницю `відсотків` усередині поля
  - ширину вирівняно з `Корінь категорій`
  - висоту і вертикальну позицію вирівняно з `URL фіду`
- Оновлено шаблон для нових feed rows у `admin.js`, щоб нові картки створювались уже з новою структурою.
- Залито зміни на production і очищено cache.

# Змінені файли
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.css`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js`

# Перевірки
- `php -l /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `node -e "const fs=require('fs'); const path='/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js'; new Function(fs.readFileSync(path,'utf8')); console.log('admin.js syntax OK')"`
- `ssh -i /Users/user/.ssh/hostinger_techmash -p 65002 u731710222@45.84.206.62 "php -l /home/u731710222/domains/strum.biz.ua/public_html/wp-content/plugins/gmc-feed-for-woocommerce/includes/class-admin-settings.php"`
- `ssh -i /Users/user/.ssh/hostinger_techmash -p 65002 u731710222@45.84.206.62 "wp --path=/home/u731710222/domains/strum.biz.ua/public_html cache flush"`
- Live-перевірка в Chrome DevTools на `https://strum.biz.ua/wp-admin/admin.php?page=d14k-merchant-feed&tab=suppliers&d14k_notice=saved`
- Перевірка console warnings/errors: чисто
- Перевірка live-розмірів полів у браузері:
  - `URL фіду`: `43px × 352px`
  - `Націнка`: `43px × 170px`
  - `Назва постачальника`: `43px × 352px`
  - `Корінь категорій`: `43px × 170px`

# Результат
Погоджений варіант опубліковано на production. Header feed-картки став чистішим, а блок `Джерело` тепер читається рівніше й компактніше без візуального перекосу поля `Націнка`.

# Next steps
- Продовжити дрібний polishing supplier feed cards зверху вниз від live-версії.
- За потреби ще підчистити глобальний блок `Стан черги`, якщо користувач захоче прибрати дублювання статусів ще сильніше.

# Ризики
- Текст `відсотків` усередині поля `Націнка` займає частину корисної ширини. Якщо користувач захоче довші значення або інший формат, може знадобитися ще один мікропас по padding.
