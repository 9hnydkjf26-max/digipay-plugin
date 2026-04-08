# Digipay Support Runbook

This document is the operating manual for anyone responding to merchant
support requests for the Digipay WooCommerce plugin. If you can read this and
know how to open a terminal, you can run support without escalating to
engineering for the common cases.

## TL;DR

1. Ask the merchant for a **diagnostic bundle** (or a bundle ID if auto-upload
   is enabled).
2. Run the bundle through the **issue catalog** — most real problems map to a
   `WCPG-*` ID with a canned fix.
3. If it's a known ID, send the canned fix from the catalog. Done.
4. If it's *not* a known ID, fix it — and **add a new catalog entry** as part
   of the fix. That's how the plugin gets smarter over time.

---

## 1. Severity → Action SLA

The issue catalog (`secure_plugin/support/class-issue-catalog.php`) tags every
known issue with one of four severities. Use this table to decide how fast to
respond and who needs to be involved.

| Severity   | Constant       | Response time           | Who acts                       | Examples |
|------------|----------------|-------------------------|--------------------------------|----------|
| `critical` | `SEV_CRITICAL` | **Page on-call < 1h**   | On-call engineer + support     | Payments fully broken, encryption key invalid, gateway hard-down |
| `error`    | `SEV_ERROR`    | Same business day       | Support, escalate if unclear   | Postback error rate >20%, HMAC failures, LiteSpeed caching REST |
| `warning`  | `SEV_WARNING`  | Within 3 business days  | Support (batch weekly)         | Missing webhook secret, max ticket > daily limit, old WP version |
| `info`     | `SEV_INFO`     | No reply needed         | Aggregate for metrics only     | Outbound IP unknown |

Rules of thumb:

- **Critical** events should never be batched. If you see one, page.
- **Error** events get same-day acknowledgement even if the fix takes longer.
- **Warning** events can be batched into a weekly digest reply.
- **Info** events should never generate a ticket — they're telemetry.

---

## 2. How to Get a Diagnostic Bundle from a Merchant

### Option A — Auto-upload (preferred)

If the merchant has auto-upload enabled (`WCPG_Auto_Uploader`), every fatal
and major event already ships a bundle to our intake. Ask the merchant for
their **site URL or instance token** and look up recent uploads.

### Option B — Manual download

Send the merchant these instructions verbatim:

> 1. Log in to your WordPress admin.
> 2. Go to **WooCommerce → Digipay Support**.
> 3. Click **Generate Diagnostic Report** and download the file.
> 4. Reply to this email with the file attached.

The bundle is a redacted JSON snapshot — secrets, tokens, and PII have already
been scrubbed by `WCPG_Context_Bundler`. It is safe to store in tickets.

### Option C — Live `wp digipay doctor`

If the merchant has shell/WP-CLI access, ask them to run:

```bash
wp digipay doctor
```

This runs the issue catalog locally and prints any matched `WCPG-*` IDs with
their fix instructions. For machine-readable output:

```bash
wp digipay doctor --format=json
wp digipay doctor --severity=error
```

The command exits non-zero if any `error` or `critical` issue is detected,
making it safe to wire into the merchant's monitoring.

---

## 3. How to Read a Bundle

A bundle is a structured array. Don't read it raw — pipe it through one of:

