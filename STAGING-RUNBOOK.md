# Staging Runbook

## Призначення

Короткий робочий сценарій для `staging.strum.biz.ua`, щоб зміни в плагіні проходили через staging до production.

## Поточне середовище

- URL: `https://staging.strum.biz.ua`
- SSH alias: `techmash`
- WordPress path: `/home/u731710222/domains/strum.biz.ua/public_html/staging`
- Plugin path: `/home/u731710222/domains/strum.biz.ua/public_html/staging/wp-content/plugins/gmc-feed-for-woocommerce`
- Env profile: `strum-staging`

## Базовий цикл

1. Локально прогнати checks

```bash
cd /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce
./scripts/checks/run-local-checks.sh
```

2. Задеплоїти на staging

```bash
./scripts/deploy/deploy-staging.sh
```

Або через unified entrypoint:

```bash
D14K_ENV_PROFILE=strum-staging ./scripts/deploy/deploy-by-profile.sh
```

Для безпечної репетиції без вивантаження файлів:

```bash
DRY_RUN=1 RUN_LOCAL_CHECKS=0 ./scripts/deploy/deploy-staging.sh
```

```bash
D14K_ENV_PROFILE=strum-staging DRY_RUN=1 RUN_LOCAL_CHECKS=0 ./scripts/deploy/deploy-by-profile.sh
```

Технічно `deploy-staging.sh` тепер є thin wrapper над спільним deploy helper:
- `scripts/deploy/deploy-by-profile.sh`
- `scripts/deploy/common.sh`
- site-specific параметри він бере з env profile:
  - `scripts/env/profiles/strum-staging.sh`

3. Прогнати безпечний smoke suite

```bash
./scripts/checks/run-staging-smoke.sh
```

Або через unified entrypoint:

```bash
D14K_ENV_PROFILE=strum-staging ./scripts/checks/run-smoke-by-profile.sh
```

4. Якщо треба перевірити штатний background flow одного supplier feed

```bash
RUN_BACKGROUND=1 ./scripts/checks/run-staging-smoke.sh
```

Якщо на staging уже виконується живий background import, helper покаже поточний state і м'яко пропустить тестовий background run.
Щоб у такій ситуації отримати саме failure, а не skip:

```bash
RUN_BACKGROUND=1 FAIL_ON_BUSY_BACKGROUND=1 ./scripts/checks/run-staging-smoke.sh
```

За замовчуванням smoke suite не запускає background import. Він робить:
- `import-readiness`
- assertion checks для supplier background scope
- `supplier-large-feed` parse smoke

Технічно `run-staging-smoke.sh` тепер є thin wrapper над спільним helper:
- `scripts/checks/run-smoke-by-profile.sh`
- `scripts/checks/run-remote-smoke.sh`
- site-specific параметри він бере з env profile:
  - `scripts/env/profiles/strum-staging.sh`

## Корисні команди

### Поточний supplier state

```bash
ssh techmash "wp --path='/home/u731710222/domains/strum.biz.ua/public_html/staging' option get d14k_supplier_background_import_state --format=json"
```

### Один background test-run вручну

```bash
scp ./scripts/smoke/background-single-feed.php techmash:/tmp/background-single-feed.php
ssh techmash "D14K_SUPPLIER_FEED_URL='https://eherp.biz.ua/export/get/yml_link?currency=UAH&lang=ua' D14K_SUPPLIER_MAX_ITEMS='1' wp --path='/home/u731710222/domains/strum.biz.ua/public_html/staging' eval-file /tmp/background-single-feed.php"
```

### Read-only parser smoke

```bash
scp ./scripts/smoke/supplier-large-feed.php techmash:/tmp/supplier-large-feed.php
ssh techmash "D14K_SUPPLIER_FEED_URL='https://eherp.biz.ua/export/get/yml_link?currency=UAH&lang=ua' D14K_SUPPLIER_MAX_ITEMS='1' wp --path='/home/u731710222/domains/strum.biz.ua/public_html/staging' eval-file /tmp/supplier-large-feed.php"
```

### Прямий запуск спільного helper

```bash
SMOKE_TITLE="Staging smoke" REMOTE_SSH_ALIAS=techmash REMOTE_WP_PATH='/home/u731710222/domains/strum.biz.ua/public_html/staging' REMOTE_FEED_URL='https://eherp.biz.ua/export/get/yml_link?currency=UAH&lang=ua' ./scripts/checks/run-remote-smoke.sh
```

### Явний запуск через profile

```bash
STAGING_PROFILE=strum-staging ./scripts/checks/run-staging-smoke.sh
```

## Правило переходу в production

Переходити в production тільки після цього набору:
- `run-local-checks.sh` зелений
- `run-staging-smoke.sh` зелений
- якщо зміна зачіпає supplier background flow, хоча б один `RUN_BACKGROUND=1` test-run на staging без errors

## Production deploy

Production deploy тепер вимагає явного env-підтвердження:

```bash
D14K_DEPLOY_PRODUCTION_CONFIRM=DEPLOY ./scripts/deploy/deploy-production.sh
```

Для dry-run без реального вивантаження:

```bash
DRY_RUN=1 RUN_LOCAL_CHECKS=0 ./scripts/deploy/deploy-production.sh
```
