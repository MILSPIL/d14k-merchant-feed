## Ціль задачі

Окремо пройти вкладку `Google` у плагіні `gmc-feed-for-woocommerce`, прибрати ефект подвійних контейнерів, перевести великі другорядні секції в accordion-логіку та зібрати вкладку в той самий UI-ритм, що вже був зроблений для `Постачальники`.

## Зроблено

- Перебудовано `Google Merchant Center` з важкої `form-table` у cleaner settings-grid з двома окремими cards:
  - `Бренд`
  - `Країна походження`
- Для великих секцій увімкнено accordion-подачу через `<details>`:
  - `Маппінг категорій Google`
  - `Custom Labels (0–4)`
  - `Виключені категорії`
  - `Фільтрація за атрибутами`
  - `Розширені правила фільтрації`
- Додано авто-open логіку для accordion-секцій, якщо в них уже є значення або активні налаштування.
- Вирівняно `Google` під правило `одна секція = один shell`.
- Для inner `widefat` і `form-table` у Google-секціях прибрано зовнішній shell-look:
  - без зайвого border-radius
  - без окремої важкої оболонки всередині card
  - без випадкових header-скруглень
- Додано cleaner summary/header для accordion-секцій:
  - title
  - status badge
  - короткий help-copy
  - chevron
- Зібрано submit-зони в нормальні `d14k-google-form__actions`, замість голих `p.submit`.
- Оновлено responsive-поведінку для нового Google-layout.

## Змінені файли

- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.css`

## Перевірки

- `php -l /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- deploy через `rsync` на production
- remote check:
  - `php -l /home/u731710222/domains/strum.biz.ua/public_html/wp-content/plugins/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
  - `wp --path=/home/u731710222/domains/strum.biz.ua/public_html cache flush`
- live-перевірка у Chrome DevTools на:
  - `https://strum.biz.ua/wp-admin/admin.php?page=d14k-merchant-feed&tab=google`
- console check: без помилок

## Результат

Вкладка `Google` стала значно спокійнішою й логічнішою. Великі другорядні секції тепер можна тримати згорнутими, а головні налаштування зверху вже не виглядають як старий WordPress-table всередині нової card-оболонки.

## Next steps

- Пройтись окремо по дрібних косяках `Google` уже точково після user-review.
- За потреби ще підтягнути `Журнал Google` і `Фіди`, якщо користувач захоче ще щільнішу композицію.
- За таким самим принципом добити інші вкладки, якщо користувач помітить залишки старого nested-shell layout.

## Ризики

- Accordion-секції працюють на native `<details>`, тому їхня відкритість не запам'ятовується між reload без окремого JS-state.
- `Custom Labels` відкриваються автоматично, якщо в мапі вже є значення. Якщо користувач захоче іншу логіку open-state, це треба буде окремо змінити.
