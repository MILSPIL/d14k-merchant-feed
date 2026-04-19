# 2026-03-31 — Фікс вертикального вирівнювання в адмінці

**Chat ID:** 8c54e556-f874-438c-a5ec-9c103678bf62

## Що зроблено

### 1. Notice-box padding (CSS specificity fix)

- Правило `.d14k-card > div` перебивало `padding: 16px` у `.d14k-notice-box`
- Додано `:not(.d14k-notice-box)` до виключень (desktop + responsive)

### 2. Feeds table — вертикальне вирівнювання

- URL фіду (`<code>`) був вирівняний по верху відносно кнопки «Копіювати»
- Додано `vertical-align: middle` для `code` та `.button` всередині `widefat td`
- Збільшено padding td з `11px 14px` → `14px`

## Файли змінені

- `assets/admin.css` — 2 коміти

## Деплой

- ✅ filler.com.ua
- ✅ 14karat.biz.ua
- ✅ techmashagro.com.ua
- ✅ Git push (main)
- ✅ Cloudflare cache purge

## Коміти

- `d0d737e` — UI: widefat table td padding 14px for vertical centering
- `6e47656` — UI: vertical-align middle for code+button in feeds table
