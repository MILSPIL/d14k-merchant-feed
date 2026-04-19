# Ціль

Фізично прибрати legacy-блоки `Маппінг` і `Фільтри` з `class-admin-settings.php`, бо після перенесення в `Google` вони лишались у файлі як мертва розмітка.

# Зроблено

- Вирізано весь legacy HTML-блок для старих top-level вкладок `mapping` і `filters`.
- Залишено тільки нову структуру, де `mapping` і `filters` живуть усередині вкладки `Google`.
- Перевірено, що в рендері більше немає `data-tab="mapping"`, `data-tab="filters"` і маркера `Legacy TAB`.

# Змінені файли

- `includes/class-admin-settings.php`
- `.agents/journal/2026-04-14-1633-ide-gpt5-remove-legacy-google-tabs.md`

# Перевірки

- `php -l includes/class-admin-settings.php`
- `grep -n 'data-tab="mapping"|data-tab="filters"|Legacy TAB' includes/class-admin-settings.php`

# Результат

Файл `class-admin-settings.php` став чистішим: у ньому більше немає прихованого дубля старої вкладкової структури. Це зменшує ризик випадкового повернення старого UI, конфліктів по DOM та плутанини під час наступного refactor.

# Next steps

- Винести великі секції `Google` у helper methods, щоб зменшити розмір `render_page()`.
- Почати так само розрізати `Prom` і `Постачальники` на менші render-методи.
- Поступово прибрати inline-style з решти секцій адмінки.

# Ризики

- Save-flow лишається сумісним через `d14k_tab=mapping` і `d14k_tab=filters`, але тепер ці форми існують тільки всередині `Google`. Якщо десь у JS або документації ще є посилання на старі top-level вкладки, їх треба буде теж дочистити.
