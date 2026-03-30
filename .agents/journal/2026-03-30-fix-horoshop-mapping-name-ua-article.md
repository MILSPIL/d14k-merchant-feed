# Сесія: Фікс маппінгу Horoshop — name_ua та article/SKU

**Дата:** 2026-03-30
**Chat ID / Conversation ID:** b04117ac-842b-48cc-b664-17536ac81f67

## Що було зроблено

### Фікс 1: `name_uk` → `name_ua` / `description_uk` → `description_ua`

**Баг:** Вчора (сесія 6250bc19) ми змінили теги для Horoshop на `name_uk` / `description_uk`, але YML-стандарт (і всі маркетплейси) очікують `name_ua` / `description_ua`.

**Причина:** Хибне припущення що Horoshop використовує свій внутрішній код мови `uk` замість стандартного `ua`.

**Файл:** `includes/class-yml-generator.php`, рядки 361, 374

**Було:**
```php
$name_tag = ($channel === 'horoshop') ? 'name_uk' : 'name_ua';
$desc_tag = ($channel === 'horoshop') ? 'description_uk' : 'description_ua';
```

**Стало:**
```php
$name_tag = 'name_ua';      // стандарт для всіх каналів
$desc_tag = 'description_ua'; // стандарт для всіх каналів
```

### Фікс 2: `article` (SKU) для Horoshop

**Проблема:** CRM ідентифікує товари за SKU. При імпорті в Horoshop, поле "Артикул" автоматично підхоплює `<article>` тег, але не `<vendorCode>`. Без `<article>` Horoshop міг підтягувати WP post ID (offer `id` атрибут) в поле "Артикул".

**Файл:** `includes/class-yml-generator.php`, рядки 345–357

**Рішення:** Для Horoshop канала генеруємо ОБИДВА теги:
- `<article>SKU</article>` — для авто-маппінгу поля "Артикул" в Horoshop → CRM
- `<vendorCode>SKU</vendorCode>` — для сумісності зі специфікацією

Prom: тільки `<vendorCode>`. Rozetka: тільки `<article>`.

## Верифікація (сервер filler.com.ua)

Результати після перегенерації фіду (13:23–13:30):

| Метрика | Значення | Статус |
|---------|----------|--------|
| `<name_ua>` теги | 131 | ✅ |
| `<name_uk>` теги | 0 | ✅ |
| `<article>` (SKU) | 132 | ✅ |
| `<vendorCode>` (SKU) | 132 | ✅ |
| Товарів у фіді | 134 | ✅ |

Приклад offer:
```xml
<offer id="27119" available="true">
  <vendorCode>19428371</vendorCode>
  <article>19428371</article>
  <name>Мезококтейль Vitaran i II ...</name>
  <name_ua>Мезококтейль Vitaran i II ...</name_ua>
</offer>
```

## Наступні кроки

- Протестувати імпорт оновленого фіду в Horoshop — перевірити що "Артикул" = WooCommerce SKU
- Перевірити маппінг полів в Horoshop при імпорті
- Вирішити питання з категорією "Renew cosmetics" (22 товари без аналогів в Horoshop)
- Після підтвердження Horoshop → деплой на `14karat.biz.ua`
