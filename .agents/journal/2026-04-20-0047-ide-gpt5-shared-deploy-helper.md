# Session Journal Entry

- Date/Time: 2026-04-20 00:47 EEST
- IDE/Agent: Codex
- Model: GPT-5
- Chat ID: N/A

## Goal
- Прибрати дублювання між staging deploy і production deploy та звести preflight і post-deploy verification до одного спільного helper.

## Done
- Додано `scripts/deploy/common.sh`.
- У helper винесено функції:
  - `deploy_print_header`
  - `deploy_require_confirmation`
  - `deploy_run_local_checks`
  - `deploy_rsync_plugin`
  - `deploy_post_checks`
- `scripts/deploy/deploy-staging.sh` перетворено на thin wrapper над `common.sh`.
- `scripts/deploy/deploy-production.sh` перетворено на thin wrapper над `common.sh`.
- Production confirm gate лишився на місці, але тепер живе в спільному helper-рівні.
- Post-deploy verification для обох deploy wrappers тепер іде через одну функцію:
  - `wp cache flush`
  - перевірка активності `gmc-feed-for-woocommerce`
  - optional smoke script
- Оновлено:
  - `STAGING-RUNBOOK.md`
  - `PRODUCTION-RUNBOOK.md`
- Перевірено:
  - `scripts/checks/run-local-checks.sh`
  - `DRY_RUN=1 RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-staging.sh`
  - `RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-production.sh` → expected refusal
  - `DRY_RUN=1 RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-production.sh`

## Changed Files
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/deploy/common.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/deploy/deploy-staging.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/deploy/deploy-production.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/STAGING-RUNBOOK.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/PRODUCTION-RUNBOOK.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/journal/2026-04-20-0047-ide-gpt5-shared-deploy-helper.md`

## Checks
- `scripts/checks/run-local-checks.sh`
- `DRY_RUN=1 RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-staging.sh`
- `RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-production.sh` → expected refusal
- `DRY_RUN=1 RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-production.sh`

## Result
- Deploy staging і deploy production тепер тримаються на одному helper, а не на двох окремих скриптах із майже однаковими шматками.
- Мені стало простіше підтримувати deploy-дисципліну без роз'їзду між середовищами.

## Next Steps
- Якщо команда захоче, можна винести site-specific env-профілі в окремі файли або окремий конфіг-процес.

## Risks
- Через stderr/stdout порядок рядків у refusal case може виглядати трохи нерівно, але сам confirm gate працює правильно.
- Реальний deploy із вивантаженням файлів у цій сесії не запускався, перевірено dry-run path.
