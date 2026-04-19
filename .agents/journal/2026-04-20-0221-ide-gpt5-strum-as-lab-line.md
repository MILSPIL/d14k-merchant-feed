# Session Journal Entry

- Date/Time: 2026-04-20 02:21 EEST
- IDE/Agent: Codex
- Model: GPT-5
- Chat ID: N/A

## Goal
- Зафіксувати правильну модель розвитку плагіна: стабільна лінія для бойових сайтів і окрема експериментальна лінія тільки для `strum`.

## Done
- Оновлено `PROJECT-INFO.md`, щоб:
  - `strum` був позначений як єдиний полігон для supplier feeds і нового Prom import/export
  - модель релізів була явно розділена на стабільну і експериментальну лінії
  - `diamonds14k` staging не плутався з supplier-лабораторією
- Оновлено `.agents/config.md`:
  - додано правило двох режимів роботи
  - `strum` зафіксовано як головний полігон нового функціоналу
  - додано правило, що новий функціонал спершу стабілізується на `strum`, а вже потім повертається в GitHub і розходиться по інших сайтах
- Оновлено `scripts/env/README.md`, щоб profile layer не змішував стабільну лінію з `strum`-лабораторією.
- Оновлено `DEPLOY-BRIEFING.md`, щоб перед деплоєм явно читалось правило: спершу `strum`, потім GitHub, потім інші сайти.

## Changed Files
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/PROJECT-INFO.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/config.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/README.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/DEPLOY-BRIEFING.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/journal/2026-04-20-0221-ide-gpt5-strum-as-lab-line.md`

## Checks
- `rg -n "strum|supplier|Prom|GitHub|stable|експериментальна" /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/PROJECT-INFO.md /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/config.md /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/DEPLOY-BRIEFING.md /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/README.md`

## Result
- Моя пам'ять більше не змішує спільний стабільний реліз плагіна і `strum` як окремий майданчик для нового функціоналу.

## Next Steps
- Коли треба буде, окремо відбити це ж правило в release-процесі GitHub і в naming релізів.
- Після стабілізації Prom import/export і supplier feeds на `strum` зафіксувати нову фінальну спільну версію.

## Risks
- У старих журналах і старих нотатках ще можуть лишатися змішані формулювання про Strum і загальну версію плагіна.
