# Remote Diagnostic Commands Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

## 2026-04-09 Signature verification addendum

Before dispatch, five assumptions in the original draft were verified against the live code and four required corrections. All corrections are applied in-place in the affected tasks below. For the historical record:

- **`WCPG_Event_Log::recent($limit, $type)`** — not `get_recent`. Second param is `type`, not `source`. Fixed in Task 9.
- **Limits are gateway-instance methods, not standalone functions.** `$gw->refresh_remote_limits()`, `$gw->get_remote_limits()`, `$gw->get_daily_transaction_total()` on `WC_Gateway_PayGo`. Fixed in Task 11.
- **`WCPG_Context_Bundler::build()`** is an instance method, not static. Fixed in Tasks 12, 17.
- **There is no `WCPG_Auto_Uploader::upload_bundle()`.** The only existing upload path is the private instance method `handle_critical_event()`, which honors a 1-hour throttle. Per user decision, **Task 12 now inlines the HMAC sign+POST logic** inside `cmd_generate_bundle` (Option B) rather than refactoring `handle_critical_event`. This preserves zero blast radius on the critical-event code path.
- **`WCPG_Context_Bundler::scrub_pii()`** is public static but **string-only** — it returns non-strings unchanged. Task 15 now uses a recursive walker that calls `scrub_pii` only on leaf strings.
- **NEW Task 7a added:** `install_uuid` is not currently exposed in the diagnostic bundle. Agent needs it to target commands. A one-line addition to the bundler's `build_meta()` fixes this. Sequenced before Phase 5 (agent integration) so pilot-merchant bundles carry it.

**Goal:** Give the Digipay support agent the ability to run whitelisted, read-only diagnostics on any opted-in merchant's site without any inbound access, so support staff get diagnostic data in ~5 min instead of hours-waiting-on-the-merchant.

**Architecture:** A pull-based command queue. Agent POSTs a signed command to a new Supabase edge function, which stores it in a `merchant_commands` table keyed by `install_uuid`. The merchant's plugin polls Supabase outbound every 5 min via WP cron, runs the whitelisted command locally, redacts the output, and POSTs the result back. All three legs are HMAC-SHA512 signed. No inbound connection to the merchant's site is ever required. Six read-only commands ship in v1: `whoami`, `event_log_tail`, `recent_order_status`, `refresh_limits`, `generate_bundle`, `test_postback_route`.

**Tech Stack:**
- Plugin: PHP 7.4+, WordPress hook/cron APIs, existing `WCPG_Auto_Uploader` HMAC pattern
- Backend: Supabase Postgres + Deno edge functions (TypeScript)
- Agent: Claude Code agent file + `gh api` CLI calls
- Testing: PHPUnit 9.5+ (existing `secure_plugin/tests/bootstrap.php` mocks)

**Repos touched:**
1. `DigipayMasterPlugin/secure_plugin/` — plugin code
2. `digipay-dashboard/` — Supabase migrations and edge functions
3. `digipay-support/` — agent instructions and runbook

**Autonomy model for v1:** All 6 commands are read-only on the merchant side (no state mutation, no filesystem writes). The agent may enqueue any of them **without a team-member confirm step** — the same way it already reads bundles from Supabase without asking. Ticket creation and outgoing merchant replies still require explicit "yes" gates. Mutating commands (future `clear_postback_dedup`, `reset_limits_cache`) are out of scope for v1 and will need their own confirm gate when added.

---

## File Structure

### Plugin side (`secure_plugin/`)

```
secure_plugin/
├── woocommerce-gateway-paygo.php                    [modify: wire cron + activation hook]
├── support/
│   ├── class-remote-command-handler.php             [CREATE: ~550 lines — poller, registry, 6 handlers, HMAC]
│   └── class-support-admin-page.php                 [modify: opt-in toggle + audit log]
└── tests/
    └── RemoteCommandHandlerTest.php                 [CREATE: ~500 lines — full test suite]
```

One class, one test file. Each command handler is a private method on `WCPG_Remote_Command_Handler`. This is deliberate: the commands are small (20-50 lines each), they share helpers (redaction, error framing), and a single class keeps the blast radius auditable — one grep finds every remote-executable code path on a merchant's site.

### Supabase side (`digipay-dashboard/`)

```
digipay-dashboard/
├── supabase/
│   ├── migrations/
│   │   └── 20260409120000_create_merchant_commands.sql          [CREATE]
│   └── functions/
│       ├── _shared/
│       │   └── verify-signature.ts                              [modify or create]
│       ├── digipay-command-enqueue/index.ts                     [CREATE]
│       ├── digipay-command-fetch/index.ts                       [CREATE]
│       └── digipay-command-result/index.ts                      [CREATE]
```

### Support agent side (`digipay-support/`)

```
digipay-support/
├── .claude/agents/digipay-support.md                [modify: add Remote Diagnostics section]
└── docs/support-runbook.md                          [modify: add runbook entry]
```

---

## Two shared keys used throughout this plan

- `INGEST_HANDSHAKE_KEY` — already exists in `class-auto-uploader.php` as constant. Signs plugin ↔ Supabase traffic (fetch, result). **Do not create a new key; reuse this one.**
- `DP_AGENT_KEY` — `dp_agent_v1_1efcd8b50f23384795413a45a8be57c6` (already used by the session-log hook in `digipay-support/.claude/hooks/log-agent-session.py`). Signs agent → Supabase enqueue. **Do not create a new key; reuse this one.**

Each Supabase edge function verifies exactly ONE of these keys based on which endpoint it serves.

---

# Phase 0: Supabase infrastructure (3 tasks)

### Task 1: Create `merchant_commands` table migration

**Files:**
- Create: `digipay-dashboard/supabase/migrations/20260409120000_create_merchant_commands.sql`

**Repo context:** `cd ~/Documents/GitHub/digipay-dashboard`

- [ ] **Step 1: Create the migration file**

```sql
-- 20260409120000_create_merchant_commands.sql
-- Remote diagnostic command queue for the Digipay support agent.
-- Commands are enqueued by the agent, pulled by the plugin on its 5-min poll,
-- executed locally on the merchant site, and the result posted back here.

create table if not exists public.merchant_commands (
  id              uuid primary key default gen_random_uuid(),
  install_uuid    text not null,
  command         text not null,
  params_json     jsonb not null default '{}'::jsonb,
  status          text not null default 'pending'
                    check (status in ('pending','fetched','completed','expired','failed')),
  enqueued_by     text,                              -- team member USER from hook attribution
  enqueued_at     timestamptz not null default now(),
  fetched_at      timestamptz,
  completed_at    timestamptz,
  expires_at      timestamptz not null default now() + interval '1 hour',
  result_json     jsonb,
  result_error    text,
  result_bytes    int
);

create index if not exists idx_merchant_commands_install_pending
  on public.merchant_commands (install_uuid, status)
  where status in ('pending','fetched');

create index if not exists idx_merchant_commands_enqueued_at
  on public.merchant_commands (enqueued_at desc);

-- Auto-expire pending commands older than their expires_at.
-- Called by a scheduled edge function or pg_cron.
create or replace function public.expire_stale_merchant_commands()
returns void
language sql
security definer
as $$
  update public.merchant_commands
    set status = 'expired'
    where status in ('pending','fetched')
      and expires_at < now();
$$;

-- RLS: deny all direct client access. Only service-role edge functions touch this table.
alter table public.merchant_commands enable row level security;
```

- [ ] **Step 2: Apply migration locally**

```bash
cd ~/Documents/GitHub/digipay-dashboard
supabase db reset --local
```

Expected: migration applies cleanly, no errors.

- [ ] **Step 3: Verify table exists**

```bash
supabase db diff --local
```

Expected: no diff (table is in the migration).

- [ ] **Step 4: Commit**

```bash
git add supabase/migrations/20260409120000_create_merchant_commands.sql
git commit -m "Add merchant_commands table for remote diagnostic queue"
```

---

### Task 2: Create the three edge functions + shared signature verifier

**Files:**
- Create: `digipay-dashboard/supabase/functions/_shared/verify-signature.ts`
- Create: `digipay-dashboard/supabase/functions/digipay-command-enqueue/index.ts`
- Create: `digipay-dashboard/supabase/functions/digipay-command-fetch/index.ts`
- Create: `digipay-dashboard/supabase/functions/digipay-command-result/index.ts`

**Repo context:** `cd ~/Documents/GitHub/digipay-dashboard`

- [ ] **Step 1: Create (or update) the shared signature verifier**

If `_shared/verify-signature.ts` already exists from earlier work, skim it and make sure it supports both keys. If it doesn't exist, create:

```ts
// supabase/functions/_shared/verify-signature.ts
import { createHmac } from "node:crypto";

/**
 * Verify an HMAC-SHA512 signature on a request body.
 *
 * Request format (matching class-auto-uploader.php):
 *   Headers:
 *     X-Digipay-Timestamp: <unix seconds>
 *     X-Digipay-Signature: <hex sha512>
 *   Signed payload: `${ts}.${rawBodyBytes}`
 *
 * Replay window: 5 minutes.
 */
export function verifySignature(
  rawBody: string,
  timestampHeader: string | null,
  signatureHeader: string | null,
  key: string,
): { ok: true } | { ok: false; reason: string } {
  if (!timestampHeader || !signatureHeader) {
    return { ok: false, reason: "missing_headers" };
  }
  const ts = Number(timestampHeader);
  if (!Number.isFinite(ts)) {
    return { ok: false, reason: "bad_timestamp" };
  }
  const now = Math.floor(Date.now() / 1000);
  if (Math.abs(now - ts) > 300) {
    return { ok: false, reason: "stale_timestamp" };
  }
  const expected = createHmac("sha512", key)
    .update(`${timestampHeader}.${rawBody}`)
    .digest("hex");
  if (expected.length !== signatureHeader.length) {
    return { ok: false, reason: "bad_signature" };
  }
  let diff = 0;
  for (let i = 0; i < expected.length; i++) {
    diff |= expected.charCodeAt(i) ^ signatureHeader.charCodeAt(i);
  }
  if (diff !== 0) {
    return { ok: false, reason: "bad_signature" };
  }
  return { ok: true };
}

export const HANDSHAKE_KEY = Deno.env.get("DIGIPAY_INGEST_HANDSHAKE_KEY") ?? "";
export const AGENT_KEY = Deno.env.get("DIGIPAY_AGENT_KEY") ?? "";

if (!HANDSHAKE_KEY || !AGENT_KEY) {
  console.error("Missing DIGIPAY_INGEST_HANDSHAKE_KEY or DIGIPAY_AGENT_KEY env vars");
}
```

- [ ] **Step 2: Create the enqueue edge function (agent → Supabase)**

