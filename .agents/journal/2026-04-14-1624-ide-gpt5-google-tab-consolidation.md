## date and time

- `2026-04-14 16:24 Europe/Kiev`

## IDE / agent

- `Codex desktop`

## model

- `gpt-5`

## task goal

- Перенести `Маппінг` і `Фільтри` з top-level навігації всередину вкладки `Google`.
- Зменшити кількість top-level вкладок без зламу поточного save-flow.

## what was done

- У `includes/class-admin-settings.php` змінено alias логіку:
  - `mapping` -> `google`
  - `filters` -> `google`
- З top-level масиву вкладок прибрано окремі `Маппінг` і `Фільтри`.
- Усередині вкладки `Google` додано внутрішню навігацію по секціях:
  - `Фіди`
  - `Маппінг`
  - `Фільтри`
- Усередину `Google` перенесено render форм:
  - Google category mapping
  - Custom Labels
  - excluded categories
  - attribute filters
  - advanced rules
- Поточний save-flow лишено сумісним:
  - `d14k_tab=mapping`
  - `d14k_tab=filters`
- Старі окремі блоки `Маппінг` і `Фільтри` залишено в коді як legacy, але вимкнено через `if (false)`, щоб не було дубльованого DOM і конфліктів по ID.
- У `assets/admin.css` додано базові стилі для внутрішньої секційної навігації та нових допоміжних класів Google-вкладки.

## changed files

- `includes/class-admin-settings.php`
- `assets/admin.css`
- `.agents/journal/2026-04-14-1624-ide-gpt5-google-tab-consolidation.md`

## commands / checks / tests

- `php -l includes/class-admin-settings.php`
- `grep -n "'mapping' =>\\|'filters' =>\\|data-tab=\"mapping\"\\|data-tab=\"filters\"\\|Legacy TAB\\|d14k-google-feeds\\|d14k-google-mapping\\|d14k-google-filters" includes/class-admin-settings.php`

## result

- Для користувача `Маппінг` і `Фільтри` тепер живуть у правильному каналі `Google`.
- Top-level навігація стала коротшою і ближчою до затвердженого UI-плану.

## next steps

1. Прибрати legacy-блоки `mapping` і `filters` з файла повністю після ще одного візуального проходу.
2. Винести `Google`-секції в helper methods.
3. Прибрати решту inline-style з `Google` і `Налаштування`.
4. Замість `alert()` у `Prom` зробити вбудований status block.

## risks / notes

- Legacy-блоки ще залишаються в коді, хоча вже не рендеряться.
- Після цього кроку бажано зробити візуальну перевірку в браузері, бо вкладка `Google` стала довшою.
