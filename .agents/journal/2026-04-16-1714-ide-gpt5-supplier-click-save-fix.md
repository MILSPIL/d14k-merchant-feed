## Ціль задачі

Прибрати UX-баг у рядку supplier feed: зробити всю зону поля клікабельною для фокусу та прибрати стан, коли `Зберегти feed` після попередньої спроби лишається недоступною.

## Зроблено

- У `assets/admin.js` додано `resetSupplierRowSaveButton()`, щоб після будь-якої нової зміни в рядку кнопка локального збереження гарантовано поверталась у нормальний стан `Зберегти feed`.
- Для `input` і `change` у supplier-row додано примусовий reset кнопки перед показом dirty-state.
- Додано делегований click-handler по `#d14k-supplier-feeds-list`, який переводить фокус у перше поле всередині:
  - `.d14k-supplier-feed-row__field`
  - `.d14k-supplier-feed-row__group`
- У `assets/admin.css` для цих зон додано `cursor: text`, щоб поведінка візуально відповідала редагуванню.
- Оновлені `admin.js` і `admin.css` задеплоєно на production.
- На production через Chrome DevTools перевірено:
  - клік по всій зоні `Корінь категорій` тепер фокусує input
  - після редагування `Logic Power` кнопка `Зберегти feed` лишається активною
  - локальне збереження проходить окремим AJAX-запитом без запуску імпорту
  - у request body пішло `feed[category_root]=Logic Power`
  - відповідь сервера: `Налаштування цього постачальника збережено.`

## Змінені файли

- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.css`

## Перевірки

- `node -e "const fs=require('fs'); new Function(fs.readFileSync('/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js','utf8')); console.log('admin.js syntax OK')"`
- `rsync` оновлених `admin.js` і `admin.css` на production
- Chrome DevTools:
  - перевірка focus по всій зоні поля
  - перевірка re-enable кнопки після редагування
  - live save через `d14k_supplier_feed_save_row`
  - перевірка request body і response

## Результат

- Поле `Корінь категорій` більше не вимагає влучати точно в текстовий рядок.
- `Зберегти feed` більше не зависає для користувача після нового редагування.
- Значення `Logic Power` збережено окремим row-save на production.

## Next steps

- Запустити наступний import і перевірити, що supplier-категорії реально переїхали під root `Logic Power`.
- Якщо захочеться, наступним окремим кроком можна ще посилити supplier-row UI через preset-кнопки для `Швидке` / `Повне` / `Власне`.

## Ризики

- Якщо вкладка довго не оновлювалась і лишився старий JS у кеші браузера, користувач побачить стару поведінку до reload.
