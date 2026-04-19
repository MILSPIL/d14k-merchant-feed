# Session Journal Entry

- Date/Time: 2026-04-20 00:54 EEST
- IDE/Agent: Codex
- Model: GPT-5
- Chat ID: N/A

## Goal
- Винести site-specific параметри deploy і smoke в окремий env-profile layer.

## Done
- Додано:
  - `scripts/env/load-profile.sh`
  - `scripts/env/profiles/strum-staging.sh`
  - `scripts/env/profiles/strum-production.sh`
  - `scripts/env/README.md`
- У profile layer зафіксовано:
  - `D14K_REMOTE_SSH_ALIAS`
  - `D14K_REMOTE_SITE_URL`
  - `D14K_REMOTE_WP_PATH`
  - `D14K_REMOTE_PLUGIN_PATH`
  - `D14K_REMOTE_FEED_URL`
  - `D14K_REMOTE_MAX_ITEMS`
  - `D14K_DEPLOY_CONFIRM_VAR`
  - `D14K_DEPLOY_CONFIRM_VALUE`
- Оновлено wrappers:
  - `scripts/checks/run-staging-smoke.sh`
  - `scripts/checks/run-production-post-deploy-smoke.sh`
  - `scripts/deploy/deploy-staging.sh`
  - `scripts/deploy/deploy-production.sh`
- Тепер wrappers:
  - спершу вантажать profile
  - потім дозволяють override через env
  - і лише після цього запускають helper-рівень
- `STAGING-RUNBOOK.md`, `PRODUCTION-RUNBOOK.md` і `.agents/config.md` оновлено під нову модель.
- Реально перевірено:
  - `scripts/checks/run-local-checks.sh`
  - `STAGING_PROFILE=strum-staging scripts/checks/run-staging-smoke.sh`
  - `PRODUCTION_PROFILE=strum-production scripts/checks/run-production-post-deploy-smoke.sh`
  - `STAGING_PROFILE=strum-staging DRY_RUN=1 RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-staging.sh`
  - `PRODUCTION_PROFILE=strum-production RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-production.sh` → expected refusal
  - `PRODUCTION_PROFILE=strum-production DRY_RUN=1 RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-production.sh`

## Changed Files
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/load-profile.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/profiles/strum-staging.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/profiles/strum-production.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/README.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/checks/run-staging-smoke.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/checks/run-production-post-deploy-smoke.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/deploy/deploy-staging.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/deploy/deploy-production.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/STAGING-RUNBOOK.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/PRODUCTION-RUNBOOK.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/config.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/journal/2026-04-20-0054-ide-gpt5-env-profiles-config-layer.md`

## Checks
- `scripts/checks/run-local-checks.sh`
- `STAGING_PROFILE=strum-staging scripts/checks/run-staging-smoke.sh`
- `PRODUCTION_PROFILE=strum-production scripts/checks/run-production-post-deploy-smoke.sh`
- `STAGING_PROFILE=strum-staging DRY_RUN=1 RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-staging.sh`
- `PRODUCTION_PROFILE=strum-production RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-production.sh` → expected refusal
- `PRODUCTION_PROFILE=strum-production DRY_RUN=1 RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-production.sh`

## Result
- Site-specific параметри більше не розкидані по wrapper-скриптах.
- У репозиторії з'явився окремий profile layer, готовий до розширення на інші сайти.

## Next Steps
- Додати профілі для `filler`, `14karat`, `beautyfill`.
- За потреби зібрати поверх цього ще й unified `deploy-by-profile` або `smoke-by-profile` entrypoints.

## Risks
- Поки що новий profile layer покриває тільки `strum` staging і production.
