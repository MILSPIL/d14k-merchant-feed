# Ціль

Спростити логіку бренду у вкладці `Google`: прибрати окремий запасний бренд, лишити поле `Бренд` і поле `Атрибут бренду`, а fallback робити з атрибута на основний бренд.

# Зроблено

- Перебудовано UI блоку `Бренд` у `Базові налаштування`:
  - прибрано radio-перемикачі режимів
  - прибрано окреме поле `Запасний бренд`
  - лишено два поля:
    - `Бренд`
    - `Атрибут бренду`
- Оновлено copy блоку, щоб він прямо описував нову логіку fallback.
- Оновлено save-логіку налаштувань:
  - `brand_mode` тепер обчислюється автоматично з наявності `brand_attribute`
  - `brand_fallback` очищається і більше не використовується в UI
- Оновлено генератори:
  - `class-feed-generator.php`
  - `class-yml-generator.php`
  - `class-csv-generator.php`
  Логіка тепер така:
  - якщо вибрано `brand_attribute`, генератор пробує взяти бренд з атрибута
  - якщо атрибут порожній або не заданий, повертається значення з `brand`
  - якщо `brand` теж порожній, використовується `get_bloginfo('name')`
- Оновлено validator:
  - бренд вважається валідним, якщо є або значення `brand`, або вибраний `brand_attribute`
- Прибрано старі brand-mode стилі з `admin.css`

# Змінені файли

- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-feed-validator.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-feed-generator.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-yml-generator.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-csv-generator.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.css`

# Перевірки

- `php -l /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `php -l /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-feed-validator.php`
- `php -l /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-feed-generator.php`
- `php -l /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-yml-generator.php`
- `php -l /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-csv-generator.php`
- `node -c /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js`
- deploy через `rsync`
- remote `php -l` для всіх змінених PHP-файлів
- `wp cache flush` на production
- Chrome DevTools live-check:
  - у блоці `Бренд` видно тільки `Бренд` і `Атрибут бренду`
  - radio-перемикачів і `Запасний бренд` більше немає

# Результат

Логіка бренду стала простішою і ближчою до реального сценарію користувача: атрибут, якщо він є, має пріоритет, а fallback завжди береться з основного поля `Бренд`.

# Next Steps

- Оцінити, чи треба ще сильніше ущільнити пару `Бренд / Країна походження`
- Продовжити точковий polish вкладки `Google` після спрощення базових налаштувань

# Ризики

- У старих збережених налаштуваннях може лишатися ключ `brand_fallback`, але новий код його вже ігнорує.
