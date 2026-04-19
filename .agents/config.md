# GMC Feed Config

- URL продакшн: Кілька (`diamonds14k.com`, `techmachagro.com.ua`, `filler.com.ua`)
- SSH: `14karat`, `techmashagro`, `filler-hostinger`
- Шлях до WP root: Різні (наприклад `/home/diamond2/public_html`)
- Тема: WoodMart (найчастіше)

## Cloudflare (filler.com.ua)

- **API Token:** винесено з markdown
- **macOS Keychain item:** `codex/gmc-feed/filler-cloudflare-token`
- **Zone ID:** `44d2bc486baf0d5a3789482d80b5a963`
- **Права:** Zone → Cache Purge → Purge (All zones)
- **Назва токена:** Deploy Cache Purge

## Profile-Driven Workflow

- Unified deploy entrypoint: `./scripts/deploy/deploy-by-profile.sh`
- Unified smoke entrypoint: `./scripts/checks/run-smoke-by-profile.sh`
- Git hook installer: `./scripts/checks/install-git-hooks.sh`
- GitHub Actions workflow: `.github/workflows/local-checks.yml`
- Розділяй два режими роботи:
  - стабільна лінія з GitHub для бойових сайтів
  - експериментальна лінія тільки для `strum`
- Env profiles:
  - `strum-staging`
  - `strum-production`
  - `filler-production`
  - `14karat-production`
  - `diamonds14k-staging`
  - `beautyfill-staging`

## Приклади

```bash
D14K_ENV_PROFILE=strum-staging ./scripts/checks/run-smoke-by-profile.sh
D14K_ENV_PROFILE=strum-production D14K_DEPLOY_PRODUCTION_CONFIRM=DEPLOY ./scripts/deploy/deploy-by-profile.sh
D14K_ENV_PROFILE=filler-production ./scripts/checks/run-smoke-by-profile.sh
D14K_ENV_PROFILE=diamonds14k-staging ./scripts/checks/run-smoke-by-profile.sh
D14K_ENV_PROFILE=14karat-production D14K_DEPLOY_PRODUCTION_CONFIRM=DEPLOY ./scripts/deploy/deploy-by-profile.sh
```

## Strum staging

- URL: `https://staging.strum.biz.ua`
- SSH alias: `techmash`
- WordPress path: `/home/u731710222/domains/strum.biz.ua/public_html/staging`
- Plugin path: `/home/u731710222/domains/strum.biz.ua/public_html/staging/wp-content/plugins/gmc-feed-for-woocommerce`
- Env profile: `strum-staging`
- Strum production profile: `strum-production`
- Роль: головний полігон для supplier feeds і нового Prom import/export.
- Базові команди:
  - `./scripts/deploy/deploy-staging.sh`
  - `./scripts/checks/run-staging-smoke.sh`
  - `RUN_BACKGROUND=1 ./scripts/checks/run-staging-smoke.sh`
  - `./scripts/checks/run-production-post-deploy-smoke.sh`

## Інші профілі

- `filler-production`
  - сайт: `https://filler.com.ua`
  - плагін: `d14k-merchant-feed`
  - smoke: marketplace feed
  - post-deploy: Cloudflare purge через Keychain token
- `14karat-production`
  - сайт: `https://diamonds14k.com`
  - плагін: `gmc-feed-for-woocommerce`
  - smoke: GMC + Prom
  - примітка: supplier feeds для цього сайту не цільові, а старий каталог `d14k-merchant-feed.OLD` лишився як хвіст
- `diamonds14k-staging`
  - сайт: `https://staging.diamonds14k.com`
  - плагін: `gmc-feed-for-woocommerce`
  - smoke: GMC + Prom
  - поточний status: GMC живий, а Prom feed endpoint ще не готовий і зараз віддає `403`
- `beautyfill-staging`
  - сайт: `https://beautyfill.shop`
  - плагін: `d14k-merchant-feed`
  - smoke: marketplace feed
  - поточний status: feed endpoint не підтверджений, smoke зараз виявляє проблему

## GitHub Workflow

- `main` це стабільна загальна версія плагіна.
- Проміжна розробка під `strum` має жити в окремій робочій гілці, а не в `main`.
- Поки новий блок не підтверджений на `strum`, не пушити його в `origin/main`.
- Після підтвердження на `strum` робоча гілка зливається в `main`, і тільки тоді нова спільна версія йде на всі сайти.
- GitHub tag/release створювати тільки з `main`, коли функціонал уже підтверджений.

## Правило розвитку

- Все нове по supplier feeds і новому Prom import/export спершу живе на `strum`.
- Поки функціонал не стабілізований на `strum`, не вважай його частиною спільної бойової версії для інших сайтів.
- Після стабілізації на `strum` новий стан фіксується в GitHub і тільки потім розходиться по всіх сайтах, де встановлено плагін.
- Не розводь окремі довгоживучі версії плагіна під різних клієнтів. Спільна фінальна версія одна, а реально використані блоки можуть відрізнятися по налаштуваннях.