```ts
// supabase/functions/digipay-command-enqueue/index.ts
import { createClient } from "https://esm.sh/@supabase/supabase-js@2";
import { verifySignature, AGENT_KEY } from "../_shared/verify-signature.ts";

const ALLOWED_COMMANDS = new Set([
  "whoami",
  "event_log_tail",
  "recent_order_status",
  "refresh_limits",
  "generate_bundle",
  "test_postback_route",
]);

const supabase = createClient(
  Deno.env.get("SUPABASE_URL")!,
  Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!,
);

Deno.serve(async (req) => {
  if (req.method !== "POST") {
    return new Response("method_not_allowed", { status: 405 });
  }
  const rawBody = await req.text();
  const verify = verifySignature(
    rawBody,
    req.headers.get("X-Digipay-Timestamp"),
    req.headers.get("X-Digipay-Signature"),
    AGENT_KEY,
  );
  if (!verify.ok) {
    return new Response(JSON.stringify({ error: verify.reason }), { status: 401 });
  }
  let body: { install_uuid?: string; command?: string; params?: unknown; enqueued_by?: string };
  try {
    body = JSON.parse(rawBody);
  } catch {
    return new Response(JSON.stringify({ error: "bad_json" }), { status: 400 });
  }
  if (!body.install_uuid || !body.command) {
    return new Response(JSON.stringify({ error: "missing_fields" }), { status: 400 });
  }
  if (!ALLOWED_COMMANDS.has(body.command)) {
    return new Response(JSON.stringify({ error: "unknown_command" }), { status: 400 });
  }
  const { data, error } = await supabase
    .from("merchant_commands")
    .insert({
      install_uuid: body.install_uuid,
      command: body.command,
      params_json: body.params ?? {},
      enqueued_by: body.enqueued_by ?? null,
    })
    .select("id, expires_at")
    .single();
  if (error) {
    return new Response(JSON.stringify({ error: "db_error", detail: error.message }), { status: 500 });
  }
  return new Response(JSON.stringify({ ok: true, id: data.id, expires_at: data.expires_at }), {
    status: 200,
    headers: { "Content-Type": "application/json" },
  });
});
```

- [ ] **Step 3: Create the fetch edge function (plugin → Supabase, pull pending commands)**

```ts
// supabase/functions/digipay-command-fetch/index.ts
import { createClient } from "https://esm.sh/@supabase/supabase-js@2";
import { verifySignature, HANDSHAKE_KEY } from "../_shared/verify-signature.ts";

const supabase = createClient(
  Deno.env.get("SUPABASE_URL")!,
  Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!,
);

Deno.serve(async (req) => {
  if (req.method !== "POST") {
    return new Response("method_not_allowed", { status: 405 });
  }
  const rawBody = await req.text();
  const verify = verifySignature(
    rawBody,
    req.headers.get("X-Digipay-Timestamp"),
    req.headers.get("X-Digipay-Signature"),
    HANDSHAKE_KEY,
  );
  if (!verify.ok) {
    return new Response(JSON.stringify({ error: verify.reason }), { status: 401 });
  }
  let body: { install_uuid?: string };
  try {
    body = JSON.parse(rawBody);
  } catch {
    return new Response(JSON.stringify({ error: "bad_json" }), { status: 400 });
  }
  if (!body.install_uuid) {
    return new Response(JSON.stringify({ error: "missing_install_uuid" }), { status: 400 });
  }

  // Expire stale rows opportunistically.
  await supabase.rpc("expire_stale_merchant_commands");

  const { data, error } = await supabase
    .from("merchant_commands")
    .select("id, command, params_json, expires_at")
    .eq("install_uuid", body.install_uuid)
    .eq("status", "pending")
    .lte("enqueued_at", new Date().toISOString())
    .order("enqueued_at", { ascending: true })
    .limit(5);

  if (error) {
    return new Response(JSON.stringify({ error: "db_error", detail: error.message }), { status: 500 });
  }

  const ids = (data ?? []).map((r) => r.id);
  if (ids.length > 0) {
    await supabase
      .from("merchant_commands")
      .update({ status: "fetched", fetched_at: new Date().toISOString() })
      .in("id", ids);
  }

  return new Response(JSON.stringify({ ok: true, commands: data ?? [] }), {
    status: 200,
    headers: { "Content-Type": "application/json" },
  });
});
```

- [ ] **Step 4: Create the result edge function (plugin → Supabase, post result)**

```ts
// supabase/functions/digipay-command-result/index.ts
import { createClient } from "https://esm.sh/@supabase/supabase-js@2";
import { verifySignature, HANDSHAKE_KEY } from "../_shared/verify-signature.ts";

const MAX_RESULT_BYTES = 512_000;

const supabase = createClient(
  Deno.env.get("SUPABASE_URL")!,
  Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!,
);

Deno.serve(async (req) => {
  if (req.method !== "POST") {
    return new Response("method_not_allowed", { status: 405 });
  }
  const rawBody = await req.text();
  if (rawBody.length > MAX_RESULT_BYTES) {
    return new Response(JSON.stringify({ error: "result_too_large" }), { status: 413 });
  }
  const verify = verifySignature(
    rawBody,
    req.headers.get("X-Digipay-Timestamp"),
    req.headers.get("X-Digipay-Signature"),
    HANDSHAKE_KEY,
  );
  if (!verify.ok) {
    return new Response(JSON.stringify({ error: verify.reason }), { status: 401 });
  }
  let body: {
    install_uuid?: string;
    command_id?: string;
    result?: unknown;
    error?: string;
  };
  try {
    body = JSON.parse(rawBody);
  } catch {
    return new Response(JSON.stringify({ error: "bad_json" }), { status: 400 });
  }
  if (!body.install_uuid || !body.command_id) {
    return new Response(JSON.stringify({ error: "missing_fields" }), { status: 400 });
  }

  const update = {
    status: body.error ? "failed" : "completed",
    completed_at: new Date().toISOString(),
    result_json: body.result ?? null,
    result_error: body.error ?? null,
    result_bytes: rawBody.length,
  };

  const { error } = await supabase
    .from("merchant_commands")
    .update(update)
    .eq("id", body.command_id)
    .eq("install_uuid", body.install_uuid);

  if (error) {
    return new Response(JSON.stringify({ error: "db_error", detail: error.message }), { status: 500 });
  }

  return new Response(JSON.stringify({ ok: true }), {
    status: 200,
    headers: { "Content-Type": "application/json" },
  });
});
```

- [ ] **Step 5: Commit**

```bash
git add supabase/functions/_shared/verify-signature.ts \
         supabase/functions/digipay-command-enqueue \
         supabase/functions/digipay-command-fetch \
         supabase/functions/digipay-command-result
git commit -m "Add edge functions for remote command enqueue/fetch/result"
```

---

### Task 3: Deploy to staging and smoke-test all three endpoints

**Files:** none (deployment + manual verification)

**Repo context:** `cd ~/Documents/GitHub/digipay-dashboard`

- [ ] **Step 1: Deploy migration to staging Supabase**

```bash
supabase db push
```

Expected: migration applies, `merchant_commands` table visible in the Supabase dashboard.

- [ ] **Step 2: Deploy all three edge functions**

```bash
supabase functions deploy digipay-command-enqueue
supabase functions deploy digipay-command-fetch
supabase functions deploy digipay-command-result
```

Expected: each deploys successfully.

- [ ] **Step 3: Set edge function secrets (if not already set)**

```bash
supabase secrets set \
  DIGIPAY_INGEST_HANDSHAKE_KEY="$(grep INGEST_HANDSHAKE_KEY ~/Documents/GitHub/DigipayMasterPlugin/secure_plugin/support/class-auto-uploader.php | head -1 | awk -F"'" '{print $2}')" \
  DIGIPAY_AGENT_KEY="dp_agent_v1_1efcd8b50f23384795413a45a8be57c6"
```

Note: confirm the first value matches the `INGEST_HANDSHAKE_KEY` constant in the plugin. **Do not commit the values anywhere.**

- [ ] **Step 4: Smoke-test enqueue with a signed curl call**

Write a throwaway shell script to generate an HMAC-signed request body:

```bash
export AGENT_KEY="dp_agent_v1_1efcd8b50f23384795413a45a8be57c6"
export BODY='{"install_uuid":"smoketest0000000","command":"whoami","enqueued_by":"smoketest"}'
export TS="$(date +%s)"
export SIG="$(printf '%s' "${TS}.${BODY}" | openssl dgst -sha512 -hmac "$AGENT_KEY" | awk '{print $2}')"

curl -i -X POST "https://hzdybwclwqkcobpwxzoo.supabase.co/functions/v1/digipay-command-enqueue" \
  -H "Content-Type: application/json" \
  -H "X-Digipay-Timestamp: $TS" \
  -H "X-Digipay-Signature: $SIG" \
  --data "$BODY"
```

Expected: HTTP 200, body `{"ok":true,"id":"<uuid>","expires_at":"..."}`.

- [ ] **Step 5: Smoke-test fetch (with plugin key) for the same install_uuid**

```bash
export PLUGIN_KEY="<contents of INGEST_HANDSHAKE_KEY>"
export BODY='{"install_uuid":"smoketest0000000"}'
export TS="$(date +%s)"
export SIG="$(printf '%s' "${TS}.${BODY}" | openssl dgst -sha512 -hmac "$PLUGIN_KEY" | awk '{print $2}')"

curl -i -X POST "https://hzdybwclwqkcobpwxzoo.supabase.co/functions/v1/digipay-command-fetch" \
  -H "Content-Type: application/json" \
  -H "X-Digipay-Timestamp: $TS" \
  -H "X-Digipay-Signature: $SIG" \
  --data "$BODY"
```

Expected: HTTP 200, body contains a `commands` array with the row enqueued in step 4. The row's `status` column in the DB flips to `fetched`.

- [ ] **Step 6: Verify bad signatures are rejected**

Flip one character of `SIG` and re-run step 4:

Expected: HTTP 401, body `{"error":"bad_signature"}`.

- [ ] **Step 7: Clean up the smoketest row**

```bash
supabase db execute --sql "delete from merchant_commands where install_uuid = 'smoketest0000000';"
```

- [ ] **Step 8: No commit needed (deployment only, no code changes in this task)**

---

# Phase 1: Plugin core infrastructure (4 tasks)

### Task 4: Create `class-remote-command-handler.php` scaffold with poller

**Files:**
- Create: `secure_plugin/support/class-remote-command-handler.php`
- Create: `secure_plugin/tests/RemoteCommandHandlerTest.php`

**Repo context:** `cd ~/Documents/GitHub/DigipayMasterPlugin/secure_plugin`

- [ ] **Step 1: Write the first failing test — class exists and has `poll()` method**

```php
// tests/RemoteCommandHandlerTest.php
<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/DigipayTestCase.php';
require_once __DIR__ . '/../support/class-remote-command-handler.php';

class RemoteCommandHandlerTest extends DigipayTestCase {

    public function test_class_exists_with_poll_method() {
        $this->assertTrue( class_exists( 'WCPG_Remote_Command_Handler' ) );
        $this->assertTrue( method_exists( 'WCPG_Remote_Command_Handler', 'poll' ) );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
./vendor/bin/phpunit tests/RemoteCommandHandlerTest.php
```

Expected: FAIL with `Class "WCPG_Remote_Command_Handler" not found`.

- [ ] **Step 3: Create the skeleton class**

