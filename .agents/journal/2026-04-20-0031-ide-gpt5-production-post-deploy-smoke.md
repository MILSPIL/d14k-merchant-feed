# Session Journal Entry

- Date/Time: 2026-04-20 00:31 EEST
- IDE/Agent: Codex
- Model: GPT-5
- Chat ID: N/A

## Goal
- Додати окремий read-only post-deploy smoke для production і оформити production runbook.

## Done
- Додано `scripts/checks/run-production-post-deploy-smoke.sh`.
- Скрипт:
  - копіює на production `import-readiness.php`, `supplier-large-feed.php`, `supplier-background-assertions.php`
  - перевіряє `HTTP 200` для `https://strum.biz.ua`
  - перевіряє `HTTP 200` для `https://strum.biz.ua/wp-json/`
  - підтверджує, що `gmc-feed-for-woocommerce` активний
  - запускає read-only WP smoke через `wp eval-file`
- `scripts/deploy/deploy-production.sh` оновлено:
  - додано `RUN_SMOKE=1`
  - після реального deploy можна одразу прогнати `run-production-post-deploy-smoke.sh`
- Додано `PRODUCTION-RUNBOOK.md`:
  - gating перед production
  - dry-run deploy
  - confirmed production deploy
  - read-only post-deploy smoke
  - корисні ручні команди
- Новий production smoke реально прогнано і підтверджено:
  - `HTTP/2 200` для сайту
  - `HTTP/2 200` для `wp-json`
  - плагін активний
  - `import-readiness` зелений
  - `supplier-background-assertions` зелений
  - `supplier-large-feed` зелений: `categories_total=49`, `offers_total=1`

## Changed Files
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/checks/run-production-post-deploy-smoke.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/deploy/deploy-production.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/PRODUCTION-RUNBOOK.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/journal/2026-04-20-0031-ide-gpt5-production-post-deploy-smoke.md`

## Checks
- `scripts/checks/run-local-checks.sh`
- `scripts/checks/run-production-post-deploy-smoke.sh`

## Result
- У production з'явився повторюваний post-deploy smoke без запису даних.
- Production workflow став симетричнішим до staging: тепер у нього теж є окремий runbook і окремий smoke path.

## Next Steps
- За потреби додати окремий `PRODUCTION_MAX_ITEMS` profile для різних feed-джерел.
- Якщо команда схоче, можна винести спільну частину staging/production smoke у спільний helper script.

## Risks
- Smoke спеціально не запускає background import і не тестує бойовий запис товарів.
