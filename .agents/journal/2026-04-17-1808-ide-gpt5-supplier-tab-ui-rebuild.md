# Ціль

Повністю перебудувати вкладку `Постачальники` в адмінці плагіна так, щоб вона швидше сканувалась, мала чистішу ієрархію і відокремлювала налаштування, live status і дії, без змін у робочій backend-логіці supplier imports.

# Що зроблено

- Перевірив поточну реалізацію supplier tab у:
  - `includes/class-admin-settings.php`
  - `assets/admin.js`
  - `assets/admin.css`
- Перевірив live production UI через `chrome-devtools` перед змінами і підтвердив, що головна проблема саме в структурі, а не в самій механіці.
- У `includes/class-admin-settings.php` перезібрав supplier tab:
  - зверху додав overview з intro і metric cards
  - виніс auto-update / queue / global actions в окрему operational zone
  - замінив table-driven верх на більш керований block layout
  - supplier rows перебудував у card-based структуру з:
    - identity header
    - status badges
    - summary lines по останньому імпорту / clean-up
    - окремими panel blocks для source / updates / schedule
    - cleaner footer з row-level actions
  - bottom summaries перетворив на окремий history grid
- У `assets/admin.js`:
  - переписав `createSupplierFeedRow()` під новий markup
  - додав `getSupplierHostFromUrl()`
  - додав `buildSupplierScheduleSummary()`
  - додав `syncSupplierRowPreview()` для live оновлення title / host / enabled / schedule badge
  - додав `createSupplierEmptyState()` і `ensureSupplierEmptyState()`
  - адаптував bindings під нові wrappers і focus-targets
- У `assets/admin.css`:
  - додав нові supplier-tab layout blocks
  - додав metric cards, guide cards, workbench surfaces
  - додав нові badges і cleaner row styles
  - додав responsive rules для tablet і narrow width
  - окремо стилізував history cards і empty state
- Задеплоїв зміни на production.
- Після помилкового першого deploy у root плагіна:
  - повторно задеплоїв у правильні `includes/` і `assets/`
  - прибрав зайві root-level копії

# Змінені файли

- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.css`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/journal/2026-04-17-1808-ide-gpt5-supplier-tab-ui-rebuild.md`

# Перевірки

- `php -l /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `node -e "const fs=require('fs'); new Function(fs.readFileSync('/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js','utf8')); console.log('admin.js syntax OK')"`
- Production:
  - `php -l /home/u731710222/domains/strum.biz.ua/public_html/wp-content/plugins/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
  - `wp cache flush`
- Browser:
  - нова supplier-tab вкладка в `chrome-devtools`
  - full-page screenshot desktop
  - full-page screenshot tablet / narrow width
  - console без помилок і попереджень

# Результат

- Supplier tab тепер читається як керований workspace, а не як довга змішана форма.
- Глобальні дії відокремлені від редагування конкретних feed.
- Кожен supplier row має сильний header-summary і зрозуміліший порядок:
  - хто це
  - який статус
  - що востаннє відбулося
  - де редагувати source
  - де редагувати update rules
  - де редагувати schedule
  - де запускати / аналізувати / зберігати
- Live production підтвердив, що новий UI застосований і на desktop, і на вузькій ширині.

# Next Steps

- За потреби додати ще один polish-pass для:
  - компактнішого списку історії
  - окремого visible queue order
  - ready-made preset для `price + stock only`

# Ризики

- У локальному worktree плагіна вже було багато незакомічених змін, тому під час наступного diff треба дивитися конкретно по трьох UI-файлах.
- Перший deploy пішов у root плагіна, але в межах цієї ж сесії помилку виправлено і зайві копії файлів видалено.