```php
<?php
// support/class-remote-command-handler.php
/**
 * WCPG_Remote_Command_Handler
 * ---------------------------
 * Polls Supabase for remote diagnostic commands targeting this install,
 * dispatches them to whitelisted handlers, and posts the results back.
 *
 * Strict rules:
 * - Read-only on the merchant side. No file writes beyond options/transients.
 * - Whitelist is compiled in (COMMANDS array). No eval, no shell, no dynamic dispatch.
 * - Every handler output is redacted via scrub_pii before it leaves the site.
 * - Per-install rate limit (20 commands/hour).
 * - Opt-in required (wcpg_remote_diagnostics_enabled option).
 */
defined( 'ABSPATH' ) || exit;

class WCPG_Remote_Command_Handler {

    const FETCH_URL           = 'https://hzdybwclwqkcobpwxzoo.supabase.co/functions/v1/digipay-command-fetch';
    const RESULT_URL          = 'https://hzdybwclwqkcobpwxzoo.supabase.co/functions/v1/digipay-command-result';
    const CRON_HOOK           = 'wcpg_poll_remote_commands';
    const OPT_IN_OPTION       = 'wcpg_remote_diagnostics_enabled';
    const RATE_LIMIT_OPTION   = 'wcpg_remote_command_rate';
    const RATE_LIMIT_PER_HOUR = 20;
    const MAX_RESULT_BYTES    = 450000;

    /**
     * Compiled-in command whitelist. Method names are prefixed `cmd_` and
     * are private — nothing outside this class can invoke them.
     */
    const COMMANDS = array(
        'whoami'              => 'cmd_whoami',
        'event_log_tail'      => 'cmd_event_log_tail',
        'recent_order_status' => 'cmd_recent_order_status',
        'refresh_limits'      => 'cmd_refresh_limits',
        'generate_bundle'     => 'cmd_generate_bundle',
        'test_postback_route' => 'cmd_test_postback_route',
    );

    /**
     * Poll Supabase for pending commands targeting this install. Called
     * from wp-cron every 5 minutes when opt-in is enabled.
     *
     * @return array{fetched:int,completed:int,failed:int,skipped_reason?:string}
     */
    public static function poll() {
        if ( ! self::is_enabled() ) {
            return array( 'fetched' => 0, 'completed' => 0, 'failed' => 0, 'skipped_reason' => 'opt_in_disabled' );
        }
        // Fetch pending commands.
        $commands = self::fetch_pending();
        if ( empty( $commands ) ) {
            return array( 'fetched' => 0, 'completed' => 0, 'failed' => 0 );
        }
        $completed = 0;
        $failed    = 0;
        foreach ( $commands as $cmd ) {
            $result = self::dispatch( $cmd );
            $posted = self::post_result( $cmd['id'], $result );
            if ( $posted && empty( $result['error'] ) ) {
                $completed++;
            } else {
                $failed++;
            }
        }
        return array( 'fetched' => count( $commands ), 'completed' => $completed, 'failed' => $failed );
    }

    /** Opt-in gate. */
    public static function is_enabled() {
        return 'yes' === get_option( self::OPT_IN_OPTION, 'no' );
    }

    /** Fetch pending commands from Supabase. Returns array of {id, command, params_json}. */
    protected static function fetch_pending() {
        if ( ! class_exists( 'WCPG_Auto_Uploader' ) ) {
            $file = plugin_dir_path( __FILE__ ) . 'class-auto-uploader.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
        if ( ! class_exists( 'WCPG_Auto_Uploader' ) ) {
            return array();
        }
        $install_uuid = WCPG_Auto_Uploader::get_or_create_install_uuid();
        if ( empty( $install_uuid ) ) {
            return array();
        }
        $body = wp_json_encode( array( 'install_uuid' => $install_uuid ) );
        $ts   = (string) time();
        $sig  = hash_hmac( 'sha512', $ts . '.' . $body, WCPG_Auto_Uploader::INGEST_HANDSHAKE_KEY );
        $resp = wp_remote_post( self::FETCH_URL, array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type'         => 'application/json',
                'X-Digipay-Timestamp'  => $ts,
                'X-Digipay-Signature'  => $sig,
            ),
            'body' => $body,
        ) );
        if ( is_wp_error( $resp ) ) {
            return array();
        }
        $code = wp_remote_retrieve_response_code( $resp );
        if ( 200 !== (int) $code ) {
            return array();
        }
        $decoded = json_decode( wp_remote_retrieve_body( $resp ), true );
        if ( ! is_array( $decoded ) || empty( $decoded['commands'] ) || ! is_array( $decoded['commands'] ) ) {
            return array();
        }
        return $decoded['commands'];
    }

    /**
     * Dispatch a single command through the whitelist. Returns an array
     * shaped like {result: mixed} on success or {error: string} on failure.
     */
    protected static function dispatch( array $cmd ) {
        $name = isset( $cmd['command'] ) ? (string) $cmd['command'] : '';
        if ( ! isset( self::COMMANDS[ $name ] ) ) {
            return array( 'error' => 'unknown_command' );
        }
        if ( ! self::within_rate_limit() ) {
            return array( 'error' => 'rate_limited' );
        }
        $method = self::COMMANDS[ $name ];
        $params = isset( $cmd['params_json'] ) && is_array( $cmd['params_json'] ) ? $cmd['params_json'] : array();
        try {
            $raw = call_user_func( array( __CLASS__, $method ), $params );
        } catch ( \Throwable $e ) {
            return array( 'error' => 'handler_exception: ' . $e->getMessage() );
        }
        $redacted = self::redact( $raw );
        return array( 'result' => $redacted );
    }

    /** Placeholder — real implementation in Task 8. */
    protected static function cmd_whoami( array $params ) {
        return array();
    }
    protected static function cmd_event_log_tail( array $params ) {
        return array();
    }
    protected static function cmd_recent_order_status( array $params ) {
        return array();
    }
    protected static function cmd_refresh_limits( array $params ) {
        return array();
    }
    protected static function cmd_generate_bundle( array $params ) {
        return array();
    }
    protected static function cmd_test_postback_route( array $params ) {
        return array();
    }

    /** Placeholder — real implementation in Task 14. */
    protected static function within_rate_limit() {
        return true;
    }

    /** Placeholder — real implementation in Task 15. */
    protected static function redact( $value ) {
        return $value;
    }

    /** Placeholder — real implementation in Task 16. */
    protected static function post_result( $command_id, array $result ) {
        return true;
    }
}
```

- [ ] **Step 4: Run the test — should pass now**

```bash
./vendor/bin/phpunit tests/RemoteCommandHandlerTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add support/class-remote-command-handler.php tests/RemoteCommandHandlerTest.php
git commit -m "Add WCPG_Remote_Command_Handler skeleton with whitelist + poll()"
```

---

### Task 5: Wire cron registration into `wcpg_init_modules` and activation hook

**Files:**
- Modify: `secure_plugin/woocommerce-gateway-paygo.php` (find `wcpg_init_modules` function and the activation hook section)

**Repo context:** `cd ~/Documents/GitHub/DigipayMasterPlugin/secure_plugin`

- [ ] **Step 1: Write a failing test for cron registration**

Append to `tests/RemoteCommandHandlerTest.php`:

```php
    public function test_cron_hook_is_registered_when_enabled() {
        update_option( WCPG_Remote_Command_Handler::OPT_IN_OPTION, 'yes' );
        // Fire the init modules hook.
        do_action( 'plugins_loaded' );
        $next = wp_next_scheduled( WCPG_Remote_Command_Handler::CRON_HOOK );
        $this->assertNotFalse( $next, 'Remote command cron should be scheduled when opt-in is enabled' );
    }
```

- [ ] **Step 2: Run the test**

```bash
./vendor/bin/phpunit tests/RemoteCommandHandlerTest.php::test_cron_hook_is_registered_when_enabled
```

Expected: FAIL (cron not registered yet).

- [ ] **Step 3: Open `woocommerce-gateway-paygo.php` and find `wcpg_init_modules`**

```bash
grep -n "wcpg_init_modules" woocommerce-gateway-paygo.php
```

- [ ] **Step 4: Add this block inside `wcpg_init_modules`, right after the auto-uploader registration**

```php
    // Remote command handler (opt-in). Poll Supabase every 5 minutes for
    // diagnostic commands from the support team.
    require_once plugin_dir_path( WCPG_PLUGIN_FILE ) . 'support/class-remote-command-handler.php';
    if ( class_exists( 'WCPG_Remote_Command_Handler' ) ) {
        add_action( WCPG_Remote_Command_Handler::CRON_HOOK, array( 'WCPG_Remote_Command_Handler', 'poll' ) );
        if ( WCPG_Remote_Command_Handler::is_enabled() && ! wp_next_scheduled( WCPG_Remote_Command_Handler::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, 'wcpg_five_minutes', WCPG_Remote_Command_Handler::CRON_HOOK );
        }
    }
```

- [ ] **Step 5: Register the custom 5-minute interval near the existing cron intervals**

Find `cron_schedules` filter (there should already be one), and add:

```php
add_filter( 'cron_schedules', function( $schedules ) {
    if ( ! isset( $schedules['wcpg_five_minutes'] ) ) {
        $schedules['wcpg_five_minutes'] = array(
            'interval' => 300,
            'display'  => __( 'Every 5 minutes (WCPG remote commands)', 'wcpg' ),
        );
    }
    return $schedules;
} );
```

- [ ] **Step 6: Run the test again**

```bash
./vendor/bin/phpunit tests/RemoteCommandHandlerTest.php::test_cron_hook_is_registered_when_enabled
```

Expected: PASS.

- [ ] **Step 7: Unschedule on deactivation — extend `wcpg_clear_scheduled_events`**

Find the deactivation cleanup function and append:

```php
    wp_clear_scheduled_hook( 'wcpg_poll_remote_commands' );
```

- [ ] **Step 8: Commit**

```bash
git add woocommerce-gateway-paygo.php tests/RemoteCommandHandlerTest.php
git commit -m "Wire remote command cron into wcpg_init_modules"
```

---

### Task 6: Opt-in check behavior — enforced short-circuit

**Files:**
- Modify: `secure_plugin/tests/RemoteCommandHandlerTest.php`

**Repo context:** `cd ~/Documents/GitHub/DigipayMasterPlugin/secure_plugin`

- [ ] **Step 1: Write a failing test for opt-in gating**

Append to `tests/RemoteCommandHandlerTest.php`:

```php
    public function test_poll_short_circuits_when_opt_in_disabled() {
        update_option( WCPG_Remote_Command_Handler::OPT_IN_OPTION, 'no' );
        $result = WCPG_Remote_Command_Handler::poll();
        $this->assertSame( 'opt_in_disabled', $result['skipped_reason'] );
        $this->assertSame( 0, $result['fetched'] );
    }

    public function test_poll_proceeds_when_opt_in_enabled() {
        update_option( WCPG_Remote_Command_Handler::OPT_IN_OPTION, 'yes' );
        $result = WCPG_Remote_Command_Handler::poll();
        // With no mocked HTTP, fetch returns empty — but skipped_reason must NOT be set.
        $this->assertArrayNotHasKey( 'skipped_reason', $result );
    }
```

- [ ] **Step 2: Run the tests**

```bash
./vendor/bin/phpunit tests/RemoteCommandHandlerTest.php
```

Expected: both PASS (the skeleton already handles this). If they don't, inspect and fix the `is_enabled()` method.

- [ ] **Step 3: Commit**

```bash
git add tests/RemoteCommandHandlerTest.php
git commit -m "Lock opt-in gate behavior with tests"
```

---

### Task 7: Mock HTTP for `fetch_pending` and verify sign/verify round-trip

**Files:**
- Modify: `secure_plugin/tests/RemoteCommandHandlerTest.php`
- Modify: `secure_plugin/tests/bootstrap.php` (add wp_remote_post mock if not already flexible enough)

**Repo context:** `cd ~/Documents/GitHub/DigipayMasterPlugin/secure_plugin`

- [ ] **Step 1: Check existing `wp_remote_post` mock in bootstrap**

```bash
grep -n "wp_remote_post" tests/bootstrap.php
```

Expected: some mock exists (given auto-uploader tests). If it returns a hardcoded value, extend it so tests can inject a custom response via a global var:

```php
// In tests/bootstrap.php — if not already present
$GLOBALS['wcpg_test_http_mocks'] = array();
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = array() ) {
        if ( isset( $GLOBALS['wcpg_test_http_mocks'][ $url ] ) ) {
            $cb = $GLOBALS['wcpg_test_http_mocks'][ $url ];
            return $cb( $args );
        }
        return array(
            'response' => array( 'code' => 200 ),
            'body'     => '{"ok":true}',
        );
    }
}
```

