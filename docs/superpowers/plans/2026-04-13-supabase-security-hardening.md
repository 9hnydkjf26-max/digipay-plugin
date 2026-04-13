# Supabase Security Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close three Supabase attack vectors that allowed an attacker to enumerate merchant site IDs and URLs: unauthenticated edge functions, open RLS on tables, and PostgREST schema hint leakage.

**Architecture:** Add HMAC-SHA512 signature verification to both edge functions (reusing the plugin's existing `wcpg_sign_api_headers` format), apply strict RLS policies denying anon access to all tables, and configure PostgREST to suppress table name hints. The plugin already sends signatures (since 14.x) — the edge functions just never checked them. Merchants on 13.1.6 will lose limits sync and health reporting (payments unaffected) until they update.

**Tech Stack:** Supabase Edge Functions (Deno/TypeScript), PostgreSQL RLS policies, PostgREST config

**Context:**
- Supabase project ref: `hzdybwclwqkcobpwxzoo`
- Edge functions: `digipay-dashboard/supabase/functions/`
- Migrations: `digipay-dashboard/supabase/migrations/`
- HMAC secret: `WCPG_Auto_Uploader::INGEST_HANDSHAKE_KEY` = `dp_ingest_v1_a74f8c3e9b2d6051f8a7c3e4b9d10287` (public, in plugin source)
- Edge function env var for the same key: `DIGIPAY_INGEST_HANDSHAKE` (referenced in plugin docs, must be set in Supabase dashboard)
- Signing format: `hash_hmac('sha512', timestamp + '.' + canonical_payload, secret)`
  - GET requests: canonical_payload = sorted query string (`instance_token=X&site_id=Y`)
  - POST requests: canonical_payload = raw JSON body
- Headers sent by plugin: `X-Digipay-Install-Uuid`, `X-Digipay-Timestamp`, `X-Digipay-Signature`
- Replay window: 5 minutes (already documented in plugin)

---

### Task 1: Add shared HMAC verification helper

**Files:**
- Create: `digipay-dashboard/supabase/functions/_shared/verify-hmac.ts`

This shared module will be imported by both edge functions.

- [ ] **Step 1: Create the shared HMAC verification module**

```typescript
// digipay-dashboard/supabase/functions/_shared/verify-hmac.ts

const HANDSHAKE_KEY = Deno.env.get("DIGIPAY_INGEST_HANDSHAKE") ?? "";
const REPLAY_WINDOW_SECONDS = 300; // 5 minutes

/**
 * Verify the HMAC-SHA512 signature from a Digipay plugin request.
 *
 * @param canonical  The canonical payload string (query string for GET, JSON body for POST).
 * @param headers    The request headers object.
 * @returns          { valid: true } or { valid: false, reason: string }
 */
export async function verifyHmac(
  canonical: string,
  headers: Headers
): Promise<{ valid: true } | { valid: false; reason: string }> {
  if (!HANDSHAKE_KEY) {
    console.error("DIGIPAY_INGEST_HANDSHAKE env var is not set");
    return { valid: false, reason: "server_misconfigured" };
  }

  const signature = headers.get("x-digipay-signature");
  const timestamp = headers.get("x-digipay-timestamp");
  const installUuid = headers.get("x-digipay-install-uuid");

  if (!signature || !timestamp || !installUuid) {
    return { valid: false, reason: "missing_signature_headers" };
  }

  // Replay protection: reject timestamps outside 5-minute window.
  const ts = parseInt(timestamp, 10);
  if (isNaN(ts)) {
    return { valid: false, reason: "invalid_timestamp" };
  }
  const now = Math.floor(Date.now() / 1000);
  if (Math.abs(now - ts) > REPLAY_WINDOW_SECONDS) {
    return { valid: false, reason: "timestamp_expired" };
  }

  // Compute expected signature: HMAC-SHA512(timestamp + '.' + canonical)
  const encoder = new TextEncoder();
  const key = await crypto.subtle.importKey(
    "raw",
    encoder.encode(HANDSHAKE_KEY),
    { name: "HMAC", hash: "SHA-512" },
    false,
    ["sign"]
  );
  const data = encoder.encode(timestamp + "." + canonical);
  const sigBuffer = await crypto.subtle.sign("HMAC", key, data);

  // Convert to hex string for comparison with PHP's hash_hmac output.
  const expectedSig = Array.from(new Uint8Array(sigBuffer))
    .map((b) => b.toString(16).padStart(2, "0"))
    .join("");

  // Constant-time comparison.
  if (expectedSig.length !== signature.length) {
    return { valid: false, reason: "signature_mismatch" };
  }
  let mismatch = 0;
  for (let i = 0; i < expectedSig.length; i++) {
    mismatch |= expectedSig.charCodeAt(i) ^ signature.charCodeAt(i);
  }
  if (mismatch !== 0) {
    return { valid: false, reason: "signature_mismatch" };
  }

  return { valid: true };
}
```

- [ ] **Step 2: Commit**

```bash
cd /Users/ethanbaron/Documents/GitHub/DigipayMasterPlugin
git add digipay-dashboard/supabase/functions/_shared/verify-hmac.ts
git commit -m "feat: add shared HMAC-SHA512 verification for edge functions"
```

---

### Task 2: Add HMAC verification to plugin-site-limits

**Files:**
- Modify: `digipay-dashboard/supabase/functions/plugin-site-limits/index.ts`

- [ ] **Step 1: Add HMAC verification to the edge function**

Replace the entire file with this updated version that adds signature verification after the CORS/method checks and before any database access:

```typescript
// digipay-dashboard/supabase/functions/plugin-site-limits/index.ts
import { serve } from "https://deno.land/std@0.177.0/http/server.ts";
import { createClient } from "https://esm.sh/@supabase/supabase-js@2";
import { verifyHmac } from "../_shared/verify-hmac.ts";

const corsHeaders = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Methods": "GET, OPTIONS",
  "Access-Control-Allow-Headers":
    "Content-Type, Accept, X-Digipay-Signature, X-Digipay-Timestamp, X-Digipay-Install-Uuid",
};

serve(async (req: Request) => {
  // Handle CORS preflight
  if (req.method === "OPTIONS") {
    return new Response(null, { headers: corsHeaders });
  }

  if (req.method !== "GET") {
    return new Response(JSON.stringify({ error: "Method not allowed" }), {
      status: 405,
      headers: { ...corsHeaders, "Content-Type": "application/json" },
    });
  }

  try {
    const url = new URL(req.url);
    const siteId = url.searchParams.get("site_id");
    const instanceToken = url.searchParams.get("instance_token");
    if (!siteId && !instanceToken) {
      return new Response(
        JSON.stringify({
          success: false,
          error: "site_id or instance_token required",
        }),
        {
          status: 400,
          headers: { ...corsHeaders, "Content-Type": "application/json" },
        }
      );
    }

    // Build canonical query string (sorted keys, RFC3986-encoded) to match
    // PHP's wcpg_canonical_query() output.
    const params: Record<string, string> = {};
    if (instanceToken) params["instance_token"] = instanceToken;
    if (siteId) params["site_id"] = siteId;
    const sortedKeys = Object.keys(params).sort();
    const canonical = sortedKeys
      .map((k) => encodeURIComponent(k) + "=" + encodeURIComponent(params[k]))
      .join("&");

    // Verify HMAC signature.
    const hmacResult = await verifyHmac(canonical, req.headers);
    if (!hmacResult.valid) {
      return new Response(
        JSON.stringify({ success: false, error: "Unauthorized" }),
        {
          status: 401,
          headers: { ...corsHeaders, "Content-Type": "application/json" },
        }
      );
    }

    const supabase = createClient(
      Deno.env.get("SUPABASE_URL")!,
      Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!
    );

    // Resolve the site_id — dashboard assignment (instance_token) is the
    // source of truth so it can override the plugin's locally-stored site_id.
    let resolvedSiteId: string | null = null;

    if (instanceToken) {
      const { data: healthRow } = await supabase
        .from("plugin_site_health")
        .select("site_id")
        .eq("instance_token", instanceToken)
        .not("site_id", "is", null)
        .maybeSingle();

      if (healthRow?.site_id) {
        resolvedSiteId = healthRow.site_id;
      }
    }

    // Fall back to the plugin's passed site_id if no dashboard assignment
    if (!resolvedSiteId && siteId) {
      resolvedSiteId = siteId;
    }

    if (!resolvedSiteId) {
      return new Response(
        JSON.stringify({
          success: false,
          error: "Site not found. Contact support for site registration.",
        }),
        {
          status: 404,
          headers: { ...corsHeaders, "Content-Type": "application/json" },
        }
      );
    }

    // Fetch limits and payment_gateway_url from site_pricing
    const { data: pricing, error: pricingError } = await supabase
      .from("site_pricing")
      .select(
        "daily_limit, max_ticket_size, gateway_status, payment_gateway_url"
      )
      .eq("site_id", resolvedSiteId)
      .maybeSingle();

    if (pricingError) {
      console.error("Pricing query error:", pricingError);
      return new Response(
        JSON.stringify({ success: false, error: "Failed to fetch limits" }),
        {
          status: 500,
          headers: { ...corsHeaders, "Content-Type": "application/json" },
        }
      );
    }

    if (!pricing) {
      return new Response(
        JSON.stringify({
          success: false,
          error: "No pricing configuration found for this site.",
        }),
        {
          status: 404,
          headers: { ...corsHeaders, "Content-Type": "application/json" },
        }
      );
    }

    // Build response
    const responseBody: Record<string, unknown> = {
      success: true,
      site_id: resolvedSiteId,
      daily_limit: pricing.daily_limit || 0,
      max_ticket_size: pricing.max_ticket_size || 0,
      status: pricing.gateway_status || "active",
    };

    // Include payment_gateway_url only when set (non-null)
    if (pricing.payment_gateway_url) {
      responseBody.payment_gateway_url = pricing.payment_gateway_url;
    }

    return new Response(JSON.stringify(responseBody), {
      status: 200,
      headers: { ...corsHeaders, "Content-Type": "application/json" },
    });
  } catch (err) {
    console.error("Limits API error:", err);
    return new Response(
      JSON.stringify({ success: false, error: "Internal server error" }),
      {
        status: 500,
        headers: { ...corsHeaders, "Content-Type": "application/json" },
      }
    );
  }
});
```

- [ ] **Step 2: Verify the unauthenticated request is rejected**

After deploying (Task 5), test:

```bash
curl -sS "https://hzdybwclwqkcobpwxzoo.supabase.co/functions/v1/plugin-site-limits?site_id=BCO-BRAV"
```

Expected: `{"success":false,"error":"Unauthorized"}` with HTTP 401.

- [ ] **Step 3: Commit**

```bash
cd /Users/ethanbaron/Documents/GitHub/DigipayMasterPlugin
git add digipay-dashboard/supabase/functions/plugin-site-limits/index.ts
git commit -m "security: enforce HMAC signature verification on plugin-site-limits"
```

---

### Task 3: Add HMAC verification to plugin-site-health-report

**Files:**
- Modify: `digipay-dashboard/supabase/functions/plugin-site-health-report/index.ts`

- [ ] **Step 1: Add HMAC verification to the health report edge function**

Replace the entire file with this updated version:

```typescript
// digipay-dashboard/supabase/functions/plugin-site-health-report/index.ts
import { serve } from "https://deno.land/std@0.177.0/http/server.ts";
import { createClient } from "https://esm.sh/@supabase/supabase-js@2";
import { verifyHmac } from "../_shared/verify-hmac.ts";

const corsHeaders = {
  "Access-Control-Allow-Origin": "*",
  "Access-Control-Allow-Methods": "POST, OPTIONS",
  "Access-Control-Allow-Headers":
    "Content-Type, X-Digipay-Signature, X-Digipay-Timestamp, X-Digipay-Install-Uuid",
};

serve(async (req: Request) => {
  // Handle CORS preflight
  if (req.method === "OPTIONS") {
    return new Response(null, { headers: corsHeaders });
  }

  if (req.method !== "POST") {
    return new Response(JSON.stringify({ error: "Method not allowed" }), {
      status: 405,
      headers: { ...corsHeaders, "Content-Type": "application/json" },
    });
  }

  try {
    // Read the raw body once — used for both HMAC verification and parsing.
    const rawBody = await req.text();

    // Verify HMAC signature. For POST, the canonical payload is the raw JSON body.
    const hmacResult = await verifyHmac(rawBody, req.headers);
    if (!hmacResult.valid) {
      return new Response(
        JSON.stringify({ error: "Unauthorized" }),
        {
          status: 401,
          headers: { ...corsHeaders, "Content-Type": "application/json" },
        }
      );
    }

    const body = JSON.parse(rawBody);

    const siteId = body.site_id || null;
    const instanceToken = body.instance_token || null;

    // Require at least one identifier: site_id or instance_token
    if (!siteId && !instanceToken) {
      return new Response(
        JSON.stringify({ error: "site_id or instance_token required" }),
        {
          status: 400,
          headers: { ...corsHeaders, "Content-Type": "application/json" },
        }
      );
    }

    const supabase = createClient(
      Deno.env.get("SUPABASE_URL")!,
      Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!
    );

    // Build the upsert data from the health report payload
    const healthData: Record<string, unknown> = {
      site_url: null,
      site_name: body.site_name || null,
      instance_token: instanceToken,

      // API status
      api_status: body.api_status || null,
      api_last_check: body.api_last_check || null,
      api_last_success: body.api_last_success || null,
      api_response_time_ms: body.api_response_time_ms || null,

      // Postback status
      postback_status: body.postback_status || null,
      postback_last_received: body.postback_last_received || null,
      postback_last_success: body.postback_last_success || null,
      postback_success_count: body.postback_success_count || 0,
      postback_error_count: body.postback_error_count || 0,
      postback_last_error: body.postback_last_error || null,

      // Environment diagnostics
      has_ssl: body.has_ssl ?? null,
      has_curl: body.has_curl ?? null,
      curl_version: body.curl_version || null,
      openssl_version: body.openssl_version || null,
      can_reach_api: body.can_reach_api ?? null,
      firewall_issue: body.firewall_issue ?? null,
      server_software: body.server_software || null,

      // Diagnostic issues
      diagnostic_issues: body.diagnostic_issues || [],
      diagnostic_details: body.diagnostic_details || null,
      last_diagnostic_run: body.last_diagnostic_run || null,

      // Version info
      plugin_version: body.plugin_version || null,
      wordpress_version: body.wordpress_version || null,
      woocommerce_version: body.woocommerce_version || null,
      php_version: body.php_version || null,

      updated_at: new Date().toISOString(),
    };

    let assignedSiteId: string | null = null;

    if (siteId) {
      // Has a site_id — upsert by site_id (existing behavior)
      healthData.site_id = siteId;

      const { error } = await supabase
        .from("plugin_site_health")
        .upsert(healthData, { onConflict: "site_id" });

      if (error) {
        console.error("Upsert by site_id error:", error);
        return new Response(JSON.stringify({ error: error.message }), {
          status: 500,
          headers: { ...corsHeaders, "Content-Type": "application/json" },
        });
      }

      assignedSiteId = siteId;
    } else if (instanceToken) {
      // No site_id but has instance_token — check if row exists
      const { data: existing } = await supabase
        .from("plugin_site_health")
        .select("id, site_id")
        .eq("instance_token", instanceToken)
        .maybeSingle();

      if (existing) {
        // Update existing row
        const { error } = await supabase
          .from("plugin_site_health")
          .update(healthData)
          .eq("instance_token", instanceToken);

        if (error) {
          console.error("Update by instance_token error:", error);
          return new Response(JSON.stringify({ error: error.message }), {
            status: 500,
            headers: { ...corsHeaders, "Content-Type": "application/json" },
          });
        }

        // Return the previously-assigned site_id if one exists
        assignedSiteId = existing.site_id || null;
      } else {
        // Insert new row (unregistered instance)
        const { error } = await supabase
          .from("plugin_site_health")
          .insert(healthData);

        if (error) {
          console.error("Insert new instance error:", error);
          return new Response(JSON.stringify({ error: error.message }), {
            status: 500,
            headers: { ...corsHeaders, "Content-Type": "application/json" },
          });
        }
      }
    }

    // Return success with any assigned site_id
    const responseBody: Record<string, unknown> = { success: true };
    if (assignedSiteId) {
      responseBody.site_id = assignedSiteId;
    }

    return new Response(JSON.stringify(responseBody), {
      status: 200,
      headers: { ...corsHeaders, "Content-Type": "application/json" },
    });
  } catch (err) {
    console.error("Health report error:", err);
    return new Response(
      JSON.stringify({ error: "Internal server error" }),
      {
        status: 500,
        headers: { ...corsHeaders, "Content-Type": "application/json" },
      }
    );
  }
});
```

- [ ] **Step 2: Commit**

```bash
cd /Users/ethanbaron/Documents/GitHub/DigipayMasterPlugin
git add digipay-dashboard/supabase/functions/plugin-site-health-report/index.ts
git commit -m "security: enforce HMAC signature verification on plugin-site-health-report"
```

---

### Task 4: Lock down RLS on all tables

**Files:**
- Create: `digipay-dashboard/supabase/migrations/20260413_lockdown_anon_rls.sql`

This migration enables RLS on every table (idempotently) and creates explicit DENY policies for the `anon` role. Tables that already have RLS enabled won't be affected — the new policies just ensure no anon access slips through.

- [ ] **Step 1: Create the RLS lockdown migration**

```sql
-- Migration: Deny all anon access to public tables
-- Date: 2026-04-13
-- Purpose: Close information leak — anon role should never read/write any table.
-- The edge functions use SUPABASE_SERVICE_ROLE_KEY to bypass RLS, so this
-- does not affect plugin ↔ edge-function traffic.

-- ============================================================
-- 1. Enable RLS on every table (idempotent — no-op if already on)
-- ============================================================
ALTER TABLE IF EXISTS public.site_pricing             ENABLE ROW LEVEL SECURITY;
ALTER TABLE IF EXISTS public.plugin_site_health        ENABLE ROW LEVEL SECURITY;
ALTER TABLE IF EXISTS public.merchant_commands         ENABLE ROW LEVEL SECURITY;
ALTER TABLE IF EXISTS public.merchant_payments         ENABLE ROW LEVEL SECURITY;
ALTER TABLE IF EXISTS public.plugin_bundle_uploads     ENABLE ROW LEVEL SECURITY;
ALTER TABLE IF EXISTS public.cpt_data                  ENABLE ROW LEVEL SECURITY;
ALTER TABLE IF EXISTS public.cpt_site_accounts         ENABLE ROW LEVEL SECURITY;
ALTER TABLE IF EXISTS public.cpt_transaction_gaps      ENABLE ROW LEVEL SECURITY;
ALTER TABLE IF EXISTS public.telegram_sessions         ENABLE ROW LEVEL SECURITY;
ALTER TABLE IF EXISTS public.digipay_support_agent_sessions ENABLE ROW LEVEL SECURITY;

-- ============================================================
-- 2. Drop any existing permissive policies for anon role
--    (clean slate before applying deny-all)
-- ============================================================
DO $$
DECLARE
  r RECORD;
BEGIN
  FOR r IN
    SELECT schemaname, tablename, policyname
    FROM pg_policies
    WHERE schemaname = 'public'
      AND policyname LIKE '%anon%'
      AND roles @> ARRAY['anon']::name[]
  LOOP
    EXECUTE format('DROP POLICY IF EXISTS %I ON %I.%I',
                   r.policyname, r.schemaname, r.tablename);
  END LOOP;
END
$$;

-- ============================================================
-- 3. Force-deny the anon role on sensitive tables.
--    With RLS enabled and no permissive policies, anon gets
--    zero rows. But an explicit deny policy makes the intent
--    clear and survives accidental permissive policy additions.
-- ============================================================

-- site_pricing: contains merchant site IDs and daily limits
CREATE POLICY deny_anon_all ON public.site_pricing
  AS RESTRICTIVE
  FOR ALL
  TO anon
  USING (false)
  WITH CHECK (false);

-- plugin_site_health: contains merchant URLs, site IDs, instance tokens
CREATE POLICY deny_anon_all ON public.plugin_site_health
  AS RESTRICTIVE
  FOR ALL
  TO anon
  USING (false)
  WITH CHECK (false);

-- merchant_commands: remote diagnostic command queue
CREATE POLICY deny_anon_all ON public.merchant_commands
  AS RESTRICTIVE
  FOR ALL
  TO anon
  USING (false)
  WITH CHECK (false);

-- merchant_payments: payment records
CREATE POLICY deny_anon_all ON public.merchant_payments
  AS RESTRICTIVE
  FOR ALL
  TO anon
  USING (false)
  WITH CHECK (false);

-- plugin_bundle_uploads: diagnostic bundles (contain merchant config)
CREATE POLICY deny_anon_all ON public.plugin_bundle_uploads
  AS RESTRICTIVE
  FOR ALL
  TO anon
  USING (false)
  WITH CHECK (false);

-- cpt_data: CPT transaction data
CREATE POLICY deny_anon_all ON public.cpt_data
  AS RESTRICTIVE
  FOR ALL
  TO anon
  USING (false)
  WITH CHECK (false);

-- cpt_site_accounts: CPT site account mappings
CREATE POLICY deny_anon_all ON public.cpt_site_accounts
  AS RESTRICTIVE
  FOR ALL
  TO anon
  USING (false)
  WITH CHECK (false);

-- cpt_transaction_gaps: transaction gap monitoring
CREATE POLICY deny_anon_all ON public.cpt_transaction_gaps
  AS RESTRICTIVE
  FOR ALL
  TO anon
  USING (false)
  WITH CHECK (false);

-- telegram_sessions: Telegram bot sessions
CREATE POLICY deny_anon_all ON public.telegram_sessions
  AS RESTRICTIVE
  FOR ALL
  TO anon
  USING (false)
  WITH CHECK (false);

-- digipay_support_agent_sessions: support agent session logs
CREATE POLICY deny_anon_all ON public.digipay_support_agent_sessions
  AS RESTRICTIVE
  FOR ALL
  TO anon
  USING (false)
  WITH CHECK (false);
```

- [ ] **Step 2: Commit**

```bash
cd /Users/ethanbaron/Documents/GitHub/DigipayMasterPlugin
git add digipay-dashboard/supabase/migrations/20260413_lockdown_anon_rls.sql
git commit -m "security: deny all anon access to public tables via RLS"
```

---

### Task 5: Disable PostgREST table name hints

PostgREST error responses include `"hint": "Perhaps you meant the table 'public.X'"` — this lets attackers enumerate table names by fuzzing. This is controlled by the `db_extra_search_path` and error detail settings.

**This must be done in the Supabase dashboard** (not via migration):

- [ ] **Step 1: Suppress schema hints in API settings**

In the Supabase Dashboard:
1. Go to **Settings → API**
2. Under **Extra search path**, ensure only `public` is listed (no `extensions` or other schemas that could leak names)
3. Go to **Settings → Database → Postgres Settings**
4. Set `pgrst.db_plan_enabled` to `false` if available

Alternatively, if using `supabase config`:
```toml
[api]
extra_search_path = ["public"]
```

**Note:** Supabase managed hosting may not expose the PostgREST hint config directly. If the dashboard doesn't offer this toggle, this is mitigated by Task 4 (RLS denies all anon reads regardless of table discovery). The hints reveal names but no longer yield any data.

- [ ] **Step 2: Verify hints are suppressed or harmless**

```bash
ANON="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Imh6ZHlid2Nsd3FrY29icHd4em9vIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjYxNzU4ODQsImV4cCI6MjA4MTc1MTg4NH0.e0mSI7Qp9sRaclOwP61guBNtwTVHYXc-TtVaUON67QU"
# Try a table that exists — should get empty result or 403, not data
curl -sS "https://hzdybwclwqkcobpwxzoo.supabase.co/rest/v1/site_pricing?select=*" \
  -H "apikey: $ANON" -H "Authorization: Bearer $ANON"
```

Expected: `[]` (RLS blocks all rows) or `{"code":"42501",...}` (permission denied). Either way, no data.

```bash
# Try a table that doesn't exist — should NOT reveal real table names
curl -sS "https://hzdybwclwqkcobpwxzoo.supabase.co/rest/v1/nonexistent_table?select=*" \
  -H "apikey: $ANON" -H "Authorization: Bearer $ANON"
```

After RLS lockdown (Task 4), even if hints still appear, the attacker gets zero data from the hinted tables.

---

### Task 6: Deploy edge functions and run migration

- [ ] **Step 1: Verify DIGIPAY_INGEST_HANDSHAKE env var is set**

In the Supabase Dashboard → Edge Functions → Settings (or via CLI):

```bash
# Check if the env var exists (the value should match the plugin constant)
# dp_ingest_v1_a74f8c3e9b2d6051f8a7c3e4b9d10287
```

If not set, add it:
```bash
supabase secrets set DIGIPAY_INGEST_HANDSHAKE=dp_ingest_v1_a74f8c3e9b2d6051f8a7c3e4b9d10287 --project-ref hzdybwclwqkcobpwxzoo
```

- [ ] **Step 2: Deploy both edge functions**

```bash
cd /Users/ethanbaron/Documents/GitHub/DigipayMasterPlugin/digipay-dashboard

supabase functions deploy plugin-site-limits --project-ref hzdybwclwqkcobpwxzoo
supabase functions deploy plugin-site-health-report --project-ref hzdybwclwqkcobpwxzoo
```

- [ ] **Step 3: Apply the RLS migration**

```bash
supabase db push --project-ref hzdybwclwqkcobpwxzoo
```

- [ ] **Step 4: Verify unauthenticated limits request is rejected**

```bash
curl -sS "https://hzdybwclwqkcobpwxzoo.supabase.co/functions/v1/plugin-site-limits?site_id=BCO-BRAV"
```

Expected: `{"success":false,"error":"Unauthorized"}` with HTTP 401.

- [ ] **Step 5: Verify unauthenticated health report is rejected**

```bash
curl -sS -X POST "https://hzdybwclwqkcobpwxzoo.supabase.co/functions/v1/plugin-site-health-report" \
  -H "Content-Type: application/json" \
  -d '{"site_id":"TEST","site_name":"test"}'
```

Expected: `{"error":"Unauthorized"}` with HTTP 401.

- [ ] **Step 6: Verify RLS blocks anon table access**

```bash
ANON="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Imh6ZHlid2Nsd3FrY29icHd4em9vIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NjYxNzU4ODQsImV4cCI6MjA4MTc1MTg4NH0.e0mSI7Qp9sRaclOwP61guBNtwTVHYXc-TtVaUON67QU"

# These should all return [] or a permission error — never actual data
curl -sS "https://hzdybwclwqkcobpwxzoo.supabase.co/rest/v1/site_pricing?select=*" \
  -H "apikey: $ANON" -H "Authorization: Bearer $ANON"

curl -sS "https://hzdybwclwqkcobpwxzoo.supabase.co/rest/v1/plugin_site_health?select=*" \
  -H "apikey: $ANON" -H "Authorization: Bearer $ANON"

curl -sS "https://hzdybwclwqkcobpwxzoo.supabase.co/rest/v1/cpt_transaction_gaps?select=*" \
  -H "apikey: $ANON" -H "Authorization: Bearer $ANON"
```

Expected: `[]` for all three (RESTRICTIVE policy with `USING (false)` returns zero rows).

- [ ] **Step 7: Verify authenticated plugin requests still work**

Test from hifidiscounts (the test site) by triggering a health report or clearing the limits cache, then checking logs. Alternatively, construct a signed request manually:

```bash
# Quick smoke test — trigger a limits fetch from the test site via WP-CLI
ssh hifidiscounts "cd /path/to/wp && wp eval 'delete_transient(\"wcpg_remote_limits_\" . md5(\"YOUR_SITE_ID\"));'"
```

Then visit the test site's checkout page and confirm the gateway still appears (limits fetched successfully).

---

### Task 7: Remove siteId from frontend JS (bonus hardening)

**Files:**
- Modify: `secure_plugin/woocommerce-gateway-paygo.php:2687-2695`

The `wcpgFPConfig` JavaScript object on checkout pages exposes `siteId` to any visitor. The fingerprint script likely doesn't need this server-side — it's metadata for fingerprint tagging, not routing.

- [ ] **Step 1: Check if fingerprint-checkout.js uses siteId**

```bash
grep -n "siteId" /Users/ethanbaron/Documents/GitHub/DigipayMasterPlugin/secure_plugin/assets/js/fingerprint-checkout.js
```

If it IS used, replace with a non-reversible hash so the fingerprint service can still correlate events per-site without exposing the actual site ID:

- [ ] **Step 2: Replace siteId with a hashed version**

In `woocommerce-gateway-paygo.php`, change line 2690 from:

```php
'siteId'        => $this->get_option( 'siteid' ),
```

to:

```php
'siteId'        => hash( 'sha256', $this->get_option( 'siteid' ) . 'fp-salt' ),
```

This gives the fingerprint service a stable per-site identifier without exposing the actual site ID.

- [ ] **Step 3: Commit**

```bash
cd /Users/ethanbaron/Documents/GitHub/DigipayMasterPlugin/secure_plugin
git add woocommerce-gateway-paygo.php
git commit -m "security: hash siteId in frontend fingerprint config to prevent enumeration"
```
