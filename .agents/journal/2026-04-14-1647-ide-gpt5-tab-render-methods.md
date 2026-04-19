# Ціль

Винести великі секції `Google`, `Prom` і `Постачальники` з `render_page()` у helper methods, щоб зменшити монолітність `class-admin-settings.php` і підготувати файл до подальшого UI-refactor.

# Зроблено

- У `render_page()` блок `Google` замінено на виклик `render_google_tab(...)`.
- У `render_page()` блок `Prom` замінено на виклик `render_prom_tab(...)`.
- У `render_page()` блок `Постачальники` замінено на виклик `render_suppliers_tab(...)`.
- Додано три нові приватні render-методи з поточним HTML цих секцій.
- Поведінку JS і save-flow у цьому проході не змінював, щоб не змішувати рефакторинг структури з поведінковими змінами.

# Змінені файли

- `includes/class-admin-settings.php`
- `.agents/journal/2026-04-14-1647-ide-gpt5-tab-render-methods.md`

# Перевірки

- `php -l includes/class-admin-settings.php`
- `grep -n 'render_google_tab|render_prom_tab|render_suppliers_tab' includes/class-admin-settings.php`

# Результат

`render_page()` став коротшим і читабельнішим. Тепер великі вкладки не захаращують верхній рівень методу, і далі можна окремо чистити `Google`, `Prom` та `Постачальники` без постійного ризику зачепити сусідні секції.

# Next steps

- Винести `Огляд` у окремий render-метод.
- Почати розрізати inline JS на дрібніші helper-функції або модулі.
- Поступово замінювати inline-style усередині helper methods на CSS-класи.

# Ризики

- Рендер-методи поки що просто перенесли існуючий HTML. Вони ще не зменшили дублювання всередині самих секцій. Наступний крок це вже не тільки перенесення, а й подальше розбиття на дрібніші компоненти.
