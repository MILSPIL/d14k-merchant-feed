# Специфікації маркетплейсів: Horoshop / Prom.ua / Rozetka

> **Призначення:** Довідник для розробки та підтримки YML-генератора (`class-yml-generator.php`).
> Цей файл — джерело правди при роботі з фідами.

---

## Порівняльна таблиця `<offer>`

| Поле | Horoshop | Prom.ua | Rozetka |
|------|:---:|:---:|:---:|
| **id** (атрибут) | ✅ | ✅ обов'язковий | ✅ обов'язковий |
| **available** (атрибут) | ✅ | ✅ (`true`/`false`/«склад») | ✅ |
| **group_id** (атрибут) | — | ✅ для різновидів | ❌ не в специфікації |
| **selling_type** (атрибут) | — | `"r"` (роздріб) | — |
| `<name>` | ✅ | ✅ обов'язковий | ✅ обов'язковий |
| `<name_ua>` | ✅ | ✅ | ✅ |
| `<description>` | ✅ HTML/CDATA | ✅ HTML/CDATA | ✅ HTML/CDATA |
| `<description_ua>` | ✅ | ✅ | ✅ |
| `<price>` | ✅ | ✅ обов'язковий (якщо oldprice) | ✅ обов'язковий |
| `<oldprice>` | ✅ | ✅ (пріоритет над `price_old`) | — |
| `<price_old>` | — | ✅ (синонім) | ✅ |
| `<price_promo>` / `<promo_price>` | — | — | ✅ обидва варіанти |
| `<currencyId>` | ✅ UAH | ✅ UAH | ✅ UAH/USD/EUR |
| `<categoryId>` | ✅ | ✅ обов'язковий | ✅ обов'язковий |
| `<picture>` | ✅ до 10 | ✅ 1–10 | ✅ 1–15 (до 10 МБ) |
| `<vendor>` (бренд) | ✅ | ✅ | ✅ **обов'язковий** |
| `<vendorCode>` | ✅ | ✅ (пріоритет) | — |
| `<article>` | — | ✅ (синонім) | ✅ |
| `<barcode>` | — | ✅ (синонім) | — |
| `<url>` | ✅ | ✅ | ✅ необов'язковий |
| `<delivery>` | ✅ `true` | ✅ | — |
| `<stock_quantity>` | — | — | ✅ **обов'язковий** |
| `<quantity_in_stock>` | — | ✅ (пріоритет) | — |
| `<param>` | ✅ | ✅ до 100 | ✅ **обов'язковий** (характеристики) |
| `<country>` | — | ✅ | — |
| `<keywords>` / `<keywords_ua>` | — | ✅ | — |
| `<gtin>` / `<mpn>` | — | ✅ | — |
| `<dimensions>` | — | ✅ | — |
| `<state>` | — | — | ✅ (новий/б/в) |

---

## Ключові відмінності між платформами

### Horoshop

- **Не має власної XML-специфікації** — імпортує як єдиний табличний формат
- Працює через маппінг тегів на поля товару
- Фіди генерує під Prom/Rozetka/Google
- Мін. для імпорту: Артикул, Название, Раздел

### Prom.ua

- Повна формальна YML-специфікація
- **group_id** — для різновидів (варіацій)
- **Стара ціна:** 3 синоніми (`<oldprice>` > `<price_old>` > `<old_price>`)
- **Кількість:** `<quantity_in_stock>` > `<stock_quantity>`
- **Артикул:** `<vendorCode>` > `<barcode>` > `<article>`
- `<name_ua>` + `<description_ua>` — обидва обов'язкові для UA-версії
- Ліміт фото: 1–10
- **Формати зображень:** рекомендовано JPEG, PNG (WebP може працювати, але не гарантовано)

### Rozetka

- **stock_quantity** — обов'язковий (ціле число)
- **param** — обов'язковий (характеристики з name/value)
- **vendor** — обов'язковий (бренд)
- Кожен різновид = окремий `<offer>` з унікальним id
- `group_id` НЕ в специфікації (групування за характеристиками)
- `<price_promo>` / `<promo_price>` — обидва валідні
- Ліміт фото: 1–15 (до 10 МБ, https, без кирилиці/пробілів)
- **Формати зображень:** JPEG, PNG, GIF (**WebP НЕ підтримується** офіційно)
- Двомовні параметри: `<value lang="uk">` / `<value lang="ru">`

---

## Мультимовність (YML стандарт)

| Елемент | Базовий тег (RU) | UA-переклад |
|---------|:---:|:---:|
| Назва | `<name>` | `<name_ua>` |
| Опис | `<description>` | `<description_ua>` |
| Ключові слова | `<keywords>` | `<keywords_ua>` |
| Параметр (Rozetka) | `<value lang="ru">` | `<value lang="uk">` |

**Наш підхід:** RU = base language, UA = переклад через WPML `name_ua`/`description_ua`.

---

## Реалізація в `class-yml-generator.php`

### Platform-specific логіка (build_offer)

| Поле | horoshop | prom | rozetka |
|------|----------|------|---------|
| selling_type | — | `"r"` | — |
| group_id | — | parent ID (варіації) | — |
| description | HTML/CDATA | HTML/CDATA | strip_all_tags |
| oldprice тег | `<oldprice>` | `<oldprice>` | `<price_old>` |
| stock | — | `<quantity_in_stock>` | `<stock_quantity>` fallback 1/0 |
| артикул тег | `<vendorCode>` | `<vendorCode>` | `<article>` |
| country | — | ISO→UA назва | — |
| delivery | `<delivery>true` | `<delivery>true` | — |
| keywords | — | product tags | — |
| gtin | — | meta `_gtin` | — |
| max photos | 10 | 10 | 15 |

### Бренд (resolve_brand)

Два режими в налаштуваннях:

1. **custom** (дефолт) — вручну, fallback на `get_bloginfo('name')`
2. **attribute** — з WooCommerce таксономії/атрибуту

**Важливо:** vendor/brand має бути реальним виробником (Celosome, Revolax), не назвою сайту!

---

## Джерела

- [Prom.ua YML спецификація](https://support.prom.ua/hc/uk/articles/360004963538)
- [Rozetka XML прайс-лист](https://sellerhelp.rozetka.com.ua/p177-xml-price-list.html)
- [Rozetka вимоги до XML](https://sellerhelp.rozetka.com.ua/p185-pricelist-requirements.html)
- [Horoshop товарні фіди](https://help.horoshop.ua/ru/articles/2446965)
- [Horoshop імпорт з файлу](https://help.horoshop.ua/ru/articles/1684865)
- [Prom різновиди (group_id)](https://support.prom.ua/hc/uk/articles/360005208718)