- **`wp digipay doctor`** (locally on the merchant's site, fastest)
- **`WCPG_Issue_Catalog::detect_all($bundle)`** (programmatically)
- **`WCPG_Report_Renderer`** (renders the bundle as human-readable markdown)

The output you care about is the list of matched **issue IDs**. Each one
points to a stable entry in the catalog with `title`, `plain_english`, `fix`,
and `severity`. The `plain_english` and `fix` fields are written for the
merchant — you can paste them into a reply with no rewriting needed.

If `detect_all` returns `[]`, the catalog has nothing to say. That means
either (a) it's a brand-new issue not yet catalogued, or (b) the bundle is
healthy and you should ask the merchant for more information about what they
actually saw.

---

## 4. Adding a New Catalog Entry (the recursive loop)

**This is the most important section of this document.** Every time we fix a
support issue that wasn't already in the catalog, we add it. This is how the
plugin gets smarter for the next merchant who hits the same problem.

### When to add an entry

Add a catalog entry when **all** of these are true:

- A merchant hit a real issue.
- The issue is detectable from a diagnostic bundle (config, logs, version,
  environment, stats — anything `WCPG_Context_Bundler` collects).
- The issue could plausibly happen to another merchant.

If the issue is a one-off (e.g. the merchant manually deleted a database row)
**do not** add an entry. Note it in the ticket and move on.

### How to add an entry

1. Pick an ID. Use the existing prefix scheme:

   | Prefix | Domain |
   |--------|--------|
   | `P`    | Postback (credit card) |
   | `W`    | Webhook (e-transfer)   |
   | `E`    | E-Transfer config      |
   | `C`    | Crypto                 |
   | `S`    | Settings / limits      |
   | `X`    | Environment / cross-cutting |

   Increment the next number in that bucket. Never reuse an ID.

2. Add the entry to `secure_plugin/support/class-issue-catalog.php` inside
   `built_in_issues()`. Required fields:

   ```php
   array(
       'id'            => 'WCPG-X-006',
       'title'         => 'Short technical title',
       'plain_english' => 'What the merchant should understand.',
       'fix'           => 'Exactly what the merchant should do.',
       'severity'      => self::SEV_WARNING,  // info | warning | error | critical
       'config_only'   => false,              // true if detectable from gateway settings alone
       'detector'      => static function ( array $bundle ) {
           // return true when this issue is present
       },
   ),
   ```

3. Optional fields — **use these whenever possible**:

   - `'introduced_in' => '13.4.0'` — first plugin version that exhibited the issue.
   - `'fixed_in'      => '13.5.1'` — version where the root cause was removed.
     Once set, the catalog auto-suppresses this entry on installs running the
     fixed version or later. **You must set `fixed_in` whenever a code change
     eliminates the issue.**
   - `'related_pr'    => 'digipay/plugin#142'` — PR that introduced the fix.

4. Add **at least two tests** in `tests/IssueCatalogTest.php`:

   - One positive: build a bundle that should match, assert the ID is in
     `detect_all()` output.
   - One negative is implicitly covered by `test_detect_all_returns_empty_for_clean_bundle`,
     but if your detector has tricky thresholds (e.g. ratio-based), add an
     explicit "just under threshold" negative test.

5. Run `composer test` from `secure_plugin/`. All issue catalog tests must
   pass.

6. Open a PR. The PR template will require you to confirm the catalog was
   updated.

### The "every fix needs an artifact" rule

When you close a support ticket by shipping a code fix, the PR **must** do
one of the following:

| Type of fix              | Required artifact |
|--------------------------|-------------------|
| Config mistake           | New catalog entry with `config_only => true` |
| Edge-case bug            | New PHPUnit test that would have caught it (and a catalog entry if detectable) |
| Unclear error message    | Better error text **and** catalog entry mapping the old text to the new fix |
| Environment incompat     | Version check in `is_available()` or activation hook **and** catalog entry |
| Recurring question       | Catalog entry (severity `info` or `warning`) plus FAQ entry |

If your fix genuinely cannot produce a catalog entry (e.g. pure refactor with
no merchant-visible behavior change), document that in the PR description
with the line:

```
no-catalog-entry: <reason>
```

The CI catalog-check workflow looks for either a touched
`class-issue-catalog.php` / `IssueCatalogTest.php` or this opt-out line.

---

## 5. Escalation Criteria

Escalate to engineering when **any** of these are true:

- **Critical severity** issue from a production merchant.
- The bundle shows signs of data corruption (orders in impossible states,
  duplicate transaction IDs, missing required postmeta).
- A new failure mode that doesn't fit any existing catalog ID **and** affects
  more than one merchant in the same week.
- A regression: an issue with a `fixed_in` version is firing on a site
  running that version or newer. (This is always a bug — the fix didn't take.)
- The merchant is reporting a security concern (suspected key leak, suspicious
  postback origin, signed update verification failure).

For everything else: send the canned fix from the catalog and close the ticket.

---

## 6. Quick Reference Commands

```bash
# Run the catalog against a live site (merchant or staging)
wp digipay doctor
wp digipay doctor --severity=error
wp digipay doctor --format=json

# Run the test suite locally
cd secure_plugin && composer test

# Run only catalog tests
cd secure_plugin && ./vendor/bin/phpunit tests/IssueCatalogTest.php
```

---

## 7. Where Things Live

| Thing | Path |
|-------|------|
| Issue catalog | `secure_plugin/support/class-issue-catalog.php` |
| Catalog tests | `secure_plugin/tests/IssueCatalogTest.php` |
| Bundle builder | `secure_plugin/support/class-context-bundler.php` |
| Bundle renderer (markdown) | `secure_plugin/support/class-report-renderer.php` |
| Auto-uploader | `secure_plugin/support/class-auto-uploader.php` |
| Admin support page | `secure_plugin/support/class-support-admin-page.php` |
| Admin issue notices | `secure_plugin/support/class-gateway-issue-notices.php` |
| WP-CLI command | `secure_plugin/class-cli.php` |
| This runbook | `secure_plugin/docs/support-runbook.md` |

---

## 8. Telemetry / Bundle Ingestion (Supabase)

When a merchant has auto-upload enabled, `WCPG_Auto_Uploader` POSTs the
diagnostic bundle plus the locally-detected issue list to a Supabase edge
function. The dashboard reads from those tables to surface trends.

### Components

| Where | Thing |
|-------|-------|
| Plugin | `WCPG_Auto_Uploader::DEFAULT_INGEST_URL` (points to the edge function) |
| Edge function | `digipay-dashboard/supabase/functions/plugin-bundle-ingest/index.ts` |
| Tables | `plugin_bundle_uploads`, `plugin_diagnostic_bundles`, `plugin_issue_detections` |
| Views | `plugin_top_issues_7d`, `plugin_issues_by_version` |
| Migration | `digipay-dashboard/supabase/migrations/20260408_add_plugin_bundle_ingestion.sql` |

### Required configuration

**On the Supabase project** (`hzdybwclwqkcobpwxzoo`):

```bash
# 1. Apply the migration
cd ~/Documents/GitHub/digipay-dashboard
supabase db push

# 2. Set the shared HMAC secret used by the edge function
supabase secrets set DIGIPAY_INGEST_SHARED_SECRET="<long-random-hex>"

# 3. Deploy the function
supabase functions deploy plugin-bundle-ingest --no-verify-jwt
```

**On every plugin install that should send telemetry**, add to `wp-config.php`:

```php
define( 'WCPG_SUPPORT_INGEST_SECRET', '<same-long-random-hex>' );
```

If `WCPG_SUPPORT_INGEST_SECRET` is not defined the auto-uploader falls back
to its per-site secret — the edge function will reject those requests as
`invalid_signature`. The shared-secret model is intentional: it gives us a
single rotation point and keeps the edge function stateless. Per-site
enrollment is a future enhancement; for now, the shared secret is fine for
telemetry whose only sensitivity is "did the upload come from one of our
plugin installs".

### Bypass / opt-out

A merchant can override the destination by setting either:

- the `wcpg_support_ingest_url` option (highest priority), or
- the `WCPG_SUPPORT_INGEST_URL` constant in wp-config.php.

If neither is set, the plugin uses `DEFAULT_INGEST_URL`.

A merchant can disable uploads entirely by un-checking auto-upload on the
**WooCommerce → Digipay Support** page (option: `wcpg_support_autoupload_enabled`).

### Useful dashboard queries

```sql
-- Top issues firing in the last 7 days
select * from plugin_top_issues_7d;

-- Regression check: issues firing on a plugin version where they should be fixed.
-- (Replace 'WCPG-W-001' / '13.5.0' with the catalog entry's id and fixed_in.)
select plugin_version, detection_count, affected_sites, last_seen
from plugin_issues_by_version
where issue_id = 'WCPG-W-001'
  and plugin_version >= '13.5.0';

-- Sites that uploaded in the last 24h with at least one error/critical detection
select u.site_id, u.site_url, u.plugin_version, count(*) as error_count
from plugin_bundle_uploads u
join plugin_issue_detections d on d.upload_id = u.id
where u.received_at >= now() - interval '24 hours'
  and d.severity in ('error','critical')
group by u.site_id, u.site_url, u.plugin_version
order by error_count desc;

-- Pull the full bundle for a specific upload (when triaging)
select bundle from plugin_diagnostic_bundles where upload_id = 12345;
```

### Release regression alerting

After every release, run this once a day for 72 hours (cron in
`digipay-dashboard/supabase/migrations/` style or a simple Slack webhook):

```sql
-- Returns rows when a "supposedly fixed" issue is firing on the new version.
-- The dashboard layer joins this against the catalog's fixed_in metadata.
select issue_id, plugin_version, detection_count, affected_sites
from plugin_issues_by_version
where last_seen >= now() - interval '24 hours';
```

If the issue ID has a `fixed_in` ≤ `plugin_version`, it's a regression →
page on-call.

---

## 9. Ticket Tracking

All merchant-visible support issues, engineering escalations, catalog
entry requests, and regressions are tracked in a **private GitHub repo**:

**`9hnydkjf26-max/digipay-support`** — https://github.com/9hnydkjf26-max/digipay-support

This repo contains **no code**. Fixes are PRs in the (public) plugin repo
`9hnydkjf26-max/digipay-plugin` that close support tickets via cross-repo
references.

### When to open what

| Situation | Template | Auto-labels |
|---|---|---|
| New merchant report | **Support Incident** | `support`, `needs:bundle` |
| Critical / money stuck / regression / security | **Engineering Escalation** | `eng-escalation`, `sev:critical` |
| New failure mode to add to catalog | **New Catalog Entry** | `catalog` |
| A `fixed_in` issue fired on a supposedly fixed version | **Regression** | `regression`, `sev:error`, `eng-escalation` |

Every template is a GitHub issue form — required fields are enforced by
the form itself, so tickets cannot be submitted without a Supabase
`upload_id`, a merchant site URL, or the other critical metadata.

### Label taxonomy

```
Flow labels:    support, eng-escalation, catalog, regression
Severity:       sev:critical, sev:error, sev:warning, sev:info
Gateway:        gateway:cc, gateway:etransfer, gateway:crypto
State:          needs:bundle, needs:reply, blocked, stale
```

Multiple labels stack. A typical triaged-and-replied ticket:
`support + sev:error + gateway:etransfer + needs:reply`.

### Closing a ticket

Every ticket closes via a **PR in `9hnydkjf26-max/digipay-plugin`** with a
cross-repo close reference in the PR body or a commit message:

```
Closes 9hnydkjf26-max/digipay-support#42
```

That PR **must also** touch `class-issue-catalog.php` or include a
`no-catalog-entry: <reason>` line in the PR body — the CI catalog-check
workflow will fail the merge otherwise. This is the enforcement mechanism
for the "every fix needs an artifact" rule in §4.

### On-call

The sole on-call contact for `sev:critical` escalations is
**@9hnydkjf26-max**. The Engineering Escalation template auto-assigns
this user. Support-level tickets (`sev:error` / `sev:warning`) have no
automatic assignee — team members claim them manually.

### Slack notifications

The official GitHub Slack app is connected to the repo. Subscribe to
ticket activity by running this once in your Slack workspace, in the
channel you want notifications to land (e.g. `#digipay-support`):

```
/github subscribe 9hnydkjf26-max/digipay-support issues
/github subscribe 9hnydkjf26-max/digipay-support issues comments
```

For just the on-call (`#digipay-oncall`):

```
/github subscribe 9hnydkjf26-max/digipay-support issues +label:"sev:critical"
```

### Where tickets live vs. where code lives

```
digipay-support  (private, issues-only)      ←── support team primarily here
      │
      │ Closes owner/repo#N
      ▼
digipay-plugin   (public, code + PRs + CI)   ←── engineering primarily here
      │
      │ class-issue-catalog.php updated
      ▼
Next merchant hitting the same issue is auto-detected
      └── loop closes
```

Both repos are `9hnydkjf26-max/*`. Cross-repo close references only work
within the same owner (GitHub limitation), so do not move either repo to
a different owner without updating this section.
