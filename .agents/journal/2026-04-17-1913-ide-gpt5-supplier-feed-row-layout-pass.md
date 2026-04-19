## Goal

Publish the next approved layout pass for individual supplier feed cards.

## Done

- Removed duplicate enabled-state messaging from the feed-row header.
- Removed the top-right delete button.
- Removed repeated import and cleanup summary lines under the supplier title.
- Rebuilt the lower half of the card into two paired rows:
  - `Джерело / Що оновлювати`
  - `Розклад цього feed / Дії з feed`
- Moved `Видалити` into the new feed actions panel together with save, analyze, and run actions.
- Moved the unsaved-changes status into the top-right area of the actions panel.
- Kept three schedule controls on one row, with the third slot visible but inactive for daily-style modes.
- Removed the hover lift effect.
- Added clearer dropdown affordances for schedule selects.
- Kept equal heights only within each row pair instead of forcing the lower row to match the upper one.
- Synced PHP, CSS, and JS rendering for both existing and newly created supplier rows.
- Deployed to production and flushed cache.

## Changed files

- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.css`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js`

## Checks

- `php -l` on local `includes/class-admin-settings.php`
- `node` syntax check for `assets/admin.js`
- remote `php -l` on production
- `wp cache flush` on production
- live browser check in Chrome DevTools
- console warnings/errors: none

## Result

Supplier feed cards now use a cleaner, denser layout with clearer action grouping and less duplicated state information.

## Next steps

- Continue with smaller polishing passes on spacing, text, and states.
- Recheck the top badge row with longer real schedule strings if more supplier combinations are added.

## Risks

- The schedule area now intentionally keeps a disabled third slot visible for daily-style modes, so any further schedule UX changes should be checked live in the browser.
