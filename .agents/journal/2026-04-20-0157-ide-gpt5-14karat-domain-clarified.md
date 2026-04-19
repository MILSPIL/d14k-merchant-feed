# Session Journal Entry

- Date/Time: 2026-04-20 01:57 EEST
- IDE/Agent: Codex
- Model: GPT-5
- Chat ID: N/A

## Goal
- Зафіксувати правильну прив'язку для сайту `14karat`: назва сайту лишається `14karat`, але домен це `diamonds14k.com`.

## Done
- Оновлено `scripts/env/profiles/14karat-production.sh`:
  - `D14K_REMOTE_SITE_URL`
  - `D14K_REMOTE_FEED_URL`
  - `D14K_MARKETPLACE_FEED_URL`
- Оновлено docs:
  - `PROJECT-INFO.md`
  - `DEPLOY-BRIEFING.md`
  - `scripts/env/README.md`

## Changed Files
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/PROJECT-INFO.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/DEPLOY-BRIEFING.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/profiles/14karat-production.sh`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/scripts/env/README.md`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/.agents/journal/2026-04-20-0157-ide-gpt5-14karat-domain-clarified.md`

## Checks
- `rg -n "14karat\\.biz\\.ua|14karat-production|14karat" /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce`

## Result
- Profile `14karat-production` тепер дивиться на `diamonds14k.com`, а не на старий домен.

## Next Steps
- Прогнати `D14K_ENV_PROFILE=14karat-production ./scripts/checks/run-smoke-by-profile.sh` уже проти `diamonds14k.com`.

## Risks
- Фактична публічна перевірка нового домену після цього виправлення ще не запускалась у цій міні-сесії.
