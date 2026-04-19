# Ціль

Пройтися по оновленій production-адмінці плагіна на `strum.biz.ua` після деплою refactor-версії `4.0.1` і перевірити базову працездатність вкладок `Google`, `Prom` і `Постачальники`.

# Зроблено

- Відкрито production-сторінку плагіна:
  - `https://strum.biz.ua/wp-admin/admin.php?page=d14k-merchant-feed&tab=google`
  - `https://strum.biz.ua/wp-admin/admin.php?page=d14k-merchant-feed&tab=prom`
  - `https://strum.biz.ua/wp-admin/admin.php?page=d14k-merchant-feed&tab=suppliers`
- Підтверджено, що на проді вже показується нова top-level навігація:
  - `Огляд`
  - `Google`
  - `Налаштування`
  - `Prom`
  - `Постачальники`
  - `Rozetka`
  - `Facebook`
  - `Довідка`
- Перевірено вкладку `Google`:
  - рендеряться `Фіди`, `Маппінг`, `Фільтри`
  - підтягуються `default_google_category`, category mapping і custom labels
  - дерево excluded categories і attribute filters відображаються
- Перевірено вкладку `Постачальники`:
  - порожній state показується коректно
  - кнопка `Додати фід постачальника` додає новий рядок без JS-помилок
  - кнопка `Видалити` прибирає рядок без JS-помилок
- Перевірено вкладку `Prom`:
  - рендеряться секції `API-ТОКЕН PROM.UA`, `ІМПОРТ`, `ЕКСПОРТ`
  - поля, чекбокси, select-и та action buttons відображаються
- Перевірено console:
  - критичних JS-помилок немає
  - є тільки `JQMIGRATE` log і `3PC blocked` debug

# Змінені файли

- `.agents/journal/2026-04-14-1720-ide-gpt5-production-ui-walkthrough.md`

# Перевірки

- browser snapshots у `chrome-devtools`
- browser click test для `Додати фід постачальника`
- browser click test для `Видалити`
- `console messages` у `chrome-devtools`

# Результат

Після деплою production-UI працює стабільно на базовому рівні: нова структура вкладок вже активна, перенесений у `assets/admin.js` функціонал не дав regressions у перевірених сценаріях, вкладки `Google`, `Prom` і `Постачальники` відкриваються й взаємодіють без явних JS-збоїв.

# Next steps

- Підчистити візуальну систему `Prom` і `Постачальники`: порожні state, action bars, підказки, spacing між групами полів.
- Уніфікувати стиль заголовків і helper-текстів між `Google`, `Prom` і `Постачальники`.
- Розбити `assets/admin.js` на секції або модулі за вкладками, щоб він не розростався в один великий файл.

# Ризики

- Перевірка була без реального збереження форм і без live API-операцій, тому mutation-flow ще треба окремо проходити вручну.
- Візуальний refactor ще не завершений: логіка вже чистіша, але `Prom` і `Постачальники` все ще нерівні по дизайну відносно верхнього shell.