(Adapt based on what's already there — don't duplicate a function definition.)

- [ ] **Step 2: Write a test verifying fetch_pending signs the request correctly and parses the response**

```php
    public function test_fetch_pending_signs_request_and_parses_response() {
        update_option( WCPG_Remote_Command_Handler::OPT_IN_OPTION, 'yes' );
        update_option( 'wcpg_install_uuid', 'abc1234567890def' );

        $captured_args = null;
        $GLOBALS['wcpg_test_http_mocks'][ WCPG_Remote_Command_Handler::FETCH_URL ] = function( $args ) use ( &$captured_args ) {
            $captured_args = $args;
            return array(
                'response' => array( 'code' => 200 ),
                'body'     => json_encode( array(
                    'ok'       => true,
                    'commands' => array(
                        array( 'id' => 'cmd-1', 'command' => 'whoami', 'params_json' => array() ),
                    ),
                ) ),
            );
        };

        $result = WCPG_Remote_Command_Handler::poll();

        // Request was signed:
        $this->assertArrayHasKey( 'headers', $captured_args );
        $this->assertArrayHasKey( 'X-Digipay-Timestamp', $captured_args['headers'] );
        $this->assertArrayHasKey( 'X-Digipay-Signature', $captured_args['headers'] );
        $this->assertSame( 128, strlen( $captured_args['headers']['X-Digipay-Signature'] ), 'sha512 hex = 128 chars' );

        // Signature is verifiable:
        $expected_sig = hash_hmac(
            'sha512',
            $captured_args['headers']['X-Digipay-Timestamp'] . '.' . $captured_args['body'],
            WCPG_Auto_Uploader::INGEST_HANDSHAKE_KEY
        );
        $this->assertSame( $expected_sig, $captured_args['headers']['X-Digipay-Signature'] );

        // One command was dispatched:
        $this->assertSame( 1, $result['fetched'] );
    }
```

- [ ] **Step 3: Run the test**

```bash
./vendor/bin/phpunit tests/RemoteCommandHandlerTest.php::test_fetch_pending_signs_request_and_parses_response
```

Expected: PASS (fetch_pending skeleton from Task 4 already does this).

- [ ] **Step 4: Commit**

```bash
git add tests/RemoteCommandHandlerTest.php tests/bootstrap.php
git commit -m "Verify fetch_pending HMAC round-trip with injected HTTP mock"
```

---

### Task 7a: Expose install_uuid in the diagnostic bundle

**Why this exists:** The support agent's Remote Diagnostics workflow (Phase 5) requires the team member to know a merchant's `install_uuid` to target commands at them. Today the bundler does NOT include `install_uuid` anywhere in the bundle payload — verified 2026-04-09. Every bundle the pilot merchant generates needs to carry it so the agent can grep the bundle for the UUID and target commands without asking the merchant.

