# Session Journal Entry

- Date/Time: 2026-04-20 00:36 EEST
- IDE/Agent: Codex
- Model: GPT-5
- Chat ID: N/A

## Goal
- Прибрати дублювання між staging smoke і production smoke та звести їх до одного спільного helper.

## Done
- Додано `scripts/checks/run-remote-smoke.sh`.
- У helper винесено спільну логіку:
  - `scp` smoke scripts на remote
  - optional `HTTP 200` checks
  - перевірка активності `gmc-feed-for-woocommerce`
  - `import-readiness`
  - `supplier-background-assertions`
  - `supplier-large-feed`
  - optional `background-single-feed`
- `scripts/checks/run-staging-smoke.sh` перетворено на thin wrapper з env-параметрами для staging.
- `scripts/checks/run-production-post-deploy-smoke.sh` перетворено на thin wrapper з env-параметрами для production.
- Додано busy-state guard:
  - якщо `RUN_BACKGROUND=1`, а `d14k_supplier_background_import_state` має `status=running`, helper не падає сліпо
  - він показує поточний state
  - за замовчуванням завершується як skip
  - `FAIL_ON_BUSY_BACKGROUND=1` перемикає це в strict failure
- Оновлено:
  - `STAGING-RUNBOOK.md`
  - `PRODUCTION-RUNBOOK.md`
- Реально підтверджено новий шлях:
  - `RUN_BACKGROUND=1 scripts/checks/run-staging-smoke.sh`
  - `scripts/checks/run-production-post-deploy-smoke.sh`
- Після документаційних правок ще раз прогнано `scripts/checks/run-local-checks.sh`.

## Changed Files
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/checks/run-remote-smoke.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/checks/run-staging-smoke.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/checks/run-production-post-deploy-smoke.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/STAGING-RUNBOOK.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/PRODUCTION-RUNBOOK.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/journal/2026-04-20-0036-ide-gpt5-shared-remote-smoke-helper.md`

## Checks
- `scripts/checks/run-local-checks.sh`
- `RUN_BACKGROUND=1 scripts/checks/run-staging-smoke.sh`
- `scripts/checks/run-production-post-deploy-smoke.sh`

## Result
- Smoke-перевірки staging і production тепер тримаються на одному helper, а не на двох майже однакових файлах.
- Поведінка стала передбачуванішою і простішою для підтримки.

## Next Steps
- Якщо команда захоче, можна так само винести deploy preflight або post-deploy verification у спільний helper-рівень.

## Risks
- Busy-state guard робить default-поведінку м'якшою, тому для сценаріїв із жорстким pass/fail треба явно додавати `FAIL_ON_BUSY_BACKGROUND=1`.
