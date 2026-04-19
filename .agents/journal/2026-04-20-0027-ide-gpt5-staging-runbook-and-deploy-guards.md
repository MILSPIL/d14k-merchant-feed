# Session Journal Entry

- Date/Time: 2026-04-20 00:27 EEST
- IDE/Agent: Codex
- Model: GPT-5
- Chat ID: N/A

## Goal
- Додати мінімально дорослий safety layer навколо staging workflow, deploy scripts і markdown secret hygiene.

## Done
- Додано й підтверджено локальний guardrail-набір:
  - `scripts/checks/run-local-checks.sh`
  - `scripts/checks/scan-markdown-secrets.py`
  - `scripts/checks/run-staging-smoke.sh`
  - `scripts/tests/supplier-background-assertions.php`
- Під час прогону знайдено старий Cloudflare token у markdown-файлах.
- Token прибрано з:
  - `.agents/journal/2026-03-31-cloudflare-cache-purge-api.md`
  - `.agents/workflows/gmc-feed-for-woocommerce.md`
- Замість raw secret зафіксовано Keychain usage:
  - `codex/gmc-feed/filler-cloudflare-token`
- `scripts/deploy/deploy-staging.sh` посилено:
  - `RUN_LOCAL_CHECKS=1` за замовчуванням
  - `DRY_RUN=1` для rehearsal без змін на сервері
  - `RUN_SMOKE=1` можна комбінувати після реального staging deploy
- `scripts/deploy/deploy-production.sh` посилено:
  - `RUN_LOCAL_CHECKS=1` за замовчуванням
  - без `D14K_DEPLOY_PRODUCTION_CONFIRM=DEPLOY` реальний deploy блокується
  - `DRY_RUN=1` дає safe rehearsal
  - після реального deploy є cache flush і перевірка активності плагіна
- `STAGING-RUNBOOK.md` оновлено під новий процес:
  - dry-run staging
  - обов'язковий gating перед production
  - production deploy із confirm env

## Changed Files
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/journal/2026-03-31-cloudflare-cache-purge-api.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/workflows/gmc-feed-for-woocommerce.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/STAGING-RUNBOOK.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/checks/run-local-checks.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/checks/run-staging-smoke.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/checks/scan-markdown-secrets.py`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/deploy/deploy-staging.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/deploy/deploy-production.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/smoke/import-readiness.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/smoke/supplier-large-feed.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/smoke/background-single-feed.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/tests/supplier-background-assertions.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/journal/2026-04-20-0027-ide-gpt5-staging-runbook-and-deploy-guards.md`

## Checks
- `scripts/checks/run-local-checks.sh`
- `RUN_BACKGROUND=1 scripts/checks/run-staging-smoke.sh`
- `DRY_RUN=1 RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-staging.sh`
- `RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-production.sh` → expected refusal
- `DRY_RUN=1 RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-production.sh`

## Result
- У репозиторії з'явився повторюваний і перевірений staging path.
- Production deploy тепер має явний safety gate.
- Markdown secrets більше не повертаються непомітно в основні docs і workflow notes.

## Next Steps
- Додати read-only production smoke script після deploy.
- За потреби зібрати окремий `PRODUCTION-RUNBOOK.md`.
- Якщо команда захоче, підв'язати ці checks до git hook або CI.

## Risks
- Репозиторій вже містив багато старих локальних змін і untracked файлів, я їх не чіпав.
- Secret scan зараз покриває markdown, але не всі інші типи файлів.
