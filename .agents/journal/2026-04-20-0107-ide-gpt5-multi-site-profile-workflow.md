# Session Journal Entry

- Date/Time: 2026-04-20 01:07 EEST
- IDE/Agent: Codex
- Model: GPT-5
- Chat ID: N/A

## Goal
- Добудувати multi-site profile-driven workflow для deploy і smoke, прибрати старий ручний розкид у docs та додати автоматичний guardrail перед комітом і в CI.

## Done
- Додано нові env profiles:
  - `scripts/env/profiles/filler-production.sh`
  - `scripts/env/profiles/14karat-production.sh`
  - `scripts/env/profiles/beautyfill-staging.sh`
- Додано unified entrypoints:
  - `scripts/checks/run-smoke-by-profile.sh`
  - `scripts/deploy/deploy-by-profile.sh`
- Додано `scripts/checks/run-marketplace-feed-smoke.sh` для marketplace-site профілів.
- Helper-рівень доповнено:
  - `REMOTE_PLUGIN_SLUG`
  - `REMOTE_WP_CLI_ARGS`
  - profile-specific `cache flush`
  - profile-specific `post-deploy command`
  - profile-specific `feed format`
- `scripts/checks/run-staging-smoke.sh` і `scripts/checks/run-production-post-deploy-smoke.sh` тепер йдуть через `run-smoke-by-profile.sh`.
- `scripts/deploy/deploy-staging.sh` і `scripts/deploy/deploy-production.sh` тепер йдуть через `deploy-by-profile.sh`.
- Для `filler-production` зафіксовано реальний production behavior:
  - feed endpoint працює
  - формат feed = `csv`
  - WP-CLI вимагає `--skip-plugins=salesdrive`
  - Cloudflare purge вбудовано в profile-specific post-deploy command
- Додано локальний git hook і installer:
  - `.githooks/pre-commit`
  - `scripts/checks/install-git-hooks.sh`
- Додано GitHub Actions workflow:
  - `.github/workflows/local-checks.yml`
- Docs переведено на profile-driven модель:
  - `scripts/env/README.md`
  - `.agents/config.md`
  - `.agents/workflows/gmc-feed-for-woocommerce.md`
  - `DEPLOY-BRIEFING.md`
  - `STAGING-RUNBOOK.md`
  - `PRODUCTION-RUNBOOK.md`

## Changed Files
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.githooks/pre-commit`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.github/workflows/local-checks.yml`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/config.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/workflows/gmc-feed-for-woocommerce.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/DEPLOY-BRIEFING.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/STAGING-RUNBOOK.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/PRODUCTION-RUNBOOK.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/checks/install-git-hooks.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/checks/run-marketplace-feed-smoke.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/checks/run-remote-smoke.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/checks/run-smoke-by-profile.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/checks/run-staging-smoke.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/checks/run-production-post-deploy-smoke.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/deploy/common.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/deploy/deploy-by-profile.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/deploy/deploy-staging.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/deploy/deploy-production.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/README.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/load-profile.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/profiles/strum-staging.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/profiles/strum-production.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/profiles/filler-production.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/profiles/14karat-production.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/profiles/beautyfill-staging.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/journal/2026-04-20-0107-ide-gpt5-multi-site-profile-workflow.md`

## Checks
- `scripts/checks/install-git-hooks.sh`
- `git config --get core.hooksPath` → `.githooks`
- `scripts/checks/run-local-checks.sh`
- `D14K_ENV_PROFILE=strum-staging scripts/checks/run-smoke-by-profile.sh`
- `D14K_ENV_PROFILE=strum-production scripts/checks/run-smoke-by-profile.sh`
- `D14K_ENV_PROFILE=filler-production scripts/checks/run-smoke-by-profile.sh`
- `D14K_ENV_PROFILE=14karat-production scripts/checks/run-smoke-by-profile.sh`
- `D14K_ENV_PROFILE=beautyfill-staging scripts/checks/run-smoke-by-profile.sh`
- `D14K_ENV_PROFILE=filler-production DRY_RUN=1 RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-by-profile.sh`
- `D14K_ENV_PROFILE=14karat-production DRY_RUN=1 RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-by-profile.sh`
- `D14K_ENV_PROFILE=beautyfill-staging DRY_RUN=1 RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-by-profile.sh`
- `D14K_ENV_PROFILE=filler-production RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-by-profile.sh` → expected refusal
- `D14K_ENV_PROFILE=14karat-production RUN_LOCAL_CHECKS=0 scripts/deploy/deploy-by-profile.sh` → expected refusal

## Result
- Репозиторій перейшов від `strum`-центричного workflow до ширшої multi-site profile-driven моделі.
- `strum` staging / production і `filler-production` мають робочий profile-driven smoke.
- `filler-production` підтвердив реальний `csv` feed замість старого припущення про `yml`.

## Next Steps
- Для `14karat-production` треба окремо розібратись із `403` на public feed URL.
- Для `beautyfill-staging` треба окремо розібратись, чому feed URL повертає home page.
- Якщо команда захоче, можна додати ще пакетний `smoke-matrix` або `deploy-matrix` wrapper поверх profile layer.

## Risks
- `14karat-production` smoke поки не зелений через публічний `403`.
- `beautyfill-staging` smoke поки не зелений через відсутність живого feed endpoint.
