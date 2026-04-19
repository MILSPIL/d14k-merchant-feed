## date and time

- `2026-04-14 15:05 Europe/Kiev`

## IDE / agent

- `Codex desktop`

## model

- `gpt-5`

## task goal

- Перевірити, чому в Google Merchant Center фід іде з коротким описом замість повного.
- Виправити пріоритет description для GMC.

## what was done

- Перевірено `includes/class-feed-generator.php`.
- Підтверджено, що GMC generator брав:
  - спочатку `short_description`
  - і лише потім `description`
- Змінено пріоритет на:
  - спочатку `full description`
  - якщо порожньо, тоді `short description`
  - якщо обидва порожні, fallback на title
- Файл задеплоєно на `strum.biz.ua`.
- Очищено кеш WordPress на production.

## changed files

- `includes/class-feed-generator.php`
- `.agents/journal/2026-04-14-1505-ide-gpt5-gmc-full-description-priority.md`

## commands / checks / tests

- `php -l includes/class-feed-generator.php`
- `rsync ... includes/class-feed-generator.php ...strum.../wp-content/plugins/gmc-feed-for-woocommerce/includes/class-feed-generator.php`
- `ssh techmash "wp --path=/home/u731710222/domains/strum.biz.ua/public_html cache flush"`

## result

- GMC feed тепер повинен віддавати повний опис товару, якщо він заповнений.
- Короткий опис лишився fallback, а не основним джерелом.

## next steps

1. Перегенерувати GMC feed.
2. Дочекатися повторного зчитування в Merchant Center.
3. Перевірити конкретний товар у GMC після оновлення даних.
4. Якщо `Введення в оману` залишиться, окремо звірити сторінку товару, ціну, наявність і policy pages сайту.

## risks / notes

- Сам по собі кодовий фікс не оновлює вже прочитаний Google фід, поки не буде нової генерації і повторного fetch з боку Merchant Center.
