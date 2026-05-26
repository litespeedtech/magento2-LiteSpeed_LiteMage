# LiteMage Cache Warmer User Guide

Date: 2026-05-26

This guide is for Magento admin users who configure, operate, and troubleshoot
the LiteMage cache warmer. It explains what each admin action does, which
settings matter first, and how to read queue behavior without needing to inspect
the database.

## Where To Find It

Configuration:

- Stores > Configuration > Advanced > LiteMage Cache > Cache Warmer

Operational pages:

- System > Cache Management > LiteMage Cache Warmer Status
- From the status page, use the tabs for Progress, Warmup Queue, Results, and
  Purge Events.

Important rule:

- Saving configuration does not rebuild the queue by itself. After changing URL
  sources, store policy, variants, profiles, priorities, or source paths, run
  Build Queue or wait for the Queue Build cron.

## Recommended Setup Flow

Use this order for a new store or a major source change:

1. Keep Enable Cache Warmer set to No.
2. Configure Store Warmup Policy so only the intended store views participate.
3. Start with the Guest variant only. Add currencies or representative
   customer IDs later.
4. Choose URL Sources. Start with Sitemap or a small Text/CSV File source.
5. Set conservative Queue Processing Limits:
   - Batch Size: default 50.
   - Concurrency: default 2.
   - Max Runtime: default 240 seconds.
   - Queue Limit Per Store: default 10000 before variant expansion.
   - Max Server Load Average: 0 means off; set a real threshold only after you
     know the store's normal load range.
6. If you have CLI access, validate source files before building:

```bash
php bin/magento cache:litemage:warm:source:validate --source=sitemap
php bin/magento cache:litemage:warm:source:validate --source=text_file
```

7. Run Build Queue from Warmup Queue.
8. Check Progress and Warmup Queue. Confirm row counts, stores, profiles,
   source queues, and priorities look correct.
9. Enable Cache Warmer.
10. Use Run Queue Now for one controlled pass if you want to start before the
    next Queue Process cron.
11. Check Results for HTTP status, cache status, response time, and errors.

For a cautious production rollout, process a small number from CLI first:

```bash
php bin/magento cache:litemage:warm:queue:process --limit=10 -v
```

## What The Warmer Does

The warmer creates durable queue work for selected storefront URLs and then
crawls due rows in controlled batches.

There are two main work types:

- Scheduled work: created by Build Queue from selected sources such as Sitemap,
  Text/CSV File, Magento URL Rewrites, and optional Recently Seen URLs.
- Purge-driven delta work: created from LiteMage purge events. It is urgent and
  one-shot.

Scheduled crawls and purge-driven delta crawls normally use `litemage_runner`.
In runner mode, LiteSpeed serves cache hits without loading Magento, and Magento
is reached only on a cache miss. Use `litemage_walker` only when you deliberately
want backend refresh crawls, want to extend cached page TTL, and can tolerate
the extra load. Normal visitors are still served from cache when a valid cache
copy already exists.

The warmer is safe by default: it is disabled in default config, uses
`litemage_runner`, processes 50 rows per run, uses concurrency 2, and limits one
worker run to 240 seconds.

## Admin Pages

Status is the overview page. It shows whether the warmer is enabled, the Build
Queue and Run Queue cron schedules, queue counts, result history, purge-event
history, and Tag-to-URL Index storage.

Progress is the best page for day-to-day monitoring. It shows runner state,
build/process schedules, current load, load guard, due rows, lane locks, latest
runner events, total completion, and per-queue progress bars. Expand sublanes to
see profile and mode breakdowns.

Warmup Queue is the work list. Use it to build work, run a queue pass, retry
failures, pause or resume processing, clear disabled-source rows, filter rows,
blacklist URLs, warm selected rows, and show cURL diagnostics.

Results is the attempt history. Use it to inspect HTTP codes, LiteSpeed cache
status, response time, final errors, and previous attempts for a URL/profile.

