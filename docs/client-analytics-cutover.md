# Client analytics: moving off h3-tour-management

h3vt-tours 1.11.x carries the two client-facing analytics features that used to
live in h3-tour-management (h3tm):

- the scheduled analytics emails (was cron `h3tm_analytics_cron`)
- the `/tour-analytics/` page (page 528, shortcode `[tour_analytics_display]`)

plus the user-profile fields that drive them (Users > Edit > "Tour Analytics").

## Why the data has to be copied first

h3tm's `uninstall.php` drops `{prefix}h3tm_user_settings` (frequencies and
last-sent dates) and deletes every `h3tm_tours` user meta row (assignments).
It keeps `{prefix}h3tm_tour_metadata` and the `h3tm_ga_measurement_id` option,
which h3vt-tours still reads. So the assignments must be copied into
h3vt-tours storage before h3tm is ever **deleted**. Deactivating h3tm deletes nothing.

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

h3tm cannot be deactivated yet: its S3 proxy serves every old `/h3panos/` tour,
including the ones embedded on client sites. So client analytics move over
with a switch instead, while h3tm stays active.

| | Takeover off (default) | `takeover on` |
|---|---|---|
| Report emails | h3tm's cron | h3vt-tours' cron. h3tm's cron event still fires with nothing attached |
| `[tour_analytics_display]` | h3tm | h3vt-tours |
| Profile fields | h3tm's "3D Tour Settings". h3vt-tours' are read-only | h3vt-tours' "Tour Analytics". h3tm's are unhooked |
| `/h3panos/` tours | h3tm | h3tm (untouched) |

h3tm's data is never modified, so `takeover off` restores its reports exactly.
Deactivating h3tm (once tour serving has moved) also hands everything to
h3vt-tours with no switch needed.

## Cutover

1. Deploy h3vt-tours 1.11.1 with h3tm active. Nothing changes for clients.
2. Dry run and review:

   ```
   wp h3vt-tours client-analytics migrate --ga
   ```

   Rows marked `UNMATCHED`, `AMBIGUOUS` or `GA-CONFLICT` stay assigned as the old
   tour (same numbers clients get today) unless settled with `--map`.
3. Pick a day that is **not** the 1st-7th of a month (or Sunday-Tuesday if anyone
   is weekly), then apply, with any overrides, and switch over:

   ```
   wp h3vt-tours client-analytics migrate --ga --apply --map="Arden Lely Community=7611"
   wp h3vt-tours client-analytics takeover on
   ```

4. Check a client or two: `wp h3vt-tours client-analytics preview 70 > /tmp/70.html`,
   `/tour-analytics/` as a client (User Switching is installed), and
   `wp h3vt-tours client-analytics due --date=YYYY-MM-01` for the next send.
5. h3tm stays installed until its tour serving is ported. Never delete it before
   then, and remember its uninstall wipes `h3tm_tours` and `h3tm_user_settings`.

Re-running `migrate --apply` once client analytics run here needs `--force`,
because it rebuilds assignments from h3tm's data and would undo edits made since.

## Rollback

`wp h3vt-tours client-analytics takeover off`. h3tm takes the shortcode, the
emails and its profile fields back, with its data untouched. The h3vt-tours user
meta can stay. It does nothing while takeover is off.

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