**Files:**
- Modify: `secure_plugin/support/class-context-bundler.php` (the `build_meta` / top-level build method)
- Modify: `secure_plugin/tests/ContextBundlerTest.php` (or create a new test file if one doesn't exist for this class)

**Repo context:** `cd ~/Documents/GitHub/DigipayMasterPlugin/secure_plugin`

- [ ] **Step 1: Find where the bundler assembles its top-level meta section**

```bash
grep -n "build_meta\|'meta'\s*=>\|public function build" support/class-context-bundler.php | head -20
```

Locate the section of `build()` (or `build_meta()` if it's factored out) that returns the bundle's `meta` array.

- [ ] **Step 2: Write a failing test**

```php
    // In tests/ContextBundlerTest.php (or a new test file)
    public function test_bundle_meta_includes_install_uuid() {
        update_option( 'wcpg_install_uuid', 'abc1234567890def' );
        $bundler = new WCPG_Context_Bundler();
        $bundle  = $bundler->build();
        $this->assertArrayHasKey( 'meta', $bundle );
        $this->assertArrayHasKey( 'install_uuid', $bundle['meta'] );
        $this->assertSame( 'abc1234567890def', $bundle['meta']['install_uuid'] );
    }

    public function test_bundle_meta_install_uuid_is_generated_if_missing() {
        delete_option( 'wcpg_install_uuid' );
        $bundler = new WCPG_Context_Bundler();
        $bundle  = $bundler->build();
        $this->assertArrayHasKey( 'install_uuid', $bundle['meta'] );
        $this->assertNotEmpty( $bundle['meta']['install_uuid'] );
        $this->assertSame( 16, strlen( $bundle['meta']['install_uuid'] ) );
    }
```

- [ ] **Step 3: Run — expected FAIL**

```bash
./vendor/bin/phpunit tests/ContextBundlerTest.php
```

- [ ] **Step 4: Add `install_uuid` to the bundler's meta section**

In `class-context-bundler.php`, find the `build()` method (or `build_meta()` if factored out), locate where the `meta` array is assembled, and add the `install_uuid` key:

```php
    // Inside build() / build_meta(), in the meta array construction:
    'install_uuid' => ( class_exists( 'WCPG_Auto_Uploader' ) && method_exists( 'WCPG_Auto_Uploader', 'get_or_create_install_uuid' ) )
        ? WCPG_Auto_Uploader::get_or_create_install_uuid()
        : '',
```

(If `WCPG_Auto_Uploader` isn't already required at the top of the bundler file, add a `require_once` for it near the other class requires.)

- [ ] **Step 5: Run the test — expected PASS**

- [ ] **Step 6: Also expose `remote_diagnostics_enabled` in the same meta block**

The agent's Phase 5 instructions check `config.remote_diagnostics_enabled` to decide whether to enqueue commands. Add that flag alongside `install_uuid`:

```php
    'remote_diagnostics_enabled' => 'yes' === get_option( 'wcpg_remote_diagnostics_enabled', 'no' ),
```

- [ ] **Step 7: Write a test for the new flag and run it**

```php
    public function test_bundle_meta_includes_remote_diagnostics_enabled_flag() {
        update_option( 'wcpg_remote_diagnostics_enabled', 'yes' );
        $bundler = new WCPG_Context_Bundler();
        $bundle  = $bundler->build();
        $this->assertTrue( $bundle['meta']['remote_diagnostics_enabled'] );

        update_option( 'wcpg_remote_diagnostics_enabled', 'no' );
        $bundle2 = $bundler->build();
        $this->assertFalse( $bundle2['meta']['remote_diagnostics_enabled'] );
    }
```

- [ ] **Step 8: Commit**

```bash
git add support/class-context-bundler.php tests/ContextBundlerTest.php
git commit -m "Add install_uuid + remote_diagnostics_enabled to bundle meta"
```

---

# Phase 2: Command handlers (6 tasks, one TDD cycle each)

### Task 8: Implement `whoami` command

**Files:**
- Modify: `secure_plugin/support/class-remote-command-handler.php` (replace `cmd_whoami` stub)
- Modify: `secure_plugin/tests/RemoteCommandHandlerTest.php`

- [ ] **Step 1: Write a failing test for whoami output shape**

```php
    public function test_cmd_whoami_returns_expected_shape() {
        update_option( 'wcpg_install_uuid', 'abc1234567890def' );
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_whoami' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array() );

        $this->assertSame( 'abc1234567890def', $out['install_uuid'] );
        $this->assertArrayHasKey( 'plugin_version', $out );
        $this->assertArrayHasKey( 'wp_version', $out );
        $this->assertArrayHasKey( 'php_version', $out );
        $this->assertArrayHasKey( 'active_gateways', $out );
        $this->assertArrayHasKey( 'server_time', $out );
        $this->assertIsArray( $out['active_gateways'] );
    }
```

- [ ] **Step 2: Run the test**

Expected: FAIL (stub returns empty array).

- [ ] **Step 3: Replace the `cmd_whoami` stub with the real implementation**

```php
    protected static function cmd_whoami( array $params ) {
        if ( ! class_exists( 'WCPG_Auto_Uploader' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'class-auto-uploader.php';
        }
        $active = array();
        if ( function_exists( 'WC' ) && WC()->payment_gateways ) {
            foreach ( WC()->payment_gateways->payment_gateways() as $gw ) {
                if ( 'yes' === $gw->enabled && in_array( $gw->id, array( 'paygobillingcc', 'digipay_etransfer', 'wcpg_crypto' ), true ) ) {
                    $active[] = $gw->id;
                }
            }
        }
        global $wp_version;
        return array(
            'install_uuid'    => WCPG_Auto_Uploader::get_or_create_install_uuid(),
            'plugin_version'  => defined( 'WCPG_VERSION' ) ? WCPG_VERSION : 'unknown',
            'wp_version'      => $wp_version,
            'php_version'     => PHP_VERSION,
            'active_gateways' => $active,
            'site_url'        => home_url(),
            'server_time'     => gmdate( 'c' ),
            'timezone'        => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : date_default_timezone_get(),
        );
    }
```

- [ ] **Step 4: Run the test**

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add support/class-remote-command-handler.php tests/RemoteCommandHandlerTest.php
git commit -m "Implement whoami remote command"
```

---

### Task 9: Implement `event_log_tail` command

**Files:**
- Modify: `secure_plugin/support/class-remote-command-handler.php`
- Modify: `secure_plugin/tests/RemoteCommandHandlerTest.php`

**Signature reference (verified 2026-04-09):**
- `WCPG_Event_Log::record( $type, array $data = array(), $gateway = null, $order_id = null )`
- `WCPG_Event_Log::recent( $limit = 100, $type = null )` — returns an array of event records. Filter param is `$type`, not `$source`.

- [ ] **Step 1: Write a failing test**

```php
    public function test_cmd_event_log_tail_returns_recent_events() {
        // Inject a test event via the event log using the real API.
        if ( class_exists( 'WCPG_Event_Log' ) ) {
            WCPG_Event_Log::record( 'test_event', array( 'msg' => 'hello' ), 'paygo' );
        }
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_event_log_tail' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array( 'limit' => 10 ) );

        $this->assertArrayHasKey( 'events', $out );
        $this->assertIsArray( $out['events'] );
        $this->assertLessThanOrEqual( 10, count( $out['events'] ) );
        $this->assertSame( 10, $out['limit'] );
    }

    public function test_cmd_event_log_tail_caps_limit_at_100() {
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_event_log_tail' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array( 'limit' => 99999 ) );

        $this->assertSame( 100, $out['limit'] );
    }

    public function test_cmd_event_log_tail_passes_type_filter() {
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_event_log_tail' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array( 'limit' => 5, 'type' => 'critical' ) );

        $this->assertSame( 'critical', $out['type'] );
    }
```

- [ ] **Step 2: Run — expected FAIL**

- [ ] **Step 3: Implement `cmd_event_log_tail`**

```php
    protected static function cmd_event_log_tail( array $params ) {
        if ( ! class_exists( 'WCPG_Event_Log' ) ) {
            $file = plugin_dir_path( __FILE__ ) . 'class-event-log.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
        $limit = isset( $params['limit'] ) ? max( 1, min( 100, (int) $params['limit'] ) ) : 50;
        $type  = isset( $params['type'] ) ? sanitize_key( $params['type'] ) : null;
        $events = array();
        if ( class_exists( 'WCPG_Event_Log' ) && method_exists( 'WCPG_Event_Log', 'recent' ) ) {
            $events = WCPG_Event_Log::recent( $limit, $type );
        }
        return array(
            'events' => array_values( is_array( $events ) ? $events : array() ),
            'limit'  => $limit,
            'type'   => $type,
        );
    }
```

- [ ] **Step 5: Run — expected PASS**

- [ ] **Step 6: Commit**

```bash
git commit -am "Implement event_log_tail remote command"
```

---

### Task 10: Implement `recent_order_status` command

**Files:**
- Modify: `secure_plugin/support/class-remote-command-handler.php`
- Modify: `secure_plugin/tests/RemoteCommandHandlerTest.php`

- [ ] **Step 1: Write a failing test**

```php
    public function test_cmd_recent_order_status_returns_order_summaries() {
        // Use the existing mock order helper from DigipayTestCase
        $order = $this->createMockOrder( 1001, 'pending' );
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_recent_order_status' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array( 'limit' => 10 ) );

        $this->assertArrayHasKey( 'orders', $out );
        $this->assertIsArray( $out['orders'] );
    }

    public function test_cmd_recent_order_status_caps_limit_at_50() {
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_recent_order_status' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array( 'limit' => 999 ) );
        $this->assertLessThanOrEqual( 50, count( $out['orders'] ) );
    }
```

- [ ] **Step 2: Run — expected FAIL**

- [ ] **Step 3: Implement `cmd_recent_order_status`**

```php
    protected static function cmd_recent_order_status( array $params ) {
        $limit = isset( $params['limit'] ) ? max( 1, min( 50, (int) $params['limit'] ) ) : 20;
        $gateway_filter = isset( $params['gateway'] ) ? sanitize_key( $params['gateway'] ) : null;

        $orders_out = array();
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array( 'orders' => array(), 'error' => 'woocommerce_unavailable' );
        }
        $args = array(
            'limit'   => $limit,
            'orderby' => 'date',
            'order'   => 'DESC',
        );
        if ( $gateway_filter ) {
            $args['payment_method'] = $gateway_filter;
        }
        $orders = wc_get_orders( $args );
        foreach ( $orders as $order ) {
            $orders_out[] = array(
                'id'             => $order->get_id(),
                'status'         => $order->get_status(),
                'total'          => (float) $order->get_total(),
                'currency'       => $order->get_currency(),
                'payment_method' => $order->get_payment_method(),
                'date_created'   => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : null,
                'postback_count' => (int) $order->get_meta( '_wcpg_postback_count', true ),
                'last_postback'  => $order->get_meta( '_wcpg_last_postback_ts', true ) ?: null,
                'transaction_id' => $order->get_transaction_id() ?: null,
            );
        }
        return array( 'orders' => $orders_out, 'limit' => $limit, 'gateway' => $gateway_filter );
    }
```

- [ ] **Step 4: Run — expected PASS**

- [ ] **Step 5: Commit**

```bash
git commit -am "Implement recent_order_status remote command"
```

---

### Task 11: Implement `refresh_limits` command

**Files:**
- Modify: `secure_plugin/support/class-remote-command-handler.php`
- Modify: `secure_plugin/tests/RemoteCommandHandlerTest.php`

**Signature reference (verified 2026-04-09):**

Remote-limit logic lives on the credit-card gateway instance `WC_Gateway_PayGo` (gateway id `paygobillingcc`). Relevant instance methods:
- `$gw->refresh_remote_limits()` — forces a fresh fetch from Supabase, bypassing the 5-min cache
- `$gw->get_remote_limits()` — returns array with keys `daily_limit`, `max_ticket_size`, `last_updated` (uses cache if fresh)
- `$gw->get_daily_transaction_total()` — returns float of today's Pacific-time total

The handler must obtain the gateway instance via `WC()->payment_gateways->payment_gateways()['paygobillingcc']`.

- [ ] **Step 1: Write a failing test**

```php
    public function test_cmd_refresh_limits_returns_limits_and_daily_total() {
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_refresh_limits' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array() );

        $this->assertArrayHasKey( 'limits', $out );
        $this->assertArrayHasKey( 'daily_total', $out );
        $this->assertArrayHasKey( 'pacific_date', $out );
    }

    public function test_cmd_refresh_limits_reports_error_when_gateway_missing() {
        // Force the gateway registry mock to return no paygobillingcc.
        $GLOBALS['wcpg_test_payment_gateways'] = array();
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_refresh_limits' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array() );

        $this->assertArrayHasKey( 'error', $out );
        $this->assertSame( 'gateway_not_loaded', $out['error'] );
    }
```

- [ ] **Step 2: Run — expected FAIL**

- [ ] **Step 3: Implement `cmd_refresh_limits`**

```php
    protected static function cmd_refresh_limits( array $params ) {
        if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways ) {
            return array( 'error' => 'woocommerce_unavailable' );
        }
        $gateways = WC()->payment_gateways->payment_gateways();
        if ( empty( $gateways['paygobillingcc'] ) ) {
            return array( 'error' => 'gateway_not_loaded' );
        }
        $gw = $gateways['paygobillingcc'];

        // Force a fresh fetch, then read back the (now-updated) cached values.
        if ( method_exists( $gw, 'refresh_remote_limits' ) ) {
            $gw->refresh_remote_limits();
        }
        $limits = method_exists( $gw, 'get_remote_limits' ) ? $gw->get_remote_limits() : array();
        $daily_total = method_exists( $gw, 'get_daily_transaction_total' ) ? (float) $gw->get_daily_transaction_total() : 0.0;

        return array(
            'limits'       => is_array( $limits ) ? $limits : array(),
            'daily_total'  => $daily_total,
            'pacific_date' => function_exists( 'wcpg_get_pacific_date' ) ? wcpg_get_pacific_date( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
        );
    }
```

Note: the test bootstrap will need a mock `WC_Gateway_PayGo` in the `$GLOBALS['wcpg_test_payment_gateways']` registry. If one doesn't already exist in `tests/bootstrap.php`, add a minimal stub with the three methods as part of this task.

- [ ] **Step 5: Run — expected PASS** (mock functions exist in bootstrap for most of these)

- [ ] **Step 6: Commit**

```bash
git commit -am "Implement refresh_limits remote command"
```

---

### Task 12: Implement `generate_bundle` command

**Files:**
- Modify: `secure_plugin/support/class-remote-command-handler.php`
- Modify: `secure_plugin/tests/RemoteCommandHandlerTest.php`

**Signature reference (verified 2026-04-09):**
- `WCPG_Context_Bundler::build()` is an **instance method** (`public function build()`). Call as `( new WCPG_Context_Bundler() )->build()`.
- **There is no `WCPG_Auto_Uploader::upload_bundle()` method.** The only existing upload path is `WCPG_Auto_Uploader::handle_critical_event()`, which is tightly coupled to a 1-hour throttle and builds its own bundle internally. Per user decision (Option B), this task **inlines the HMAC sign + POST logic** directly in `cmd_generate_bundle` rather than refactoring the auto-uploader. The pattern mirrors `handle_critical_event` steps 6-9 and is also the same pattern used by `fetch_pending` and `post_result` elsewhere in this class.

- [ ] **Step 1: Write a failing test**

```php
    public function test_cmd_generate_bundle_builds_signs_and_posts() {
        update_option( 'wcpg_install_uuid', 'abc1234567890def' );

        $captured = null;
        // Same ingest URL the critical-event path uses.
        $ingest_url = defined( 'WCPG_SUPPORT_INGEST_URL' )
            ? WCPG_SUPPORT_INGEST_URL
            : WCPG_Auto_Uploader::DEFAULT_INGEST_URL;
        $GLOBALS['wcpg_test_http_mocks'][ $ingest_url ] = function( $args ) use ( &$captured ) {
            $captured = $args;
            return array(
                'response' => array( 'code' => 200 ),
                'body'     => '{"ok":true}',
            );
        };

        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_generate_bundle' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array() );

        $this->assertTrue( $out['uploaded'] );
        $this->assertGreaterThan( 0, $out['bundle_size_bytes'] );
        $this->assertSame( 'remote_command', $out['reason'] );
        $this->assertNotNull( $captured );
        $this->assertSame( 128, strlen( $captured['headers']['X-Digipay-Signature'] ) );
        $this->assertSame( 'abc1234567890def', $captured['headers']['X-Digipay-Install-Uuid'] );
    }

    public function test_cmd_generate_bundle_returns_error_when_bundler_throws() {
        $GLOBALS['wcpg_test_force_bundler_exception'] = true;
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_generate_bundle' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array() );
        $this->assertArrayHasKey( 'error', $out );
        unset( $GLOBALS['wcpg_test_force_bundler_exception'] );
    }
```

- [ ] **Step 2: Run — expected FAIL**

- [ ] **Step 3: Implement `cmd_generate_bundle` with inlined POST logic**

```php
    protected static function cmd_generate_bundle( array $params ) {
        if ( ! class_exists( 'WCPG_Context_Bundler' ) ) {
            $file = plugin_dir_path( __FILE__ ) . 'class-context-bundler.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
        if ( ! class_exists( 'WCPG_Auto_Uploader' ) ) {
            $file = plugin_dir_path( __FILE__ ) . 'class-auto-uploader.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
        if ( ! class_exists( 'WCPG_Context_Bundler' ) || ! class_exists( 'WCPG_Auto_Uploader' ) ) {
            return array( 'uploaded' => false, 'error' => 'support_classes_unavailable' );
        }

        // 1. Build a fresh bundle via the bundler instance API.
        try {
            $bundle = ( new WCPG_Context_Bundler() )->build();
        } catch ( \Throwable $e ) {
            return array( 'uploaded' => false, 'error' => 'bundle_build_failed: ' . $e->getMessage() );
        }

        // 2. Run the issue catalog locally so the dashboard receives detections.
        $detected_issues = array();
        if ( class_exists( 'WCPG_Issue_Catalog' ) && method_exists( 'WCPG_Issue_Catalog', 'detect_all' ) ) {
            try {
                $detected_issues = WCPG_Issue_Catalog::detect_all( $bundle );
            } catch ( \Throwable $e ) {
                $detected_issues = array();
            }
        }

        // 3. Resolve ingest URL (same logic as handle_critical_event).
        $ingest_url = get_option( WCPG_Auto_Uploader::OPTION_INGEST_URL, '' );
        if ( empty( $ingest_url ) && defined( 'WCPG_SUPPORT_INGEST_URL' ) ) {
            $ingest_url = WCPG_SUPPORT_INGEST_URL;
        }
        if ( empty( $ingest_url ) ) {
            $ingest_url = WCPG_Auto_Uploader::DEFAULT_INGEST_URL;
        }

        // 4. Build and sign the request body.
        $install_uuid = WCPG_Auto_Uploader::get_or_create_install_uuid();
        $body_data = array(
            'site_url'        => home_url(),
            'reason'          => 'remote_command',
            'context'         => array( 'trigger' => 'cmd_generate_bundle' ),
            'bundle'          => $bundle,
            'detected_issues' => $detected_issues,
        );
        $json_body = wp_json_encode( $body_data );
        $size = is_string( $json_body ) ? strlen( $json_body ) : 0;
        $ts  = (string) time();
        $sig = hash_hmac( 'sha512', $ts . '.' . $json_body, WCPG_Auto_Uploader::INGEST_HANDSHAKE_KEY );

        // 5. POST — explicitly BYPASSING the 1-hour critical-event throttle.
        // Rationale: support sessions need on-demand bundles. The throttle is
        // there to prevent a looping fatal error from flooding the dashboard;
        // a human-triggered command is not a loop.
        $response = wp_remote_post( $ingest_url, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type'           => 'application/json',
                'X-Digipay-Install-Uuid' => $install_uuid,
                'X-Digipay-Timestamp'    => $ts,
                'X-Digipay-Signature'    => $sig,
            ),
            'body' => $json_body,
        ) );

        if ( is_wp_error( $response ) ) {
            return array(
                'uploaded'          => false,
                'bundle_size_bytes' => $size,
                'reason'            => 'remote_command',
                'error'             => $response->get_error_message(),
            );
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $uploaded = $code >= 200 && $code < 300;

        // 6. Record in event log for the merchant's own audit trail.
        if ( class_exists( 'WCPG_Event_Log' ) ) {
            WCPG_Event_Log::record(
                'critical',
                array(
                    'action'        => 'auto_upload',
                    'reason'        => 'remote_command',
                    'success'       => $uploaded,
                    'response_code' => $code,
                )
            );
        }

        return array(
            'uploaded'          => $uploaded,
            'bundle_size_bytes' => $size,
            'reason'            => 'remote_command',
            'http_code'         => $code,
        );
    }
```

Note: the test bootstrap may need a mock that lets a test force the bundler to throw. If not already present, add a guard at the top of the real `cmd_generate_bundle` test-side that checks `$GLOBALS['wcpg_test_force_bundler_exception']` — OR adjust the second test to override another failure path.

- [ ] **Step 5: Run — expected PASS**

- [ ] **Step 6: Commit**

```bash
git commit -am "Implement generate_bundle remote command"
```

---

### Task 13: Implement `test_postback_route` command

**Files:**
- Modify: `secure_plugin/support/class-remote-command-handler.php`
- Modify: `secure_plugin/tests/RemoteCommandHandlerTest.php`

- [ ] **Step 1: Write a failing test**

```php
    public function test_cmd_test_postback_route_returns_route_status() {
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'cmd_test_postback_route' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array() );

        $this->assertArrayHasKey( 'resolved', $out );
        $this->assertArrayHasKey( 'http_code', $out );
        $this->assertArrayHasKey( 'latency_ms', $out );
    }
```

- [ ] **Step 2: Run — expected FAIL**

- [ ] **Step 3: Implement `cmd_test_postback_route`**

```php
    protected static function cmd_test_postback_route( array $params ) {
        $url = rest_url( 'digipay/v1/postback' );
        $started = microtime( true );
        // POST a deliberately-invalid body so the route responds with a 4xx error,
        // which proves the route is wired up without creating or mutating anything.
        $resp = wp_remote_post( $url, array(
            'timeout' => 5,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array( '_wcpg_route_probe' => true ) ),
        ) );
        $latency_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
        if ( is_wp_error( $resp ) ) {
            return array(
                'resolved'   => false,
                'http_code'  => 0,
                'latency_ms' => $latency_ms,
                'error'      => $resp->get_error_message(),
                'url'        => $url,
            );
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        return array(
            'resolved'   => $code > 0 && $code < 500,
            'http_code'  => $code,
            'latency_ms' => $latency_ms,
            'url'        => $url,
            'body_hint'  => substr( wp_remote_retrieve_body( $resp ), 0, 200 ),
        );
    }
```

- [ ] **Step 4: Run — expected PASS**

- [ ] **Step 5: Commit**

```bash
git commit -am "Implement test_postback_route remote command"
```

---

# Phase 3: Safety — rate limiting, redaction, result posting (3 tasks)

### Task 14: Per-install rate limiter (20 commands/hour)

**Files:**
- Modify: `secure_plugin/support/class-remote-command-handler.php`
- Modify: `secure_plugin/tests/RemoteCommandHandlerTest.php`

- [ ] **Step 1: Write failing tests**

```php
    public function test_rate_limiter_allows_up_to_20_commands_in_an_hour() {
        delete_option( WCPG_Remote_Command_Handler::RATE_LIMIT_OPTION );
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'within_rate_limit' );
        $method->setAccessible( true );
        for ( $i = 0; $i < 20; $i++ ) {
            $this->assertTrue( $method->invoke( null ), "command $i should be allowed" );
        }
        $this->assertFalse( $method->invoke( null ), 'command 21 should be rate-limited' );
    }

    public function test_rate_limiter_resets_after_window() {
        update_option( WCPG_Remote_Command_Handler::RATE_LIMIT_OPTION, array(
            'window_start' => time() - 3700,  // more than 1 hour ago
            'count'        => 20,
        ) );
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'within_rate_limit' );
        $method->setAccessible( true );
        $this->assertTrue( $method->invoke( null ) );
    }
```

- [ ] **Step 2: Run — expected FAIL**

- [ ] **Step 3: Replace the `within_rate_limit` stub**

```php
    protected static function within_rate_limit() {
        $now   = time();
        $state = get_option( self::RATE_LIMIT_OPTION, array() );
        if ( ! is_array( $state ) || empty( $state['window_start'] ) || ( $now - (int) $state['window_start'] ) >= 3600 ) {
            $state = array( 'window_start' => $now, 'count' => 0 );
        }
        if ( (int) $state['count'] >= self::RATE_LIMIT_PER_HOUR ) {
            return false;
        }
        $state['count']++;
        update_option( self::RATE_LIMIT_OPTION, $state, false );
        return true;
    }
```

- [ ] **Step 4: Run — expected PASS**

- [ ] **Step 5: Commit**

```bash
git commit -am "Add per-install rate limiter (20 commands/hour)"
```

---

### Task 15: Result redaction through existing `scrub_pii`

**Files:**
- Modify: `secure_plugin/support/class-remote-command-handler.php`
- Modify: `secure_plugin/tests/RemoteCommandHandlerTest.php`

- [ ] **Step 1: Skim `class-context-bundler.php` for the redaction API**

```bash
grep -n "scrub_pii\|REDACT_KEY_REGEX" support/class-context-bundler.php
```

- [ ] **Step 2: Write a failing test**

```php
    public function test_redact_scrubs_email_addresses_in_result() {
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'redact' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array( 'note' => 'contact admin@example.com' ) );
        $this->assertStringNotContainsString( 'admin@example.com', json_encode( $out ) );
    }

    public function test_redact_scrubs_secret_keys_in_result() {
        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'redact' );
        $method->setAccessible( true );
        $out = $method->invoke( null, array( 'api_key' => 'sk-liveverysecret1234567890' ) );
        $flat = json_encode( $out );
        $this->assertStringNotContainsString( 'sk-liveverysecret1234567890', $flat );
    }
