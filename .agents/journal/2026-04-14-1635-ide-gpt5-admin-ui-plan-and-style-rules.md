## date and time

- `2026-04-14 16:35 Europe/Kiev`

## IDE / agent

- `Codex desktop`

## model

- `gpt-5`

## task goal

- Підготувати план рефакторингу адмінки плагіна.
- Зафіксувати правила для стилів і UI, щоб майбутні розширення не ламали дизайн-систему.

## what was done

- Проаналізовано поточну структуру вкладок у `includes/class-admin-settings.php`.
- Проаналізовано дизайн-токени і наявну систему стилів у `assets/admin.css`.
- Зафіксовано головні джерела хаосу:
  - змішування каналів у `Prom.ua Sync`
  - top-level вкладки для неканальних секцій
  - емодзі в UI
  - inline-style у PHP
  - локальні layout-рішення без reusable-компонентів
- Додано окремий документ з цільовою архітектурою адмінки:
  - `.agents/references/admin-ui-system-plan.md`
- Додано окремий style guide і чеклист для майбутніх UI-змін:
  - `.agents/references/admin-ui-style-rules.md`

## changed files

- `.agents/references/admin-ui-system-plan.md`
- `.agents/references/admin-ui-style-rules.md`
- `.agents/journal/2026-04-14-1635-ide-gpt5-admin-ui-plan-and-style-rules.md`

## commands / checks / tests

- `sed -n '1,260p' includes/class-admin-settings.php`
- `sed -n '240,560p' includes/class-admin-settings.php`
- `sed -n '1040,1405p' includes/class-admin-settings.php`
- `sed -n '1,260p' assets/admin.css`
- `grep -n "emoji\\|🟢\\|🔴\\|📤\\|📥\\|✅\\|⚠" includes/class-admin-settings.php assets/admin.css`

## result

- Є зафіксований план, як розносити адмінку по вкладках каналів.
- Є зафіксовані правила, як писати UI і стилі далі.
- Наступні правки можна робити вже не "по відчуттю", а по документованій системі.

## next steps

1. Почати рефакторинг top-level вкладок:
   - додати `Огляд`
   - розділити `Prom` і `Постачальники`
   - перенести `Маппінг` у `Google`
2. Прибрати емодзі з `includes/class-admin-settings.php`.
3. Прибрати inline-style з нових секцій і перевести їх на reusable CSS classes.
4. Винести рендер окремих вкладок у helper methods.

## risks / notes

- Документи поки не підключені до коду автоматично, тому їх треба реально читати перед UI-правками.
- Наступний логічний крок уже кодовий: почати реальний refactor вкладок і компонентів.
