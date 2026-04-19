# Session Journal Entry

- Date/Time: 2026-04-20 02:15 EEST
- IDE/Agent: Codex
- Model: GPT-5
- Chat ID: N/A

## Goal
- Перебудувати `14karat` profiles під реальний сценарій сайту: Google Merchant Center + Prom.ua, без Horoshop і без supplier feeds.

## Done
- Додано новий smoke helper `scripts/checks/run-gmc-prom-smoke.sh`.
- `scripts/checks/run-smoke-by-profile.sh` навчено новому типу smoke: `gmc_prom`.
- Оновлено `14karat-production`:
  - smoke змінено з marketplace/Horoshop на `gmc_prom`
  - plugin path змінено на `/home/diamond2/public_html/wp-content/plugins/gmc-feed-for-woocommerce/`
  - plugin slug змінено на `gmc-feed-for-woocommerce`
  - Prom check переведено в `optional`, бо endpoint ще не готовий
- Оновлено `diamonds14k-staging`:
  - smoke змінено з marketplace/Horoshop на `gmc_prom`
  - GMC перевіряється по `merchant-feed/uk/` і `merchant-feed/ru/`
  - Prom перевіряється по `marketplace-feed/prom/` в optional-режимі
- Оновлено docs:
  - `.agents/config.md`
  - `PROJECT-INFO.md`
  - `scripts/env/README.md`
  - `DEPLOY-BRIEFING.md`

## Changed Files
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/checks/run-gmc-prom-smoke.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/checks/run-smoke-by-profile.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/profiles/14karat-production.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/profiles/diamonds14k-staging.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/config.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/PROJECT-INFO.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/README.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/DEPLOY-BRIEFING.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/journal/2026-04-20-0215-ide-gpt5-14karat-gmc-prom-profile.md`

## Checks
- `./scripts/checks/run-local-checks.sh`
- `D14K_ENV_PROFILE=14karat-production ./scripts/checks/run-smoke-by-profile.sh`
- `D14K_ENV_PROFILE=diamonds14k-staging ./scripts/checks/run-smoke-by-profile.sh`
- `D14K_ENV_PROFILE=14karat-production DRY_RUN=1 RUN_LOCAL_CHECKS=0 ./scripts/deploy/deploy-by-profile.sh`
- `ssh 14karat "wp --path='/home/diamond2/public_html' plugin list --status=active --field=name | sed -n '1,120p'"`
- `ssh 14karat "ls -1 /home/diamond2/public_html/wp-content/plugins | sed -n '1,120p'"`

## Result
- `14karat` більше не міряється через чужий Horoshop flow.
- Production і staging тепер перевіряються через правильний контур `GMC + Prom`.
- GMC на production і staging зелений.
- Prom endpoint на обох середовищах поки дає `403`, але це тепер оформлено як реальний незавершений стан, а не як хибний фейл через не той тип smoke.
- Додатково виявлено, що на production активний `gmc-feed-for-woocommerce`, а старий каталог `d14k-merchant-feed.OLD` лежить як хвіст.

## Next Steps
- Якщо треба довести `14karat` до повністю зеленого стану, окремо увімкнути і перевірити `marketplace-feed/prom/` на production і staging.
- Після цього можна перевести `D14K_PROM_CHECK_MODE` з `optional` у `required`.
- Окремо вирішити, чи старий каталог `d14k-merchant-feed.OLD` ще потрібен.

## Risks
- `14karat` production і staging усе ще мають історичний слід старої назви плагіна в локальних docs і артефактах.
- Prom на `14karat` ще не готовий, тому повністю зелений smoke для цього сайту поки не досягнуто.
