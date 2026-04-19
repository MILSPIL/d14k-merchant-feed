# Session Journal Entry

- Date/Time: 2026-04-20 02:28 EEST
- IDE/Agent: Codex
- Model: GPT-5
- Chat ID: N/A

## Goal
- Зафіксувати уточнення до release policy: фінальна версія плагіна після дозрівання нового функціоналу має ставитись на всі сайти з плагіном.

## Done
- Оновлено `PROJECT-INFO.md`:
  - спільна фінальна версія після Strum-циклу розходиться на всі сайти з плагіном
  - різниться не кодова база, а фактично використані можливості й налаштування
- Оновлено `.agents/config.md`:
  - заборонено мислити окремими довгоживучими версіями під різних клієнтів
  - закріплено, що фінальна спільна версія одна
- Оновлено `scripts/env/README.md`:
  - після дозрівання функціоналу він іде в спільну фінальну версію для всіх сайтів

## Changed Files
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/PROJECT-INFO.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/config.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/README.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/journal/2026-04-20-0228-ide-gpt5-shared-final-version-policy.md`

## Checks
- `rg -n "всі сайти|спільна фінальна версія|налаштування" /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/PROJECT-INFO.md /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/config.md /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/README.md`

## Result
- Моя політика тепер не розводить різні клієнтські версії плагіна. Фінальна версія одна, а використання блоків відрізняється по конфігурації.

## Next Steps
- Якщо знадобиться, окремо оформити короткий GitHub release workflow для цієї моделі.

## Risks
- У старих журналах ще можуть лишатися формулювання, ніби функціонал після Strum-циклу йде тільки на частину сайтів.
