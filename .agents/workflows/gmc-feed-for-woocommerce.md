---
description: GMC Feed for WooCommerce — робоча сесія
---
- Спілкуйся виключно українською мовою.
- Прочитай `.agents/config.md`
- Прочитай `PROJECT-INFO.md` та останні файли в `.agents/journal/` (історія сесій)
- Повідом статус і запитай "Що робимо сьогодні?"
- Працюй як Командир (маршрутизація з GEMINI.md)
- В кінці кожної сесії обов'язково занотуй результати в новий файл у `.agents/journal/` (формат імені: YYYY-MM-DD-коротко-що-зроблено.md).
- У кожному такому файлі завжди вказуй Chat ID / Conversation ID поточної сесії.

## 📚 Довідникова документація

Коли потрібна інформація по маркетплейсам, фідам або архітектурі плагіна — шукай тут:

| Що | Де |
|---|---|
| **Специфікації Horoshop / Prom / Rozetka** | `.agents/references/marketplace-specs.md` |
| **Порівняння фідів (тест v3.0.0)** | `.agents/references/feed-comparison-2026-03-29.md` |
| **Архітектура v3.0.0** | `.agents/journal/2026-03-29-v3.0.0-marketplace-feeds.md` |
| **Конфіг деплою (SSH, шляхи, CF)** | `.agents/config.md` |
| **Брифінг деплою v3.0.0** | `DEPLOY-BRIEFING.md` (в корені) |

### Коли читати довідники

- **Робота з YML-генератором** → `marketplace-specs.md` (обов'язкові теги, відмінності між платформами)
- **Дебаг фідів / порівняння з Horoshop** → `feed-comparison-2026-03-29.md` (vendor, ціни, структура)
- **Розуміння архітектури** → журнал v3.0.0 (всі класи, методи, platform-specific логіка)
- **Деплой** → `config.md` + `DEPLOY-BRIEFING.md`

## 🚀 Деплой і smoke через profile

Після будь-яких змін у плагіні працюй через profile-driven entrypoints:

```bash
# Filler production smoke
D14K_ENV_PROFILE=filler-production ./scripts/checks/run-smoke-by-profile.sh

# Filler production deploy
D14K_ENV_PROFILE=filler-production D14K_DEPLOY_PRODUCTION_CONFIRM=DEPLOY ./scripts/deploy/deploy-by-profile.sh
```

Cloudflare purge для `filler-production` уже вшитий у post-deploy flow профілю.

// turbo-all
