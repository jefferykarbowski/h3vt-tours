# Client analytics: moving off h3-tour-management

h3vt-tours 1.11.0 carries the two client-facing analytics features that used to
live in h3-tour-management (h3tm):

- the scheduled analytics emails (was cron `h3tm_analytics_cron`)
- the `/tour-analytics/` page (page 528, shortcode `[tour_analytics_display]`)

plus the user-profile fields that drive them (Users > Edit > "Tour Analytics").

## Why the data has to be copied first

h3tm's `uninstall.php` drops `{prefix}h3tm_user_settings` (frequencies and
last-sent dates) and deletes every `h3tm_tours` user meta row (assignments).
It keeps `{prefix}h3tm_tour_metadata` and the `h3tm_ga_measurement_id` option,
which h3vt-tours still reads. So the assignments must be copied into
h3vt-tours storage before h3tm is **deleted**. Deactivating h3tm deletes nothing.

## Where h3vt-tours keeps it

| What | Where |
|---|---|
| Assigned tours | user meta `h3vt_tours_assigned`: `tour:{post_id}` or `legacy:/tours/{tour_id}/` |
| Email frequency | user meta `h3vt_tours_email_frequency` (daily, weekly, monthly, never) |
| Last report sent | user meta `h3vt_tours_last_email_sent` (site time) |
| Old tour linked to a rebuilt one | post meta `_h3vt_tours_legacy_paths` on the `h3vt_tour` post |
| Sender | options `h3vt_tours_email_from_name`, `h3vt_tours_email_from_address` |

A client assigned a rebuilt tour gets numbers for **both** URLs: the new
`/tour/{slug}/` page (tracked since 2026-10-15) and the old `/tours/{tour_id}/`
one, so "All Time" keeps its history. Draft tour posts report only the old URL
until they are published.

## Who owns what while both plugins are installed

h3vt-tours checks for h3tm (`H3TM_VERSION`). While h3tm is active:

- h3vt-tours sends nothing (its cron returns early, `run` refuses)
- `[tour_analytics_display]` stays h3tm's
- the h3vt-tours profile fields are read-only, with a notice

Deactivating h3tm hands all three over on the next request. No settings toggle.

## Cutover

1. Deploy h3vt-tours 1.11.0 with h3tm still active. Nothing changes for clients.
2. Dry run and review:

   ```
   wp h3vt-tours client-analytics migrate --ga
   ```

   Rows marked `UNMATCHED`, `AMBIGUOUS` or `GA-CONFLICT` stay assigned as the old
   tour (same numbers clients get today) unless settled with `--map`.
3. Apply, with any overrides:

   ```
   wp h3vt-tours client-analytics migrate --ga --apply --map="Arden Lely Community=7611"
   ```

4. Check a client or two: `wp h3vt-tours client-analytics preview 70 > /tmp/70.html`,
   or the Preview button on their profile.
5. Pick a day that is **not** the 1st-7th of a month or a Sunday-Tuesday (so no
   report is due), re-run step 3 to pick up any late edits, then deactivate h3tm.
6. Check `/tour-analytics/` as a client (User Switching is installed) and
   `wp h3vt-tours client-analytics due --date=YYYY-MM-01` for the next send.
7. After the first monthly send goes out from h3vt-tours, delete h3tm.

Re-running `migrate --apply` after h3tm is deactivated needs `--force`, because it
rebuilds assignments from h3tm's data and would undo edits made since.

## Rollback

Reactivate h3tm. It takes the shortcode and the emails back and its data is
untouched. The h3vt-tours user meta can stay. It does nothing while h3tm is active.

## Changes from h3tm's behaviour

- Reports count only complete days, ending yesterday. "Last Month" is now
  "Last 30 Days", and "All Time" starts 2020-01-01 instead of three years back.
- A monthly report missed because the site was down on the 1st still goes out
  through the 7th. Weekly reports have a two-day window after Sunday.
- A client is only marked as sent when `wp_mail()` succeeds. h3tm stamped them
  regardless.
- Only users who can manage other users see or edit the profile fields. h3tm let
  a client edit their own assigned tours.
- Tours are matched by URL prefix (`BEGINS_WITH /tours/{id}/`), the same as Tour
  Stats. Session counts matched h3tm's `CONTAINS` filter exactly on the tours
  checked.
- Titles come from the tour post when one is assigned ("Arden Courts of Largo"
  rather than the old "Arden Largo").
