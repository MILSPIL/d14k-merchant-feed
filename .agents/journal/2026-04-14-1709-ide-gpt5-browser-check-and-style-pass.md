# Ціль

Перевірити адмінку плагіна в браузері після переносу inline JS і прибрати ще один помітний шматок inline-style у вкладці `Налаштування`.

# Зроблено

- Перевірено `strum.biz.ua/wp-admin/admin.php?page=d14k-merchant-feed&tab=prom_sync` через браузер.
- Зафіксовано, що на проді ще відкрита стара збірка UI:
  - старі top-level вкладки `Фіди / Маппінг / Фільтри / Prom.ua Sync`
  - старі емодзі в заголовках `Prom`
  - отже локальний refactor поки не розгорнутий на проді
- У локальному коді прибрано частину inline-style з `Налаштування`:
  - hidden `h1`
  - logo style
  - cron controls row
  - compact description spacing
  - brand fieldset
  - brand mode panels
  - brand select width
- Додано відповідні reusable CSS-класи в `assets/admin.css`.

# Змінені файли

- `includes/class-admin-settings.php`
- `assets/admin.css`
- `.agents/journal/2026-04-14-1709-ide-gpt5-browser-check-and-style-pass.md`

# Перевірки

- `php -l includes/class-admin-settings.php`
- browser snapshot у `chrome-devtools`

# Результат

Є чітке розділення між локальним і production-станом:

- локально refactor уже продовжено і частина inline-style прибрана
- на проді ще стара збірка, тому реальна browser-verification нового UI потребує окремого деплою

# Next steps

- Розгорнути поточну локальну збірку на `strum.biz.ua`
- Після деплою пройтись по вкладках `Google`, `Prom`, `Постачальники`
- Продовжити виносити inline-style з `Prom`, `Постачальники` і validation views

# Ризики

- Без деплою браузерна перевірка показує тільки старий production UI, тому вона не може підтвердити новий refactor.
