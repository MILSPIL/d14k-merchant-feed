## Goal

Replace overly dark UI accents in the Suppliers tab with the approved accent color `#554CE6`.

## Done

- Updated the system accent tokens to `#554CE6`.
- Changed the supplier intro step circles from near-black to an accent indigo gradient.
- Applied the accent tint to calmer badge surfaces:
  - `schedule`
  - `next-run`
  - `neutral`
  - `active`
- Updated automation/meta pills in the workbench area to the same accent family.
- Updated checked chips in `Що оновлювати`.
- Brought secondary labels and the markup unit into the same color system.

## Changed Files

- `/Users/user/Documents/Мої-розробки/gmc-feed-for-woocommerce/assets/admin.css`

## Checks

- Production cache flush via `wp cache flush`
- Live browser verification in Chrome DevTools on the suppliers admin page

## Result

The tab now feels less heavy. Instead of isolated dark spots, the accent color carries through numbers, pills, and badges as one consistent visual system.

## Next Steps

- If needed, do a separate color hierarchy pass for success/warning/error states so they stay balanced against the new accent.

## Risks

- Future palette updates should recheck the balance between the accent color and status colors.
