# Ціль

Безпечно розгорнути поточний refactor адмінки на `strum.biz.ua` з можливістю швидкого відкату.

# Зроблено

- Перед деплоєм створено локальний backup production-плагіна:
  - `releases/strum-backups/gmc-feed-for-woocommerce-strum-20260414-1712-predeploy.tar.gz`
- Підтверджено production-шлях плагіна:
  - `/home/u731710222/domains/strum.biz.ua/public_html/wp-content/plugins/gmc-feed-for-woocommerce`
- Задеплоєно на `strum.biz.ua` тільки потрібні файли:
  - `assets/admin.css`
  - `assets/admin.js`
  - `d14k-merchant-feed.php`
  - `includes/class-admin-settings.php`
  - `includes/class-cron-manager.php`
  - `includes/class-feed-generator.php`
- Очищено WordPress cache на production через `wp cache flush`.
- Через браузер перевірено, що на проді вже видно нову top-level навігацію:
  - `Огляд`
  - `Google`
  - `Налаштування`
  - `Prom`
  - `Постачальники`
  - `Rozetka`
  - `Facebook`
  - `Довідка`
- Додатково відкрито вкладки `Prom`, `Постачальники` і `Google` після деплою.

# Змінені файли

- `.agents/journal/2026-04-14-1713-ide-gpt5-strum-backup-and-deploy.md`

# Перевірки

- `ssh techmash "test -d .../wp-content/plugins/gmc-feed-for-woocommerce"`
- `ssh techmash "grep -n 'Version:\\|D14K_FEED_VERSION' .../d14k-merchant-feed.php"`
- `rsync -avz --relative assets/admin.css assets/admin.js d14k-merchant-feed.php includes/class-admin-settings.php includes/class-cron-manager.php includes/class-feed-generator.php techmash:.../wp-content/plugins/gmc-feed-for-woocommerce/`
- `ssh techmash "wp --path=/home/u731710222/domains/strum.biz.ua/public_html cache flush"`
- browser reload + snapshots у `chrome-devtools`

# Результат

Оновлена збірка вже на `strum.biz.ua`, backup production-стану створений локально, нова структура вкладок підтверджена в браузері.

# Next steps

- Пройти ручну UX-перевірку дрібних дій усередині `Google`, `Prom` і `Постачальники`
- Продовжити виносити inline-style з `Prom`, `Постачальники` та validation views
- За потреби підготувати швидкий rollback з backup-архіву

# Ризики

- Backup створений як архів у локальному `releases/strum-backups`, а не як автоматичний rollback-скрипт, тому відкат треба буде робити окремою командою.
