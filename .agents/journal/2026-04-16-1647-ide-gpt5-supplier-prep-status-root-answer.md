## Ціль задачі

Прибрати хибний стартовий стан `0 з 1` у background supplier import, викласти фікс на production і зафіксувати поведінку `Корінь категорій` для вже створених supplier-категорій.

## Зроблено

- Перевірено код статус-картки supplier import у `assets/admin.js`.
- Підтверджено причину UX-проблеми: до завершення `prepare_feed_dataset()` UI вже намагався показувати батчі без реального `offers_total`.
- Додано окремий підготовчий стан для background import:
  - `queued`
  - `preparing`
  - `sync_categories`
- Тепер до появи реального `offers_total` картка показує стадію підготовки feed і короткий опис, а не фальшивий батч `0 з 1`.
- Підтверджено по коду поведінку `Корінь категорій`:
  - на наступному імпорті вже створені категорії цього supplier feed будуть переприв'язані під новий root
  - це стосується саме категорій, знайдених за `source_key + external category id`
- Оновлений `assets/admin.js` задеплоєно на production через `rsync`.
- Після заливки перевірено збіг md5 local/remote.

## Змінені файли

- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js`

## Перевірки

- `node -e "const fs=require('fs'); new Function(fs.readFileSync('/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js','utf8')); console.log('admin.js syntax OK')"`
- `rsync -avz -e "ssh -p 65002 -i ~/.ssh/hostinger_techmash" .../assets/admin.js .../wp-content/plugins/gmc-feed-for-woocommerce/assets/admin.js`
- `md5` локального `admin.js`
- `md5sum` remote `admin.js`

## Результат

- Production більше не повинен показувати штучний стартовий прогрес `0 з 1` до підготовки feed.
- Під час старту background import користувач бачитиме чесну фазу підготовки.
- Для supplier root підтверджено, що вже створені категорії цього постачальника будуть переміщені під указаний root на наступному імпорті.

## Next steps

- Перезавантажити вкладку `Постачальники` і перевірити живий старт нового run з оновленим prep-state.
- Якщо UI виглядатиме добре, задати для LogicPower `Корінь категорій` і знову прогнати import.
- Після цього можна перейти до політики для товарів, яких більше немає у feed.

## Ризики

- Переміщення під root відбудеться на наступному імпорті, а не миттєво після збереження налаштування.
- Якщо в адмінці лишилася стара вкладка, новий `admin.js` підтягнеться тільки після reload.