Purge Events shows recent LiteMage purge tags and how many warmup rows were
queued, restarted, or affected by purge-driven warmup.

## Queue Actions

Build Queue adds or updates scheduled queue rows from the currently enabled URL
sources. It also removes scheduled-source work for sources that are no longer
enabled and cleans stale source memberships. The default Queue Build cron is
daily at 03:00 server time.

Build Queue does not crawl URLs. It prepares work only. The Queue Process cron,
which defaults to every five minutes, claims and crawls due rows.

Run Queue Now starts one manual queue pass. It uses the same worker path as
cron, so it honors enabled state, load guard, batch size, concurrency, crawl
delay, request timeout, max runtime, and customer-session lane locks. It also
recovers stale running rows before claiming work. If Cache Warmer is disabled,
Run Queue Now skips instead of processing rows.

Retry Failed resets failed scheduled rows back to pending, sets attempts back to
zero, clears the last error, and makes those rows due now. Use it after fixing
the cause of failures.

Clear Disabled Sources appears when queue rows or source memberships still
belong to sources that are no longer enabled. It removes that disabled-source
scheduled work immediately. Build Queue also reconciles this automatically.

Pause sets Enable Cache Warmer to No in config and stops queue build/process
runs without deleting queue rows. Resume sets Enable Cache Warmer to Yes in
config so cron and manual queue processing can continue.

## Selected Row Actions

The Warmup Queue grid also has selected-row actions.

Blacklist Selected URLs marks selected URLs as excluded from future warmup.
Blacklisted rows stay visible with Blacklisted status.

Unblacklist Selected URLs removes that exclusion. Future Build Queue runs may
add or update those URLs again.

Warm Selected URLs Now sends crawler requests for the checked rows immediately
and records Results. Use this for small checks. It does not wait for
`next_run_at`; it uses configured concurrency and customer-session lane locks,
but it is not the same as the normal load-guarded Run Queue Now worker pass.

Show cURL for Selected URLs displays up to the first five diagnostic cURL
commands. Basic Auth passwords are redacted. Representative customer-session
profiles may be skipped because those commands would contain signed login
tokens.

## Queue Statuses

Pending means a row is waiting for its next run time. If Next Run is in the
future, the worker will not claim it yet.

Running means a worker claimed the row and is processing it.

Warmed means the latest attempt completed successfully and no next run is
scheduled. If a successful row has a regular recrawl interval, it is stored as
Pending with a future Next Run instead of staying Warmed.

Failed means the row exhausted its allowed attempts. Failed scheduled rows stay
in the queue until Retry Failed, Purge All restart, or interval-based
reactivation brings them back.

Skipped means the worker intentionally did not keep retrying the row. Examples
include HTTP 404, cross-site redirects, too many redirects, or another safe
skip condition. A 404 also deactivates the known URL so known-URL recrawls do
not keep requesting it unless a source discovers it again.

Blacklisted means the URL is excluded from warmup.

HTTP result handling:

- Any 2xx response is treated as warmed. HTTP 201 is included and is shown as a
  cache miss when no LiteSpeed cache-status header is present.
- Same-site redirects are followed up to three redirects.
- Cross-site redirects and redirect loops are skipped.
- HTTP 404 is skipped and the known URL is deactivated.
- Other 4xx and 5xx responses are failures and count toward max attempts.

## Attempts And Retries

The queue's attempts display is `attempts/max_attempts`. With the default
configuration, `max_attempts` is `3`.

Examples:

- `0/3`: no failed attempts yet.
- `1/3`: one failed attempt; the row will retry later.
- `2/3`: two failed attempts; the row will retry later.
- `3/3`: the row reached failed status and cron will not retry it further.

Retries are delayed. After a failure, the worker records the result, increments
attempts, schedules a future retry, and continues to the next URL.

The retry delay is `60 * attempts` seconds, capped at 3600 seconds. With the
default settings:

