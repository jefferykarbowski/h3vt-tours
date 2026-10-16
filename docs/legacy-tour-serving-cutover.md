# Legacy tours: moving /h3panos/ serving off h3-tour-management

h3vt-tours 1.12.0 can serve the old 3DVista tours at `https://h3vt.com/h3panos/{slug}/...`
and the `/h3-tour-analytics.js` script they load, which until now only the
h3-tour-management plugin (h3tm) could do. That was the last thing keeping h3tm
active. Client analytics already moved on 2026-10-16 (see
[client-analytics-cutover.md](client-analytics-cutover.md)).

Code: `includes/class-h3vt-tours-legacy-tours.php`, CLI in
`includes/class-h3vt-tours-legacy-tours-cli.php`.

## What h3tm still does on live

Checked on 2026-10-16 against live h3tm 2.9.0 (file checksums match the
`h3-tour-management` repo's `main`) and ten days of nginx logs (Oct 6 to 16).

| Piece | What it does | Live use | Matters? | After this PR |
|---|---|---|---|---|
| S3 proxy (`class-h3tm-s3-proxy.php`) | Answers `/h3panos/{slug}/{file}` at `init` by fetching the file from CloudFront `d14z8re58oj029.cloudfront.net` (origin path `/tours`) and passing it through | About 1,500 requests a day across 105 tours. Embedded on client sites, including camphillnursingandrehab.com, seniorcarefinder.com, acceleratepiscataway.com, the aubergeXXX.com sites, villageatwesterville.com and arden-courts.com | **Yes. Outage if it stops.** | Ported |
| Slug resolution and redirects | Slug to S3 folder via `{prefix}h3tm_tour_metadata`; 301 for old slugs (`url_history`, 10 tours); 301 `/h3panos/x` to `/h3panos/x/?_redirected=1`; custom entry file (1 tour, Mellott) | Every request | Yes | Ported, same table |
| `/h3-tour-analytics.js` (`class-h3tm-analytics-endpoint.php`) | GA4 loader that every Lambda-processed tour's `index.htm` on S3 references (tour id, title and `/tours/{id}/` path in the query string) | About 40 a day | Yes: it is how the tours report into GA property 491286260 | Ported, JavaScript byte for byte |
| `/h3-analytics.js` | Older loader | None in the logs | Low | Ported anyway (small) |
| Inline gtag in the oldest tours | Injected into those S3 `index.htm` files by a 2025 cron | Served from S3 as-is | Yes, but no server code involved | Nothing to port |
| `wp_new_user_notification()` override | Stubs out WordPress's "new user" emails to admin and user (moved there from the theme's functions.php) | Every user Greg creates | Yes, or retiring h3tm turns those emails back on | Ported to `h3vt-tours.php` |
| Client analytics emails, `/tour-analytics/`, profile fields | | | | Already taken over (1.11.1) |
| Tour Stats admin page | | 5 admin hits | | Already ported (1.10.0) |
| "3D Tours" admin pages | Tour list, S3 upload through Lambda, rename, change URL, delete, embed code; analytics, S3 and diagnostics settings | `page=h3-tour-management` 18 hits, `page=h3tm-analytics` 4 hits in 10 days. Newest tour row created 2026-03-02, last edit 2026-05-01: nothing has been uploaded through h3tm in 7 months | Only if Greg still needs to upload, rename or re-slug **old** tours. New tours are `h3vt_tour` posts | **Not ported.** `wp h3vt-tours legacy-tours list` gives every URL. Decide before deleting h3tm |
| Lambda pipeline and webhook | Processes uploads, calls `admin-ajax.php?action=h3tm_lambda_webhook` | Only on upload | No, once uploads stop | Not ported. AWS-side Lambda can be retired with h3tm |
| AWS keys in `wp_options` | `h3tm_aws_access_key` / `h3tm_aws_secret_key`, plaintext, IAM user `h3-tour-management-plugin` | h3tm requires them before it will serve | **Serving does not need them**: CloudFront is public | The port never reads them. Delete and rotate after h3tm is gone (see clean-up) |
| Cron `h3tm_inject_analytics` (hourly) | Runs `tools/cron-inject-analytics.php` | No-op: that file is missing; last real run 2025-09-29 | No | Clear after deletion |
| Rewrite rules for `h3panos/` and the analytics scripts | Fallback routing | Never reached: the `init` handler exits first | No | Port serves at `init`, needs no rules |

Settings h3tm reads that the port reads too: `h3tm_cloudfront_enabled`,
`h3tm_cloudfront_domain`, `h3tm_s3_bucket`, `h3tm_s3_region`,
`h3tm_ga_measurement_id` (unset on live, so the default `G-08Q1M637NJ`
applies, same as h3tm), `h3tm_analytics_enabled` (unset, defaults on).
`takeover on` copies the CloudFront and bucket values into
`h3vt_tours_legacy_tours_source`, so serving stops depending on h3tm's options.
h3tm's `uninstall.php` keeps those options and the metadata table anyway.

