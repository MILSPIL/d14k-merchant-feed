# Session Journal Entry

- Date/Time: 2026-04-20 02:05 EEST
- IDE/Agent: Codex
- Model: GPT-5
- Chat ID: N/A

## Goal
- Додати окремий staging profile для `14karat` на домені `staging.diamonds14k.com` і привести docs до реальної карти середовищ.

## Done
- Додано новий env profile `diamonds14k-staging` для:
  - `https://staging.diamonds14k.com`
  - SSH alias `14karat`
  - WP path `/home/diamond2/staging.diamonds14k.com`
  - plugin slug `gmc-feed-for-woocommerce`
  - marketplace smoke на `https://staging.diamonds14k.com/marketplace-feed/horoshop/`
- Оновлено profile-driven docs і карта середовищ:
  - `.agents/config.md`
  - `PROJECT-INFO.md`
  - `scripts/env/README.md`
  - `DEPLOY-BRIEFING.md`
- Зафіксовано фактичний статус staging:
  - сайт відповідає `200`
  - `wp-json` відповідає `200`
  - `gmc-feed-for-woocommerce` активний
  - публічний `marketplace-feed/horoshop/` повертає `403`
  - query endpoint `?d14k_yml_feed=1&d14k_yml_channel=horoshop` відповідає `This feed channel is disabled.`

## Changed Files
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/profiles/diamonds14k-staging.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/config.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/PROJECT-INFO.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/README.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/DEPLOY-BRIEFING.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/journal/2026-04-20-0205-ide-gpt5-diamonds14k-staging-profile.md`

## Checks
- `./scripts/checks/run-local-checks.sh`
- `D14K_ENV_PROFILE=diamonds14k-staging ./scripts/checks/run-smoke-by-profile.sh`
- `D14K_ENV_PROFILE=diamonds14k-staging DRY_RUN=1 RUN_LOCAL_CHECKS=0 ./scripts/deploy/deploy-by-profile.sh`
- `ssh 14karat "test -d /home/diamond2/staging.diamonds14k.com/wp-content/plugins/d14k-merchant-feed && echo HAS_D14K || echo NO_D14K; test -d /home/diamond2/staging.diamonds14k.com/wp-content/plugins/gmc-feed-for-woocommerce && echo HAS_GMC || echo NO_GMC"`

## Result
- У мене тепер є окремий чесний staging profile для `14karat`, і docs більше не приховують, що цей staging зараз живе на `gmc-feed-for-woocommerce`, а не на `d14k-merchant-feed`.

## Next Steps
- Увімкнути feed channel `horoshop` на `staging.diamonds14k.com`, щоб `D14K_ENV_PROFILE=diamonds14k-staging ./scripts/checks/run-smoke-by-profile.sh` став зеленим.
- Після цього прогнати staging smoke ще раз і, якщо треба, dry-run deploy вже з `RUN_SMOKE=1`.

## Risks
- `diamonds14k-staging` smoke поки не зелений, бо feed endpoint реально віддає `403`.
- Production `14karat-production` і staging `diamonds14k-staging` зараз працюють на різних plugin slug, це окреме джерело розсинхрону.