- First failure: retry in about 60 seconds.
- Second failure: retry in about 120 seconds.
- Third failure: mark failed and stop automatic retries.

Successful warmup resets attempts to zero.

Build Queue is not the same as Retry Failed. Build Queue updates source
ownership, priority, URL data, and membership information. It does not normally
clear failed attempts just because the URL still appears in a source.

A daily Build Queue run does not necessarily clear `3/3` failed rows or crawl
them. It can reactivate a completed or failed scheduled row only when that row
has a regular recrawl interval and the next run time is due, or when purge
handling marks the row urgent. Use Retry Failed for manual failed-row recovery.

## Load Guard, Stale Rows, And Lane Locks

The load guard checks the 1-minute system load average. When Max Server Load
Average is greater than zero and current load is at or above that value, cron,
Run Queue Now, and CLI queue processing skip without claiming more work.

If load rises or max runtime is reached during a healthy worker run,
unprocessed claimed rows are released back to pending immediately.

A Running row can become stale if PHP is killed, the server reboots, a process
fatals, or a worker loses its database connection after claiming rows. The next
cron or Run Queue Now pass releases stale running rows before claiming new work.
Rows older than `max(300, max_runtime * 2)` seconds are moved back to pending.

Lane locks prevent representative customer-session variants for the same
profile, store, and mode from running concurrently. Expired lane locks are
cleaned before workers claim new work.

## Sources And URL Rules

Scheduled URL Sources are selected in the Cache Warmer configuration. Defaults
are Sitemap and Text/CSV File. Magento URL Rewrites can also be enabled.
Recently Seen URLs are controlled separately by Tag-to-URL Index settings.

All source URLs must resolve to configured store hosts. Relative URLs require a
store ID from the source row and are expanded using that store's base URL.
Absolute URLs must match the selected store's host and allowed port.

The warmer rejects unsafe storefront paths such as admin, checkout, customer,
cart, wishlist, catalogsearch, review/customer, and dot-segment paths.

Query strings are rejected unless Allowed Query Parameters lists every query
parameter name used by the URL. Array query parameters are not supported. The
setting allows parameter names, not specific values.

Priority values are whole numbers from 0 to 9999. Lower numbers run earlier.
Effective priority is built from source/queue priority, optional URL priority,
variant offset, and store priority offset. Urgent purge-driven work is ordered
before normal scheduled work.

## Sitemap Source

Use Sitemap for normal catalog and CMS discovery.

Each configured Sitemap Paths row uses:

```text
path_or_url,store_ids,source_priority
```

Examples:

```text
var/litemage/warmup/sitemap.xml,1|2|3,100
https://example.com/sitemap.xml,1,100
```

Rules:

- Store IDs are required and use pipe separators.
- Local sitemap files must be under `var/litemage/warmup/`.
- Local and remote sitemap files are capped at 50 MB.
- Remote sitemap host and port must match at least one selected store.
- Nested remote sitemaps must also match selected store hosts and ports.
- Remote sitemap reads use the warmer request timeout, capped at 60 seconds,
  and do not follow remote HTTP redirects.
- URLs with no `lastmod` or a recent `lastmod` get URL priority `0`; older
  `lastmod` values get URL priority `25`.

## Text/CSV File Source

Use Text/CSV File for curated Top-N URLs, important landing pages, or pages
exported from analytics. Generate large ranking lists outside Magento and keep
the file bounded.

Configured Text/CSV File Paths rows use:

```text
path,store_ids,source_priority
```

Example:

```text
var/litemage/warmup/popular-urls.csv,1|2|3,20
```

Rows inside the referenced file use:

```text
url,url_priority
```

Examples:

```text
/
/women/tops.html,5
https://example.com/sale.html,10
```

Rules:

