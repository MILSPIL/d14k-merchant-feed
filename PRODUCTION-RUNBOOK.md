# Production Runbook

## Призначення

Короткий бойовий сценарій для `strum.biz.ua`, щоб production deploy проходив через однакові безпечні кроки і не вимагав імпровізації.

## Поточне середовище

- URL: `https://strum.biz.ua`
- SSH alias: `techmash`
- WordPress path: `/home/u731710222/domains/strum.biz.ua/public_html`
- Plugin path: `/home/u731710222/domains/strum.biz.ua/public_html/wp-content/plugins/gmc-feed-for-woocommerce`
- Env profile: `strum-production`

## Гейт перед production

Переходити в production тільки якщо:
- `./scripts/checks/run-local-checks.sh` зелений
- `./scripts/checks/run-staging-smoke.sh` зелений
- якщо зміна зачіпає supplier background flow, був хоча б один `RUN_BACKGROUND=1 ./scripts/checks/run-staging-smoke.sh` без errors

## Dry-run перед вивантаженням

```bash
cd /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce
DRY_RUN=1 RUN_LOCAL_CHECKS=0 ./scripts/deploy/deploy-production.sh
```

```bash
D14K_ENV_PROFILE=strum-production DRY_RUN=1 RUN_LOCAL_CHECKS=0 ./scripts/deploy/deploy-by-profile.sh
```

## Production deploy

Production deploy вимагає явного env-підтвердження:

```bash
D14K_DEPLOY_PRODUCTION_CONFIRM=DEPLOY ./scripts/deploy/deploy-production.sh
```

```bash
D14K_ENV_PROFILE=strum-production D14K_DEPLOY_PRODUCTION_CONFIRM=DEPLOY ./scripts/deploy/deploy-by-profile.sh
```

Щоб одразу після deploy прогнати read-only smoke:

```bash
D14K_DEPLOY_PRODUCTION_CONFIRM=DEPLOY RUN_SMOKE=1 ./scripts/deploy/deploy-production.sh
```

```bash
D14K_ENV_PROFILE=strum-production D14K_DEPLOY_PRODUCTION_CONFIRM=DEPLOY RUN_SMOKE=1 ./scripts/deploy/deploy-by-profile.sh
```

Технічно `deploy-production.sh` тепер є thin wrapper над спільним deploy helper:
- `scripts/deploy/deploy-by-profile.sh`
- `scripts/deploy/common.sh`
- site-specific параметри він бере з env profile:
  - `scripts/env/profiles/strum-production.sh`

## Read-only post-deploy smoke

Окремий smoke script не змінює дані в WordPress і не запускає background import:

```bash
./scripts/checks/run-production-post-deploy-smoke.sh
```

```bash
D14K_ENV_PROFILE=strum-production ./scripts/checks/run-smoke-by-profile.sh
```

Він перевіряє:
- публічний `HTTP 200` для `https://strum.biz.ua`
- публічний `HTTP 200` для `https://strum.biz.ua/wp-json/`
- що `gmc-feed-for-woocommerce` активний
- `import-readiness`
- assertion checks для supplier background scope
- read-only `supplier-large-feed` parse smoke

Технічно `run-production-post-deploy-smoke.sh` тепер є thin wrapper над спільним helper:
- `scripts/checks/run-smoke-by-profile.sh`
- `scripts/checks/run-remote-smoke.sh`
- site-specific параметри він бере з env profile:
  - `scripts/env/profiles/strum-production.sh`

Якщо helper колись запускається з `RUN_BACKGROUND=1` і на сервері вже є активний import, він за замовчуванням покаже state і завершиться як skip.
Щоб примусово впасти на busy-state:

```bash
FAIL_ON_BUSY_BACKGROUND=1 ./scripts/checks/run-remote-smoke.sh
```

## Корисні команди

### Поточний supplier state

```bash
ssh techmash "wp --path='/home/u731710222/domains/strum.biz.ua/public_html' option get d14k_supplier_background_import_state --format=json"
```

### Read-only parser smoke вручну

```bash
scp ./scripts/smoke/supplier-large-feed.php techmash:/tmp/supplier-large-feed.php
ssh techmash "D14K_SUPPLIER_FEED_URL='https://eherp.biz.ua/export/get/yml_link?currency=UAH&lang=ua' D14K_SUPPLIER_MAX_ITEMS='1' wp --path='/home/u731710222/domains/strum.biz.ua/public_html' eval-file /tmp/supplier-large-feed.php"
```

### Один повний safe smoke script

```bash
./scripts/checks/run-production-post-deploy-smoke.sh
```

### Прямий запуск спільного helper

```bash
SMOKE_TITLE="Production post-deploy smoke" REMOTE_SSH_ALIAS=techmash REMOTE_WP_PATH='/home/u731710222/domains/strum.biz.ua/public_html' REMOTE_SITE_URL='https://strum.biz.ua' REMOTE_FEED_URL='https://eherp.biz.ua/export/get/yml_link?currency=UAH&lang=ua' RUN_HTTP_CHECKS=1 ./scripts/checks/run-remote-smoke.sh
```

### Явний запуск через profile

```bash
PRODUCTION_PROFILE=strum-production ./scripts/checks/run-production-post-deploy-smoke.sh
```

## Після deploy

Після production deploy бажано зафіксувати:
- чи пройшов post-deploy smoke
- чи активний плагін
- чи не змінився `d14k_supplier_background_import_state`
- які саме файли реально деплоїлись
