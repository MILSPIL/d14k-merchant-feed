# Ціль

Знайти кореневу причину, чому у вкладці `Google` селект `Атрибут бренду` порожній, і прибрати тимчасовий AJAX fallback, якщо проблема вирішується на server-side.

# Зроблено

- Перевірено production по SSH:
  - WooCommerce реально має 7 глобальних атрибутів
  - серед них є `Brand -> pa_brand`
- Перевірено raw HTML відповіді адмін-сторінки через браузерний `fetch`:
  - page response не містив `<option value="pa_brand">`
  - значить проблема була не в CSS і не в браузері, а в PHP-рендері сторінки
- Знайдено корінь:
  - вкладка `Google` рендериться через окремий метод `render_google_tab(...)`
  - у `render_page()` масиви `$wc_attributes` і `$brand_attribute_options` були обчислені, але не передавались у `render_google_tab(...)`
  - тому всередині шаблону вони були `undefined`, через що селект виходив порожнім
- Виправлено:
  - передано `$wc_attributes` і `$brand_attribute_options` у `render_google_tab(...)`
  - оновлено сигнатуру `render_google_tab(...)`
  - прибрано тимчасовий debug-коментар
  - прибрано тимчасовий AJAX workaround `d14k_get_brand_attributes`
  - прибрано JS fallback `populateBrandAttributeSelects()`

# Змінені файли

- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js`

# Перевірки

- `php -l /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `node -c /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js`
- deploy через `rsync`
- remote:
  - `php -l /home/u731710222/domains/strum.biz.ua/public_html/wp-content/plugins/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
  - `wp --path=/home/u731710222/domains/strum.biz.ua/public_html cache flush`
- Chrome DevTools:
  - raw HTML page response тепер містить `<option value="pa_brand">`
  - `data-d14k-attribute-options=\"null\"` більше немає
  - DOM-селект має 8 пунктів
  - `Brand (pa_brand)` присутній без AJAX fallback

# Результат

Селект `Атрибут бренду` знову працює як простий server-render елемент. Додатковий AJAX-запит більше не потрібен.

# Next Steps

- За потреби підчистити текст option-ів у селекті, щоб прибрати зайві пробіли від HTML-розмітки
- Продовжити polish вкладки `Google`

# Ризики

- Істотних ризиків немає. Проблема була в непереданих параметрах між методами, не в даних WooCommerce.