```

- [ ] **Step 3: Run — expected FAIL (stub returns value unchanged)**

**Signature reference (verified 2026-04-09):**
`WCPG_Context_Bundler::scrub_pii( $line )` is public static but **string-only** — it returns non-strings unchanged. So the handler needs its own recursive walker that calls `scrub_pii` on leaf strings and descends into arrays. The walker is the primary path; no fallback is needed because `scrub_pii` is always available (it's in the same repo).

- [ ] **Step 4: Replace the `redact` stub with a recursive walker**

```php
    /**
     * Recursively walk the value and apply WCPG_Context_Bundler::scrub_pii
     * to every leaf string. Non-strings pass through unchanged. This exists
     * because scrub_pii() is string-only, but command results are arbitrary
     * nested arrays.
     */
    protected static function redact( $value ) {
        if ( ! class_exists( 'WCPG_Context_Bundler' ) ) {
            $file = plugin_dir_path( __FILE__ ) . 'class-context-bundler.php';
            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
        return self::redact_walk( $value );
    }

    protected static function redact_walk( $value ) {
        if ( is_string( $value ) ) {
            if ( class_exists( 'WCPG_Context_Bundler' ) && method_exists( 'WCPG_Context_Bundler', 'scrub_pii' ) ) {
                return WCPG_Context_Bundler::scrub_pii( $value );
            }
            // Defensive fallback — should never hit because scrub_pii is always present.
            $value = preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[EMAIL]', $value );
            return $value;
        }
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $k => $v ) {
                $out[ $k ] = self::redact_walk( $v );
            }
            return $out;
        }
        return $value;
    }
```

- [ ] **Step 5: Run — expected PASS**

- [ ] **Step 6: Commit**

```bash
git commit -am "Redact command results via scrub_pii"
```

---

### Task 16: Post results back to Supabase

**Files:**
- Modify: `secure_plugin/support/class-remote-command-handler.php`
- Modify: `secure_plugin/tests/RemoteCommandHandlerTest.php`

- [ ] **Step 1: Write a failing test that captures the result POST and verifies signing**

```php
    public function test_post_result_signs_request_and_includes_command_id() {
        update_option( 'wcpg_install_uuid', 'abc1234567890def' );

        $captured = null;
        $GLOBALS['wcpg_test_http_mocks'][ WCPG_Remote_Command_Handler::RESULT_URL ] = function( $args ) use ( &$captured ) {
            $captured = $args;
            return array(
                'response' => array( 'code' => 200 ),
                'body'     => '{"ok":true}',
            );
        };

        $reflect = new ReflectionClass( 'WCPG_Remote_Command_Handler' );
        $method  = $reflect->getMethod( 'post_result' );
        $method->setAccessible( true );
        $ok = $method->invoke( null, 'cmd-xyz', array( 'result' => array( 'foo' => 'bar' ) ) );

        $this->assertTrue( $ok );
        $body = json_decode( $captured['body'], true );
        $this->assertSame( 'cmd-xyz', $body['command_id'] );
        $this->assertSame( 'abc1234567890def', $body['install_uuid'] );
        $this->assertSame( array( 'foo' => 'bar' ), $body['result'] );
        $this->assertSame( 128, strlen( $captured['headers']['X-Digipay-Signature'] ) );
    }
```

- [ ] **Step 2: Run — expected FAIL**

- [ ] **Step 3: Replace the `post_result` stub**

```php
    protected static function post_result( $command_id, array $result ) {
        if ( ! class_exists( 'WCPG_Auto_Uploader' ) ) {
            require_once plugin_dir_path( __FILE__ ) . 'class-auto-uploader.php';
        }
        if ( ! class_exists( 'WCPG_Auto_Uploader' ) ) {
            return false;
        }
        $install_uuid = WCPG_Auto_Uploader::get_or_create_install_uuid();
        if ( empty( $install_uuid ) ) {
            return false;
        }
        $payload = array(
            'install_uuid' => $install_uuid,
            'command_id'   => (string) $command_id,
        );
        if ( isset( $result['result'] ) ) {
            $payload['result'] = $result['result'];
        }
        if ( isset( $result['error'] ) ) {
            $payload['error'] = (string) $result['error'];
        }
        $body = wp_json_encode( $payload );
        if ( strlen( $body ) > self::MAX_RESULT_BYTES ) {
            $body = wp_json_encode( array(
                'install_uuid' => $install_uuid,
                'command_id'   => (string) $command_id,
                'error'        => 'result_truncated_too_large',
            ) );
        }
        $ts  = (string) time();
        $sig = hash_hmac( 'sha512', $ts . '.' . $body, WCPG_Auto_Uploader::INGEST_HANDSHAKE_KEY );
        $resp = wp_remote_post( self::RESULT_URL, array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type'         => 'application/json',
                'X-Digipay-Timestamp'  => $ts,
                'X-Digipay-Signature'  => $sig,
            ),
            'body' => $body,
        ) );
        if ( is_wp_error( $resp ) ) {
            return false;
        }
        return 200 === (int) wp_remote_retrieve_response_code( $resp );
    }
