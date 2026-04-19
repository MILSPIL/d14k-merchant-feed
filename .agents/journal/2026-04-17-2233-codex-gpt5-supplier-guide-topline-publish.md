## Goal

Publish the approved supplier intro guide-card refinement: move titles next to the step number and split them into 2 lines.

## Done

- Updated supplier intro guide markup to use a dedicated topline wrapper with the step number on the left and the title on the right.
- Changed the three intro titles to a 2-line layout:
  - `Додайте` / `feed`
  - `Перевірте` / `аналіз`
  - `Запускайте` / `у фоні`
- Adjusted CSS for the new guide topline:
  - grid layout for number + title
  - slightly larger step badge
  - aligned title baselines across the 3 cards
- Deployed updated PHP and CSS to production.
- Flushed WordPress cache.
- Verified the live page in Chrome DevTools.

## Changed Files

- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.css`

## Checks

- `php -l /Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- Remote `php -l` on production plugin file
- `wp cache flush` on production
- Live DOM check on `https://strum.biz.ua/wp-admin/admin.php?page=d14k-merchant-feed&tab=suppliers&d14k_notice=saved`

## Result

The approved intro guide layout is live. The top supplier onboarding cards now read more cleanly and keep a more intentional horizontal rhythm.

## Next Steps

- Continue the next incremental supplier-tab UI refinements when new review notes arrive.

## Risks

- Future typography or spacing changes can shift this block again, so the guide-card topline should be rechecked in the browser after any global type updates.
