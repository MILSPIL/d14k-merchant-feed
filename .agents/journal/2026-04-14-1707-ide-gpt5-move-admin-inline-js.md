# Ціль

Прибрати решту inline JavaScript з адмінки плагіна та перевести поведінку UI в `assets/admin.js`, щоб `class-admin-settings.php` відповідав новим правилам структури UI.

# Зроблено

- Видалено великий inline `<script>` з `render_page()` у `includes/class-admin-settings.php`.
- Видалено фінальний inline `<script>` наприкінці `render_page()` з логікою copy, brand mode, category cascade та custom rules.
- Перенесено поведінку в `assets/admin.js`:
  - Prom test/import/export/status actions
  - supplier feed row add/remove
  - URL toggle
  - copy buttons
  - channel toggle / channel generation
  - brand mode switch
  - category exclusion cascade
  - attribute filter toggles
  - advanced custom rules builder
  - category accordion tree logic
- Підтверджено, що в `class-admin-settings.php` більше немає inline `<script>` і `onclick`.

# Змінені файли

- `includes/class-admin-settings.php`
- `assets/admin.js`
- `.agents/journal/2026-04-14-1707-ide-gpt5-move-admin-inline-js.md`

# Перевірки

- `php -l includes/class-admin-settings.php`
- `grep -n "<script>\\|onclick=" includes/class-admin-settings.php`
- `node --check assets/admin.js`

# Результат

Адмінка перейшла на чистіший підхід: PHP тепер рендерить markup, а поведінка UI живе в окремому JS-файлі. Це зменшує шум у `class-admin-settings.php` і дає базу для подальшого винесення повторюваних UI-патернів.

# Next steps

- Винести частину inline-style з `Налаштування`, `Prom` і `Постачальники` в `admin.css`.
- Почати ділити `admin.js` на логічні модулі або хоча б на чіткі секції за вкладками.
- Пройтися браузером по `Google`, `Prom` і `Постачальники`, щоб перевірити, що весь перенесений JS працює без regressions.

# Ризики

- Логіка category accordion і advanced rules тепер повністю залежить від `assets/admin.js`, тому після деплою треба обов’язково зробити ручну UI-перевірку в браузері.