- Source files must be local and under `var/litemage/warmup/`.
- Source files are capped at 50 MB.
- Blank lines are skipped.
- Lines beginning with `#` are treated as comments.
- Relative URLs are expanded for each selected store.
- Absolute URLs must match a selected store host and allowed port.
- URL priority is optional. Blank means no URL-specific priority offset.

## Magento URL Rewrites Source

Magento URL Rewrites reads Magento's `url_rewrite` table. By default it includes
product, category, and CMS page entity types.

Use this source when the store's URL rewrite table is a reliable list of pages
to warm. Keep Queue Limit Per Store and variant count conservative because this
source can generate many rows.

## Recently Seen URLs And Tag-to-URL Index

Tag-to-URL Index is an optional fallback for purge-driven warmup. It records
compact product/category tag-to-URL mappings from cacheable crawler MISS
responses. Real browser requests do not write these mapping rows.

Keep it disabled unless direct purge warmup plus scheduled sitemap/CSV recrawls
miss important pages.

When Tag-to-URL Index and Enable Recently Seen URLs Queue are both enabled,
Build Queue can add recently seen tagged URLs collected within the mapping TTL.
The default Recently Seen URLs Limit is 1000 before variant expansion.

## Variants, Stores, And Lanes

Guest is the baseline profile and is always included.

Non-default currency variants are created from Non-default Currencies To Warm.
Each selected currency is used only for stores where that currency is enabled
and is not the store default.

Representative Customer IDs are for logged-in or customer-group-specific
pricing. Use dedicated warmup accounts assigned to the groups you need to warm.
Do not use private shopper accounts or real customer cookies.

The warmup login is not password based. The worker calls a signed no-store
frontend endpoint that accepts only `litemage_runner` or `litemage_walker`
requests with a short-lived token, then Magento creates a normal frontend
session for that representative customer.

Customer-session rows are isolated by profile, mode, and store. Only one worker
can own a customer-session lane at a time, and that lane is processed serially
so the temporary cookie jar is not shared by concurrent requests. Guest and
currency-only lanes can use the configured concurrency.

Store Warmup Policy controls which store views participate, each store's
priority offset, and whether each store uses all variants, guest only, or a
custom variant set.

Queue / Variant Map lets admins disable individual queues or variants and tune
priority per queue. Duplicated URLs from different queues are crawled once at
the effective highest priority. The Queue page's Also Found In column shows
lower-priority source memberships that are covered by the effective queue row.

After changing Store Warmup Policy or Queue / Variant Map, Save Config and run
Build Queue.

## Recrawl And Purge Behavior

Regular Recrawl Interval controls scheduled recrawls after a successful warmup.

- Blank or `0`: use the Magento public TTL. Runner mode uses the TTL. Walker
  mode uses slightly less than the TTL.
- Positive value: use that many seconds, with a minimum of 300 seconds.
- `-1`: disable recurring scheduled recrawl. Successful rows become Warmed
  until purge handling or a manual action makes them pending again.

Purge-driven delta warmup is event-driven and uses `litemage_runner`. It is
enabled by default, but no purge-driven warmup is recorded while the main Cache
Warmer setting is disabled.

For product, category, and CMS purge tags, the warmer tries to resolve direct
frontend URLs and queues them as urgent one-shot work. If Tag-to-URL Index is
enabled, related indexed URLs may also be queued.

Purge-driven delta rows are transient. They are removed from the queue after
success, skip, final failure, or Purge All supersedes them. A later purge event
can create new delta work for the same URL.

Purge All is treated as a broad invalidation. When Purge All is recorded, the
warmer:

- clears transient delta work, including claimed delta rows;
- advances the scheduled warmup round for covered scheduled rows;
- resets non-running scheduled rows to pending and due now;
- protects in-flight scheduled rows so an old pre-purge crawl cannot mark the
  row warmed after Purge All.

If a scheduled worker finishes after its row's warmup round changed, completion
is ignored and the row returns to pending for a fresh post-purge run.

Purge All restart can still run when Cache Warmer is enabled even if regular
purge-driven delta warmup is disabled.

