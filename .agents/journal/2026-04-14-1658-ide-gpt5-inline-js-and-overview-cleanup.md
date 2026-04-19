# Ціль

Почати чистити inline JS і inline-style після винесення вкладок у render-методи.

# Зроблено

- У `Огляд` частину inline-style замінено на CSS-класи:
  - іконка заголовка
  - іконки в кнопках
  - прихований code-блок із URL
  - muted-іконка у `Канали в роботі`
- Переписано основний inline JS у дрібніші helper-функції:
  - `postAdminAction(...)`
  - `setButtonState(...)`
  - `bindSimpleAction(...)`
  - `runPromImportBatches(...)`
  - `bindPromImport()`
  - `bindPromStatusCheck()`
  - `bindSupplierFeedRows()`
- Прибрано inline `onclick` для toggle URL у `Огляд` і замінено його на `data-target` + загальний JS-обробник.

# Змінені файли

- `includes/class-admin-settings.php`
- `assets/admin.css`
- `.agents/journal/2026-04-14-1658-ide-gpt5-inline-js-and-overview-cleanup.md`

# Перевірки

- `php -l includes/class-admin-settings.php`
- `grep -n 'onclick=|d14kToggleCodeTarget' includes/class-admin-settings.php`
- `grep -n 'd14k-icon-inline-title|d14k-code-toggle-target|d14k-button-link' assets/admin.css`

# Результат

JS став структурованішим: прості AJAX-дії більше не дублюють один і той самий шаблон керування кнопкою і статусом. `Огляд` теж став чистішим, бо частина presentation-логіки вже винесена в CSS.

# Next steps

- Прибрати старі inline `onclick` із category accordion у фільтрах.
- Почати виносити inline JS з PHP в окремий admin script.
- Продовжити заміну inline-style в `Огляд`, `Prom` і `Постачальники`.

# Ризики

- У файлі ще лишилися старі inline handler-и в category accordion. Вони не пов’язані з новим refactor, але їх теж треба прибрати, щоб дійти до чистого стилю коду.