Server side, the nginx vhost's `location ^~ /h3panos/ { try_files $uri $uri/ /index.php?$args; }`
and the static-asset block's fall back to `index.php` must stay as they are:
the tours are not files on disk.

## How the port behaves

Same as h3tm, request for request:

- Same URL forms, same 301s (`X-Redirect-By: WordPress`), same `?_redirected=1`
  loop guard, and the same quirk of dropping any other query string on a
  tour's directory URL.
- Same origin URLs and fallbacks (CloudFront first; for legacy names with
  spaces, the dashed folder name second).
- Same response headers on files: `Cache-Control: public, max-age=3600`,
  `Expires` one hour out, `X-Content-Type-Options: nosniff`, and h3tm's short
  content-type list (anything else goes out as `application/octet-stream`).
- Same 404 (`Tour Not Found` wp_die page) and same bare `/h3panos/` behaviour
  (WordPress 404 page).
- Same analytics JavaScript and query handling, including h3tm's habit of not
  unslashing the title, so GA page titles do not change.

Deliberate differences:

- AWS keys are not required (h3tm would 503 without them). Only the CloudFront
  domain or the bucket name is needed.
- Request bodies are streamed through a temp file instead of held in PHP
  memory, which matters for tour videos.
- `/h3-tour-analytics.js` is answered directly. h3tm first 301'd it to
  `/h3-tour-analytics.js/` (WordPress canonical redirect, about half of those
  requests in the logs), then served the same script.
- Only paths that start with `/h3panos/` are treated as tours. h3tm matched
  `/h3panos/` anywhere in the request URI, query string included.
- A bare `/h3panos/?anything` gets WordPress's 404 page. h3tm's fallback
  handler 301'd it to a junk URL (`/h3panos/%3Fanything/?_redirected=1`).
- The analytics scripts come out with LF line endings. h3tm's source file is
  CRLF, so its output was too. The JavaScript is otherwise identical.

Serving runs at `init` priority 0. h3tm's handler runs at `init` 999, so while
both are active and takeover is on, h3tm never sees the request.

## Who serves, when

| | h3tm active, takeover off | h3tm active, `takeover on` | h3tm deactivated or deleted |
|---|---|---|---|
| `/h3panos/` tours and analytics scripts | h3tm | **h3vt-tours** | **h3vt-tours** |
| h3vt-tours 1.12.0 | Dormant for these URLs | Answers first; h3tm untouched | Only server |

Status: `wp h3vt-tours legacy-tours takeover status`.

## Cutover

Run on the VPS as root in `/var/www/h3vt/public`, with `--allow-root`. Ask
Jeff before each step that changes live.

1. **Deploy 1.12.0** by file sync, as usual (back up the current plugin dir
   first, e.g. to `/root/backups/plugin-update-YYYYMMDD/`). With h3tm active
   and takeover off this changes nothing for tours. Spot check:
   `curl -sI https://h3vt.com/h3panos/CampHill/` still 200 with
   `Cache-Control: public, max-age=3600`.
2. **Dry check (read-only):** `wp h3vt-tours legacy-tours check`. Resolves every
   row of the metadata table the way a visitor's request would and HEADs its
   entry file at CloudFront. Expect 200 for every live tour; 301 rows are the
   old slugs.
3. **Take over:** `wp h3vt-tours legacy-tours takeover on`.
4. **Verify right away:**
   - `curl -sI https://h3vt.com/h3panos/CampHill` → 301 to `/h3panos/CampHill/?_redirected=1`
   - `curl -sI https://h3vt.com/h3panos/CampHill/` → 200, `text/html`, `Cache-Control: public, max-age=3600`
   - `curl -sI https://h3vt.com/h3panos/arden-elkgrove/` → 301 to `/h3panos/arden-elk-grove/`
   - `curl -s "https://h3vt.com/h3-tour-analytics.js?tour=x" | head -3` → `// H3 Tour Analytics Script v2.0`, no 301
   - Open an embedded tour on a client site (e.g. camphillnursingandrehab.com)
     and click past the loading screen.
   - GA4 Realtime for property 491286260 shows the view under `/tours/{id}/`.
5. **Watch for a day:** compare the status mix of `/h3panos/` requests in
   `/var/log/nginx/access.log` with the pre-cutover baseline (Oct 6 to 16,
   15,595 requests: 82% 200, 7% 301, 8% 404, 3% 499). Most of those 404s are
   not ours to fix here: see "Tours already broken on live" below. Also check
   `/var/log/php-fpm/www-error.log` for new fatals.