## Retention And Cleanup

The cleanup cron uses Queue and Result Retention Days, default 30 days, for
result history, purge-event history, and runner-event history. Tag-to-URL Index
rows expire by Tag Mapping TTL Days, default 7 days.

Durable scheduled queue rows are intentionally retained. They are not deleted
just because they reached Warmed, Skipped, or Failed. Later source scans update
the same rows, purge events can mark matching rows urgent, and interval-based
work becomes pending again only when its interval is due.

To delete warmer data from CLI:

```bash
php bin/magento cache:litemage:warm:truncate --failed-only
php bin/magento cache:litemage:warm:truncate --all-data
```

Use `--all-data` only when you want to remove all warmer tables and temporary
warmer cookie files.

## Troubleshooting

If the queue is empty:

- Confirm Cache Warmer has at least one enabled URL Source, or purge-driven
  delta warmup is enabled for future purge events.
- Confirm Store Warmup Policy enables the intended stores.
- Run Build Queue.
- If using sitemap or Text/CSV File, validate the source from CLI if available.

If Build Queue reports source errors:

- Check that source rows include store IDs in the second column.
- Check local source files are under `var/litemage/warmup/`.
- Check file size is under 50 MB.
- Check absolute URLs match the selected store host and port.
- Add only necessary public query parameter names to Allowed Query Parameters.
- Remove unsafe paths such as checkout, customer, cart, wishlist, admin, and
  catalogsearch.

If the queue is not moving:

- Confirm Cache Warmer is enabled or resume it from Progress or Warmup Queue.
- Confirm pending rows are due now.
- Check Progress for recent load-guard skips.
- Check current load against Max Server Load Average.
- Check lane locks if customer-session profiles are configured.
- Confirm failed rows have not reached max attempts.
- Confirm cron is running, or use Run Queue Now for one manual pass after Cache
  Warmer is enabled.

If rows show `3/3`:

- Fix the cause shown in Last Error.
- Use Retry Failed to move failed scheduled rows back to pending.

If rows are stuck in Running:

- Use Run Queue Now or wait for the next process cron. Stale recovery runs
  before new work is claimed.
- Running rows older than `max(300, max_runtime * 2)` seconds are released back
  to pending.

If source changes do not appear:

- Run Build Queue. Config save does not rebuild the queue.
- If disabled-source warnings appear, use Clear Disabled Sources or Build Queue.

If customer-group warmup is slow:

- This is expected for representative customer profiles. Customer-session lanes
  run serially per profile, mode, and store.
- Start with one or two representative customers and add more only after
  confirming the cache objects are useful.

If cache status is empty:

- Check Results headers and response code.
- For a cacheable LiteMage page, `X-LiteSpeed-Cache` or `X-LSADC-Cache` should
  normally show hit or miss. If the cache status is empty, treat it as a signal
  to verify LiteMage is enabled for the page, the page is cacheable, the request
  did not hit an excluded path or context, and the response headers are not
  stripped by a proxy.
- HTTP 201 is treated as a cacheable response by LiteMage and displayed as miss
  if the cache-status header is absent. Empty cache status on other 2xx
  responses should still be investigated.

## Useful CLI Commands

These are optional but useful for operators with shell access:

```bash
php bin/magento cache:litemage:warm:status
php bin/magento cache:litemage:warm:status --failed
php bin/magento cache:litemage:warm:queue:generate --dry-run
php bin/magento cache:litemage:warm:queue:generate --source=sitemap --dry-run
php bin/magento cache:litemage:warm:queue:process --limit=10 -v
php bin/magento cache:litemage:warm:url /some-url --store=1 --mode=runner -v
php bin/magento cache:litemage:warm:profile:list
php bin/magento cache:litemage:warm:profile:diagnose https://example.com/ --profile=guest
```

Check warmer logs in:

```text
var/log/litemage-crawler.log
var/log/litemage.log
```
