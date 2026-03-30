# 2026-03-31 — Фіналізація UI Horoshop + деплой GitHub

**Chat ID:** 8c54e556-f874-438c-a5ec-9c103678bf62

## Що зроблено

### UI адмін-панелі

- **Horoshop** — єдиний активний маркетплейс-канал
- **Prom.ua** — показано як "Coming Soon → Тестування"
- **Rozetka** — показано як "Coming Soon → За розкладом"
- Додано **warning-блок** про необхідність ручного створення категорій у Horoshop
- Замінено emoji на **SVG іконки** (Lucide) в усій секції маркетплейсів
- Додано CSS: `.d14k-notice-box`, `.d14k-coming-soon-*`, `.d14k-url-toggle`

### Деплой

- Commit: `85c5936` → `main` → GitHub (`MILSPIL/d14k-merchant-feed`)

### Документація

- Створено `.agents/references/horoshop-limitations.md` — повний довідник обмежень

## Файли змінені

- `includes/class-admin-settings.php` — marketplace section rewrite
- `assets/admin.css` — нові стилі компонентів
- `.agents/references/horoshop-limitations.md` — [NEW]

## Що далі

- Очікуємо відповідь підтримки Horoshop
- Після відповіді: або інтегруємо API, або документуємо manual workflow
- Тестування Prom.ua YML фіду