6. **Soak about a week,** then deactivate h3tm:
   `wp plugin deactivate h3-tour-management-main`. Its deactivation clears its
   report cron and flushes rewrite rules; it deletes no data. Repeat step 4.
7. **Delete h3tm** only after deciding whether the old-tour admin pages are
   needed (see the inventory). Back up the DB first. Its uninstall drops
   `h3tm_user_settings` and every `h3tm_tours` user meta row (both already
   copied into h3vt-tours), and keeps the metadata table and the
   CloudFront/S3/GA options.

## Tours already broken on live (before and after this change)

Found while building the baseline. `wp h3vt-tours legacy-tours check --deep`
(run against the h3vt.local clone, which reads the same CloudFront) fails 57
of the 189 URL forms it tries. Almost all are pre-2025 tours without a
tour_id: either their player files (`lib/tdvplayer.js`, `script.js`) are in
S3 Glacier, so CloudFront answers `403 InvalidObjectState`, or the folder
name the URL implies does not exist (`403 AccessDenied`). Five tours with a
tour_id (Kenwood, Potomac, "War", "Wildark", Mellott) are missing player
files from their upload. The proxy turns all of these into 404s and the tour
stays blank after "Loading virtual tour".

Ones still getting real traffic (Oct 6 to 16):

| Tour URL | Player-file 404s | Origin answer | Linked from |
|---|---|---|---|
| `/h3panos/CampHill/` | 729 | Glacier | camphillnursingandrehab.com (501 hits) |
| `/h3panos/Piscataway-Skilled/` | 53 | `lib/tdvplayer.js` in Glacier | acceleratepiscataway.com (75 hits) |
| `/h3panos/The%20Village/` | 14 | Glacier | villageatwesterville.com |
| `/h3panos/Arden-Kenwood/` | 10 | Not in the upload | |
| `/h3panos/Arden-Potomac/` | 4 | Not in the upload | |

h3tm and the port behave identically for these. Fixing them means restoring
the objects out of Glacier for good (copy in place to STANDARD) and checking
the bucket's lifecycle rule, which is separate AWS work.

## Rollback

| From | Do | Effect |
|---|---|---|
| Step 3 (takeover on) | `wp h3vt-tours legacy-tours takeover off` | h3tm serves again on the next request. Nothing else changed |
| Step 6 (h3tm deactivated) | `wp plugin activate h3-tour-management-main`, then `takeover off` | Activation is safe on this site: the metadata migration is already marked done, default options are add-only, the tables are created with dbDelta. It reschedules `h3tm_analytics_cron`, which stays silent while client analytics takeover is on |
| Step 1 (bad deploy) | Restore the previous plugin dir from the backup | 1.11.1 |
| Step 7 (deleted) | Restore the plugin dir and the DB tables/user meta from the backup | |

## Clean-up after h3tm is deleted

- Delete `h3tm_aws_access_key` and `h3tm_aws_secret_key` from `wp_options`,
  then deactivate and delete that IAM access key. The VPS `aws` CLI profile
  `h3tm` uses the same key for tour imports, so give it its own key first. The
  inline policy `h3vt-glacier-restore` on that IAM user is still attached.
- `wp cron event delete h3tm_inject_analytics` (and any leftover
  `h3tm_analytics_cron` / `analytics_cron`).
- Optionally retire the upload Lambda and its webhook secret
  (`h3tm_lambda_function_url`, `h3tm_lambda_webhook_secret`).

## Testing (2026-10-16, h3vt.local live clone)

902 requests replayed from ten days of live nginx logs (every tour root form,
up to six assets per tour, the observed 301s and 404s, 25 analytics script
URLs, all HEAD requests, plus hand-written edge cases), each recorded for
status, `Content-Type`, `Content-Length`, `Cache-Control`, `Expires` offset,
`X-Content-Type-Options`, `Location`, `X-Redirect-By`,
`Access-Control-Allow-Origin` and a body hash:

| Run | Against | Result |
|---|---|---|
| A: live h3vt.com (h3tm 2.9.0) | B | 901 of 902 identical (only the theme's 404 page for bare `/h3panos/`) |
| B: h3vt.local, h3tm 2.9.0's serving classes loaded, takeover off | | baseline |
| C: h3vt.local, h3tm loaded, `takeover on` | B | all 873 tour requests identical; 29 analytics script URLs differ only by the dropped 301 and CRLF |
| D: h3vt.local, h3tm not loaded, no `h3panos` rewrite rules, takeover off | B | same as C plus the bare `/h3panos/?x=1` case above |
| E: 120 tour requests, 4 at a time, as D | B | 120 identical |

Also loaded `/h3panos/auberge-lake-zurich` in a browser with h3tm unloaded:
the tour renders, every resource loads, and the GA4 collect calls fire with
`page_path /tours/20251030_232532_nqt2mrd1/` for G-08Q1M637NJ.
