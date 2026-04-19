## Goal

Prepare a better supplier import architecture for large feeds by moving the process to a background server queue instead of browser-driven batches.

## Done

- Confirmed the plugin did not yet use Action Scheduler for supplier imports.
- Added a local Action Scheduler based background supplier import flow.
- Added background job start, worker hook, global state storage, and status reader.
- Added AJAX endpoints to start background import and poll status.
- Switched the supplier import button locally to background behavior.
- Added local polling and stable status-card rendering for background import.
- Updated button label and helper copy for background mode.
- Ran local syntax checks.
- Did not deploy anything to production because the current live import is still running.

## Changed files

- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-supplier-feeds.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/includes/class-admin-settings.php`
- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.js`

## Verification

- `php -l includes/class-supplier-feeds.php`
- `php -l includes/class-admin-settings.php`
- `node -e "new Function(...assets/admin.js...)"`

## Result

- A local background-import version is ready and no longer depends on keeping the admin tab open.
- Deployment is intentionally deferred until the live production import finishes.

## Next steps

- Wait for the current live import to finish.
- Deploy the background queue version.
- Verify Action Scheduler availability and live polling behavior on production.

## Risks

- Background mode is local only right now.
- Production verification is still required after deployment.