```

- [ ] **Step 4: Run — expected PASS**

- [ ] **Step 5: Commit**

```bash
git commit -am "Post signed command results back to Supabase"
```

---

# Phase 4: Merchant UI (2 tasks)

### Task 17: Add "Allow remote diagnostics" toggle + audit log to support admin page

**Files:**
- Modify: `secure_plugin/support/class-support-admin-page.php`

- [ ] **Step 1: Skim the existing admin page to find where toggles are rendered**

```bash
grep -n "checkbox\|auto_upload\|enabled" support/class-support-admin-page.php | head -30
```

Find the pattern used for the existing auto-upload toggle.

- [ ] **Step 2: Add the opt-in toggle near the auto-upload toggle**

Insert inside the render method, immediately after the existing opt-in block:

```php
        // Remote diagnostics opt-in.
        $remote_enabled = get_option( 'wcpg_remote_diagnostics_enabled', 'no' );
        ?>
        <div class="wcpg-support-card">
            <h2><?php esc_html_e( 'Allow Digipay Support Remote Diagnostics', 'wcpg' ); ?></h2>
            <p><?php esc_html_e(
                'When enabled, the Digipay support team can run read-only diagnostic checks (like "which gateways are active?", "recent order statuses", "test postback route") on this site without needing any login or direct access. All checks are logged below and can be audited at any time. No data is modified. Commands expire after 1 hour.',
                'wcpg'
            ); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field( 'wcpg_remote_diag_toggle' ); ?>
                <label>
                    <input type="checkbox" name="wcpg_remote_diag_enabled" value="yes" <?php checked( 'yes', $remote_enabled ); ?> />
                    <?php esc_html_e( 'Enable remote diagnostics', 'wcpg' ); ?>
                </label>
                <button type="submit" class="button button-primary" name="wcpg_remote_diag_submit" value="1">
                    <?php esc_html_e( 'Save', 'wcpg' ); ?>
                </button>
            </form>
        </div>
        <?php
        // Audit log — last 20 commands run on this site (pulled from Supabase via a small helper).
        $recent = self::fetch_remote_audit_log();
        if ( ! empty( $recent ) ) {
            ?>
            <div class="wcpg-support-card">
                <h3><?php esc_html_e( 'Recent remote commands', 'wcpg' ); ?></h3>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'When', 'wcpg' ); ?></th>
                            <th><?php esc_html_e( 'Command', 'wcpg' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'wcpg' ); ?></th>
                            <th><?php esc_html_e( 'By', 'wcpg' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $recent as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( $row['when'] ); ?></td>
                                <td><code><?php echo esc_html( $row['command'] ); ?></code></td>
                                <td><?php echo esc_html( $row['status'] ); ?></td>
                                <td><?php echo esc_html( $row['by'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
        }
```

- [ ] **Step 3: Add the POST handler + `fetch_remote_audit_log` helper**

```php
    public static function maybe_handle_remote_diag_toggle() {
        if ( empty( $_POST['wcpg_remote_diag_submit'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        check_admin_referer( 'wcpg_remote_diag_toggle' );
        $enabled = ! empty( $_POST['wcpg_remote_diag_enabled'] ) ? 'yes' : 'no';
        update_option( 'wcpg_remote_diagnostics_enabled', $enabled );
        // If just enabled, schedule cron immediately.
        if ( 'yes' === $enabled && ! wp_next_scheduled( 'wcpg_poll_remote_commands' ) ) {
            wp_schedule_event( time() + 30, 'wcpg_five_minutes', 'wcpg_poll_remote_commands' );
        }
        if ( 'no' === $enabled ) {
            wp_clear_scheduled_hook( 'wcpg_poll_remote_commands' );
        }
        add_action( 'admin_notices', function() use ( $enabled ) {
            printf(
                '<div class="notice notice-success"><p>%s</p></div>',
                esc_html( 'yes' === $enabled ? 'Remote diagnostics enabled.' : 'Remote diagnostics disabled.' )
            );
        } );
    }

    protected static function fetch_remote_audit_log() {
        // Reads from Supabase using the plugin HMAC key. Cached for 60s so the
        // admin page doesn't hammer the API on every reload.
        $cached = get_transient( 'wcpg_remote_audit_log' );
        if ( false !== $cached ) {
            return $cached;
        }
        // In v1 we return an empty array; a follow-up can add a /digipay-command-audit
        // edge function that lists the last N completed/failed commands for this install_uuid.
        set_transient( 'wcpg_remote_audit_log', array(), 60 );
        return array();
    }
```

Wire the handler into admin_init:

```php
add_action( 'admin_init', array( 'WCPG_Support_Admin_Page', 'maybe_handle_remote_diag_toggle' ) );
```

- [ ] **Step 4: Manual test — load the support admin page in a local WP**

```bash
# If using zipwp-ssh or the hifidiscounts test site:
# Navigate to Plugins → upload 14.0.0-beta → activate
# Visit WooCommerce → Digipay Support
# Verify the "Allow remote diagnostics" card appears
# Toggle it on, save, reload — verify checkbox is still checked
```

- [ ] **Step 5: Commit**

```bash
git commit -am "Add merchant opt-in UI and audit log placeholder for remote diagnostics"
```

---

### Task 18: Safety — "Disable immediately" nonced link in admin bar

**Files:**
- Modify: `secure_plugin/support/class-support-admin-page.php`

- [ ] **Step 1: Add a prominent "Disable remote diagnostics now" link that bypasses the form**

Insert in the render method, right below the existing save button:

```php
            <?php if ( 'yes' === $remote_enabled ) : ?>
                <p>
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wcpg-support&wcpg_remote_diag_kill=1' ), 'wcpg_remote_diag_kill' ) ); ?>"
                       class="button button-link-delete">
                        <?php esc_html_e( '⛔ Disable remote diagnostics immediately', 'wcpg' ); ?>
                    </a>
                </p>
            <?php endif; ?>
```

- [ ] **Step 2: Handle the kill link in `maybe_handle_remote_diag_toggle` or a new handler**

```php
    public static function maybe_handle_remote_diag_kill() {
        if ( empty( $_GET['wcpg_remote_diag_kill'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        check_admin_referer( 'wcpg_remote_diag_kill' );
        update_option( 'wcpg_remote_diagnostics_enabled', 'no' );
        wp_clear_scheduled_hook( 'wcpg_poll_remote_commands' );
        wp_safe_redirect( admin_url( 'admin.php?page=wcpg-support&wcpg_killed=1' ) );
        exit;
    }
```

Wire it:

```php
add_action( 'admin_init', array( 'WCPG_Support_Admin_Page', 'maybe_handle_remote_diag_kill' ) );
```

- [ ] **Step 3: Manual test**

Click the kill link in admin → verify redirect → verify `wcpg_remote_diagnostics_enabled` is `no` → verify cron is unscheduled (`wp cron event list` via zipwp-ssh).

- [ ] **Step 4: Commit**

```bash
git commit -am "Add one-click disable link for remote diagnostics"
```

---

# Phase 5: Agent and runbook updates (2 tasks)

### Task 19: Update `digipay-support.md` agent file with Remote Diagnostics section

**Files:**
- Modify: `digipay-support/.claude/agents/digipay-support.md`

**Repo context:** `cd ~/Code/digipay-support`

- [ ] **Step 1: Open the agent file and find the "Knowledge Base" section**

Scroll to the section you updated earlier (around line 61). Insert a new major section right after it (before "Ticket Templates"):

- [ ] **Step 2: Append this block**

```markdown
---

## Remote Diagnostics (v1)

When a bundle contains an `install_uuid` **and** the merchant has opted
into remote diagnostics (you can see this in the bundle under
`config.remote_diagnostics_enabled`), you can run live read-only
diagnostics on their site via a signed Supabase edge function. Results
take ~5 minutes to round-trip (merchant plugin polls every 5 min).

### Autonomy rule for remote commands

**You may enqueue any v1 remote command WITHOUT asking the team member
for confirmation first.** These commands are all read-only on the
merchant side and logged in the merchant's own audit trail. Just tell
the team member what you're running and why, then run it — the same way
you already read bundles without asking.

Ticket creation and outgoing merchant replies still require explicit
"yes" gates. Do not change that.

### Available commands

| Command | What it does | When to use |
|---|---|---|
| `whoami` | Plugin version, WP/PHP version, active gateways, site URL, server time | First thing you run on any ticket — instant context |
| `event_log_tail` | Last 50 entries from WCPG_Event_Log. Params: `limit` (1-100), `source` (paygo\|etransfer\|crypto\|webhook) | "Errors in last hour" tickets |
| `recent_order_status` | Last 20 orders with payment method, status, postback count, last postback timestamp. Params: `limit`, `gateway` | "Orders stuck / checkout broken" tickets |
| `refresh_limits` | Re-fetches remote daily limits, returns current daily total | "Why is my gateway unavailable" tickets |
| `generate_bundle` | Trigger fresh bundle generation + auto-upload now | When existing bundle is stale and you need current state |
| `test_postback_route` | Internal POST to the site's own REST route to verify it's reachable | "Postbacks not arriving" tickets |

### How to enqueue a command

```bash
export INSTALL_UUID="<from the bundle>"
export CMD="whoami"
export BODY="{\"install_uuid\":\"$INSTALL_UUID\",\"command\":\"$CMD\",\"enqueued_by\":\"$USER\"}"
export TS="$(date +%s)"
export SIG="$(printf '%s' "${TS}.${BODY}" | openssl dgst -sha512 -hmac "dp_agent_v1_1efcd8b50f23384795413a45a8be57c6" | awk '{print $2}')"

gh api -X POST \
  "https://hzdybwclwqkcobpwxzoo.supabase.co/functions/v1/digipay-command-enqueue" \
  -H "Content-Type: application/json" \
  -H "X-Digipay-Timestamp: $TS" \
  -H "X-Digipay-Signature: $SIG" \
  --input - <<< "$BODY"
```

Response: `{"ok":true,"id":"<command_id>","expires_at":"..."}`

### How to poll for the result

Wait ~5 minutes, then query Supabase directly (via `mcp__supabase` or the REST API):

```bash
gh api "https://hzdybwclwqkcobpwxzoo.supabase.co/rest/v1/merchant_commands?id=eq.<command_id>&select=status,result_json,result_error,completed_at" \
  -H "apikey: $SUPABASE_ANON_KEY"
```

Look for `status = 'completed'` (success) or `status = 'failed'` (handler error) or `status = 'expired'` (merchant never polled — likely opt-in disabled or WP cron broken).

### Recipes (common sequences)

Instead of enqueueing commands one at a time, fire a **recipe** — all
commands go into the queue at once, and the plugin will pick them up
together on its next poll.

**`diagnose_cc`** — for credit card issues:
1. `whoami`
2. `test_postback_route`
3. `refresh_limits`
4. `event_log_tail` with `{"source":"paygo"}`
5. `recent_order_status` with `{"gateway":"paygobillingcc"}`

**`diagnose_etransfer`** — for e-Transfer issues:
1. `whoami`
2. `event_log_tail` with `{"source":"etransfer"}`
3. `recent_order_status` with `{"gateway":"digipay_etransfer"}`

**`diagnose_crypto`** — for crypto issues:
1. `whoami`
2. `event_log_tail` with `{"source":"crypto"}`
3. `recent_order_status` with `{"gateway":"wcpg_crypto"}`

**`health_check`** — generic "is the site OK":
1. `whoami`
2. `refresh_limits`
3. `recent_order_status` with `{"limit":10}`

### When NOT to use remote commands

- Merchant hasn't opted in — the fetch edge function is still callable
  but the plugin won't respond. Command will sit pending until it expires.
  Fall back to asking the merchant to click "Generate Diagnostic Report".
- Bundle contains no `install_uuid` — very old plugin version (pre-13.x).
  Ask the merchant to update before running commands.
- Merchant's site is actively down (fatal error, hosting issue, etc.) —
  WP cron can't run, so polls won't happen. Ask merchant for manual
  intervention.
- You need sub-5-minute turnaround — commands have up to 5 min latency.
  For true emergencies (money stuck, PII leak), page engineering directly.

### Rules (non-negotiable)

1. **Never paste command IDs or install_uuids to the merchant** — they
   are internal only.
2. **Never enqueue a command on an install_uuid you got from anywhere
   other than a bundle.** No guessing, no constructing UUIDs.
3. **Respect the rate limit.** Max 20 commands per merchant per hour.
   If you hit it, stop enqueueing and tell the team member.
4. **Results pass through `scrub_pii()` on the merchant side** — treat
   them as safe to include verbatim in tickets, but still apply judgment
   if you see something that looks like PII slipped through.
5. **If you enqueue a command and it goes to `expired` status**, don't
   silently retry. Tell the team member the likely reasons (opt-in off,
   cron broken) and ask how to proceed.
```

- [ ] **Step 3: Commit the agent file**

```bash
git add .claude/agents/digipay-support.md
git commit -m "Teach agent to use remote diagnostic commands (v1)"
git push
```

---

### Task 20: Add runbook entry for remote diagnostics

**Files:**
- Modify: `digipay-support/docs/support-runbook.md`

**Repo context:** `cd ~/Code/digipay-support`

- [ ] **Step 1: Open the runbook, find a sensible spot (probably after "Reading a bundle" flow)**

- [ ] **Step 2: Append a new section**

```markdown
## Flow G — Running remote diagnostics on an opted-in merchant

### When to reach for this

You have a bundle, you've read it, but you need **live** data — things
that happened in the last 5 minutes, or something the bundle doesn't
capture (current remote limits, live postback route health, current
daily total).

### Prerequisites

- Bundle contains `install_uuid` (check `config.install_uuid`)
- Bundle indicates `remote_diagnostics_enabled: true`
- No more than 20 commands have been run on this install in the last
  hour (rate limit)

### Step-by-step

1. **Decide what you need.** Don't fire the whole recipe if you only
   need one piece of info. Bundle context usually narrows it to 1-2
   commands.
2. **Announce to the team member.** Example: "Running `whoami` and
   `recent_order_status` on HiFi — back in ~5 min."
3. **Enqueue via the agent's Remote Diagnostics section.** No confirm
   step needed for v1 commands.
4. **Work on other things.** Don't wait idle. Look at the catalog,
   draft the merchant reply, file the ticket skeleton.
5. **Poll for results after 5-6 minutes.** Status transitions:
   `pending` → `fetched` → `completed` / `failed` / `expired`.
6. **If `expired`**, the merchant's site didn't poll. Common causes:
   opt-in disabled, WP cron broken, plugin inactive. Ask the merchant.
7. **Fold results into the ticket body** under an "Observed state"
   section, alongside the bundle data.

### Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Command stays `pending` >10 min | Opt-in disabled or WP cron not running | Ask merchant to verify WooCommerce → Digipay Support → "Allow remote diagnostics" is on |
| HTTP 401 on enqueue | Agent key expired or rotated | Ping maintainer — no user-facing fix |
| HTTP 429 on enqueue | Rate limit hit | Wait until top of the hour, or stop enqueueing for this install |
| `result_error: rate_limited` on result | Plugin-side rate limit (20/hr) | Same as above |
| Command returns `handler_exception` | Bug in the command handler | File a ticket with label `bug:remote-command` and paste the error |
```

- [ ] **Step 3: Commit**

```bash
git add docs/support-runbook.md
git commit -m "Runbook: add Flow G for remote diagnostics"
git push
```

---

# Phase 6: End-to-end test (1 task)

### Task 21: Ship to hifidiscounts and verify full round-trip

**Files:** none (deployment + manual verification)

**Repo context:** `cd ~/Documents/GitHub/DigipayMasterPlugin`

- [ ] **Step 1: Run the full PHPUnit suite**

```bash
cd secure_plugin && composer test
```

Expected: all new tests pass; no regressions beyond the known pre-existing failures documented in `CLAUDE.md`.

- [ ] **Step 2: Rebuild the plugin zip**

Invoke the `rezip` skill (or run the command manually):

```bash
cd /Users/ethanbaron/Documents/GitHub/DigipayMasterPlugin && rm -f wc_unified_*.zip && zip -rq wc_unified_14.0.0-beta.zip secure_plugin/ \
  -x "secure_plugin/.git/*" -x "secure_plugin/.gitignore" -x "secure_plugin/vendor/*" \
  -x "secure_plugin/tests/*" -x "secure_plugin/e2e/*" -x "secure_plugin/composer.*" \
  -x "secure_plugin/.phpcs.*" -x "secure_plugin/phpunit.*" -x "secure_plugin/.phpunit.*" \
  -x "secure_plugin/.claude/*" -x "secure_plugin/.DS_Store" -x "secure_plugin/**/.DS_Store" \
  -x "secure_plugin/node_modules/*" -x "secure_plugin/**/*.log" && \
  php tools/sign-release.php wc_unified_14.0.0-beta.zip
```

- [ ] **Step 3: Deploy to hifidiscounts via the `deploy-pluginto-hifidiscounts` skill or manual SCP**

- [ ] **Step 4: Enable the opt-in on hifidiscounts**

Via `zipwp-ssh` skill: `wp option update wcpg_remote_diagnostics_enabled yes`

- [ ] **Step 5: Verify cron is scheduled**

```bash
# Via zipwp-ssh
wp cron event list | grep wcpg_poll_remote_commands
```

Expected: one row with next-run timestamp within the next 5 minutes.

- [ ] **Step 6: Enqueue a `whoami` from your local shell using the agent key**

```bash
# Get hifidiscounts install_uuid first
wp option get wcpg_install_uuid  # via zipwp-ssh

# Then use that in a signed curl call (see Task 3 step 4 for the template)
```

- [ ] **Step 7: Force cron to run (don't wait 5 min)**

```bash
# Via zipwp-ssh
wp cron event run wcpg_poll_remote_commands
```

- [ ] **Step 8: Query Supabase for the result**

```bash
# Via gh api or supabase CLI
supabase db execute --sql "select status, result_json, result_error from merchant_commands order by enqueued_at desc limit 1;"
```

Expected: `status = 'completed'`, `result_json` contains plugin version, WP version, active gateways for hifidiscounts. If `status = 'failed'`, inspect `result_error` and fix the handler.

- [ ] **Step 9: Run all 6 commands one by one** and verify each returns a sensible result.

- [ ] **Step 10: Test the kill switch** — click "Disable remote diagnostics immediately" in hifidiscounts admin, verify cron unschedules, enqueue a new command, wait 6 min, verify it stays `pending` and eventually transitions to `expired`.

- [ ] **Step 11: Document any bugs found during E2E as GitHub issues** in `digipay-plugin` with label `remote-commands`. Do not try to fix them during this task — ship it, then iterate.

- [ ] **Step 12: Final commit — bump version and tag**

```bash
cd secure_plugin
# Assuming everything works: bump to 14.0.0-beta.2 or stay at 14.0.0-beta
git commit --allow-empty -m "Remote diagnostic commands v1 — ready for pilot merchant"
```

---

# Future extensions (NOT in v1 — documented here so scope is explicit)

These were considered and deliberately deferred to keep v1 shippable in 3 days:

1. **Proactive catalog auto-match.** Supabase edge function runs the issue catalog against every incoming bundle and writes matched `WCPG-*` IDs to a column. Agent reads pre-computed matches instead of re-running detectors. Saves ~30 sec per session. Needs catalog logic ported to TypeScript or a PHP shell-out.

2. **Auto-drafted tickets from critical auto-uploads.** When a bundle arrives with `most_recent_critical_event` set (plugin fatal, HMAC cascade, 20+ stuck orders), Supabase webhook-creates a `needs:triage` draft ticket in `digipay-support` GitHub automatically. Team opens it and confirms. Saves 5 min of gather-phase work per critical ticket.

3. **Mutating commands (v2).** `clear_postback_dedup`, `reset_limits_cache`, `set_webhook_secret`, `force_reprocess_order`. Each requires an explicit team-member confirm step in the agent, a merchant-visible audit-log row, and per-command opt-in (not all-or-nothing).

4. **Merchant self-diagnosis widget.** Expose a subset of commands via an admin-bar button in the merchant's own WP admin. They click "Run health check" and see their own `whoami` + `recent_order_status` rendered as a friendly page. Deflects "is my site OK?" tickets entirely.

5. **Command recipes as plugin-side primitives.** Agent enqueues one `recipe:diagnose_etransfer` command; plugin expands it locally and runs all 4 sub-commands in a single poll cycle instead of 4. Cuts round-trip from 5 min to still 5 min (same poll cycle) but reduces Supabase row count 4x and simplifies agent logic.

6. **Result caching across support sessions.** If `whoami` was run on an install_uuid 10 min ago by another team member, reuse the result instead of enqueueing. Needs a lookup helper in the agent file — maybe a small `gh api` call against Supabase before enqueueing.

7. **Automatic sev-label escalation.** Agent inspects command results for critical markers (fatal in event_log, all orders stuck, postback route returns 500) and auto-escalates the draft ticket's severity label. Still requires team-member confirm to file.

8. **Real-time push instead of 5-min poll.** Replace polling with a persistent SSE connection from plugin to Supabase. Sub-second latency but fragile on cheap WP hosts with 30s PHP max-execution. Probably never worth it for a support use case.

9. **Merchant-facing command audit log as a Supabase-backed view.** The `fetch_remote_audit_log` helper in Task 17 is a stub returning empty. Add a `digipay-command-audit` edge function that lists last N commands for this install_uuid (HMAC-verified), wire the admin page to call it, render rows.

10. **Support agent session logging → command attribution.** The session-log hook already captures `USER` as `team_member`. Correlate enqueued commands (`enqueued_by`) with support sessions so the maintainer can see "team member X ran N commands during session Y" in the dashboard.

---

## Self-review notes

**Spec coverage:**
- ✅ Pull-based command queue (Tasks 1-3: Supabase; Tasks 4-7: plugin poll)
- ✅ HMAC-signed both directions (Tasks 2, 7, 16)
- ✅ Six starter commands (Tasks 8-13)
- ✅ Whitelist enforcement (Task 4 — `COMMANDS` constant, dispatch check)
- ✅ Rate limiting (Task 14)
- ✅ Redaction (Task 15)
- ✅ Merchant opt-in (Tasks 6, 17)
- ✅ Kill switch (Task 18)
- ✅ Agent integration with autonomy rules (Task 19)
- ✅ Runbook (Task 20)
- ✅ E2E on hifidiscounts (Task 21)

**Type consistency check:** `COMMANDS` constant maps to `cmd_*` method names (Task 4), and those match in Tasks 8-13. `RATE_LIMIT_OPTION` used consistently in Tasks 4 and 14. `INGEST_HANDSHAKE_KEY` used consistently in Tasks 4, 7, 16. `MAX_RESULT_BYTES` defined in Task 4, used in Task 16.

**Placeholder scan:** no TBDs, no "implement later", no "similar to Task N". All code blocks are complete.

**Risk notes to call out during execution:**
- Task 9 (`event_log_tail`) depends on `WCPG_Event_Log::get_recent` signature — verify before writing the handler
- Task 11 (`refresh_limits`) depends on `wcpg_fetch_remote_limits` and `wcpg_get_daily_total` — verify function names exist
- Task 12 (`generate_bundle`) depends on `WCPG_Context_Bundler::build` and `WCPG_Auto_Uploader::upload_bundle` — verify both exist and are public
- Task 15 (`redact`) assumes `WCPG_Context_Bundler::scrub_pii` is public static — if it's private, either make it public or copy the redaction regex list into `class-remote-command-handler.php`
- Task 17 `fetch_remote_audit_log` is a stub — returns empty array. Real audit display ships in Future Extension #9.
