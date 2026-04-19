# Env Profiles

Ця папка тримає site-specific параметри для deploy і smoke, щоб вони не були зашиті прямо в wrapper-скрипти.

## Як це влаштовано

- loader: `scripts/env/load-profile.sh`
- профілі: `scripts/env/profiles/*.sh`

Кожен профіль може задавати:
- `D14K_PROFILE_LABEL`
- `D14K_REMOTE_SSH_ALIAS`
- `D14K_REMOTE_SITE_URL`
- `D14K_REMOTE_WP_PATH`
- `D14K_REMOTE_PLUGIN_PATH`
- `D14K_REMOTE_FEED_URL`
- `D14K_REMOTE_MAX_ITEMS`
- `D14K_DEPLOY_CONFIRM_VAR`
- `D14K_DEPLOY_CONFIRM_VALUE`

## Поточні профілі

- `strum-staging`
- `strum-production`
- `filler-production`
- `14karat-production`
  - сайт `14karat`
  - домен `diamonds14k.com`
  - плагін `gmc-feed-for-woocommerce`
  - smoke `GMC + Prom`
- `diamonds14k-staging`
  - сайт `14karat staging`
  - домен `staging.diamonds14k.com`
  - плагін `gmc-feed-for-woocommerce`
  - smoke `GMC + Prom`
- `beautyfill-staging`

## Як мислити профілями

- `strum-staging` і `strum-production` це моя експериментальна лінія для supplier feeds і нового Prom import/export.
- Інші production-профілі це бойова стабільна лінія, яка має отримувати вже дозрілий функціонал із GitHub.
- Якщо нова можливість ще тестується тільки на `strum`, не переносити її логіку на інші сайти як обов'язкову частину smoke або deploy policy.
- Коли можливість дозріла, фінальна версія плагіна має піти на всі сайти з плагіном, навіть якщо частина блоків там просто не використовується.

## Приклади

```bash
STAGING_PROFILE=strum-staging ./scripts/checks/run-staging-smoke.sh
PRODUCTION_PROFILE=strum-production ./scripts/checks/run-production-post-deploy-smoke.sh
STAGING_PROFILE=strum-staging ./scripts/deploy/deploy-staging.sh
PRODUCTION_PROFILE=strum-production D14K_DEPLOY_PRODUCTION_CONFIRM=DEPLOY ./scripts/deploy/deploy-production.sh
```

## Unified entrypoints

```bash
D14K_ENV_PROFILE=strum-staging ./scripts/checks/run-smoke-by-profile.sh
D14K_ENV_PROFILE=strum-production ./scripts/checks/run-smoke-by-profile.sh
D14K_ENV_PROFILE=filler-production ./scripts/checks/run-smoke-by-profile.sh
D14K_ENV_PROFILE=diamonds14k-staging ./scripts/checks/run-smoke-by-profile.sh
D14K_ENV_PROFILE=14karat-production ./scripts/deploy/deploy-by-profile.sh
```

Для `14karat-production` і `diamonds14k-staging` smoke тепер орієнтується на `merchant-feed/uk/`, `merchant-feed/ru/` і `marketplace-feed/prom/`.
GMC для цієї пари зараз живий, а Prom feed endpoint поки не готовий і перевіряється в optional-режимі.

## Git hook і CI

- Встановити локальний pre-commit hook:

```bash
./scripts/checks/install-git-hooks.sh
```

- Локальний hook запускає `scripts/checks/run-local-checks.sh`
- GitHub Actions workflow: `.github/workflows/local-checks.yml`
