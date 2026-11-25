# HIFQA Fraud Detector — Architecture Document

> Plugin version: 1.1.0
> Last updated: November 2026

---

## Overview

HIFQA Fraud Detector is a WordPress plugin that scores every WooCommerce order with local signal rules at checkout. Optionally, borderline orders get an AI review shortly after checkout — a tool-calling agent with OpenAI and Anthropic, a single prompt with Gemini and Ollama — with Pinecone pattern search as context, and the store's signal weights adjust to its overrides.

The plugin supports multiple AI providers — OpenAI, Anthropic, Google Gemini, and Ollama (local) — with provider-native embeddings and namespaced Pinecone indexes for full provider independence.

---

## Architecture Principles

The architecture is built around the following principles:

- Local-first operation with graceful degradation.
- Modular AI provider abstraction.
- Provider-independent vector storage.
- Merchant-specific learning.
- WordPress-native integration.
- Checkout reliability over external service availability.

---

## Core Architecture

```
New order placed (checkout request)
      ↓
Extract 16 weighted signals → local score → ALLOW / REVIEW / BLOCK
(no external requests during checkout)
      ↓
Local score in the AI band (review − 10 … block − 1, 30–59 by default)
and the AI layer configured?
      ↓ yes: as_enqueue_async_action( 'aifd_ai_score_order', … )
─────────── after checkout (Action Scheduler) ───────────
Skip if the merchant has judged the order, or it is blocked
      ↓
Take one unit of the hourly AI budget (skip + count if used up)
      ↓
Vector memory on? Build pattern text → embed (provider-native model)
→ search Pinecone in the site + provider namespace
      ↓
OpenAI / Anthropic: tool-calling agent        Gemini / Ollama: single prompt
  ├── check_signal_weights                    → JSON verdict
  ├── search_fraud_patterns
  ├── check_velocity
  └── calculate_final_risk (required)
      ↓
aifd_apply_local_floor(): keep the stricter decision and the higher score
      ↓
REVIEW / BLOCK → merchant dashboard (React)
      ↓
Merchant: Confirm Fraud OR Override
      ↓
Confirm → embed pattern → store in Pinecone namespace
Override → record false positive
        → reduce signal weights for this store
```

## Capability Tiers (Local-First Architecture)

To meet WordPress.org review requirements and protect merchants from
checkout-breaking outages, the plugin uses a three-tier capability
gate instead of requiring AI on every order.

| Tier | What it does | Default | Requires |
|------|--------------|---------|----------|
| 0 — Local | 16 weighted signals, pure PHP | Always on | Nothing |
| 1 — Vector Memory | Pinecone similarity search, as context for tier 2 | Off | Pinecone key + host, and tier 2 |
| 2 — AI Review | OpenAI / Anthropic: tool-calling agent. Gemini / Ollama: single prompt | Off | Provider API key (or Ollama host) |

Each tier wraps its external calls in a try/catch. A circuit breaker
trips after 3 consecutive failures and disables that tier for 10
minutes. `aifd_score_order()` always has a usable score from Tier 0.

### The AI review queue

`aifd_score_order()` runs Tier 0 only, so checkout never waits for an
external service. `aifd_maybe_queue_ai_scoring()` then queues an AI
review for borderline orders only:

- **Band:** `aifd_get_ai_band()` — from 10 below the review threshold up
  to 1 below the block threshold. Lower scores are clear passes and not
  worth a paid call; a local block cannot go any higher.
- **Queue:** `as_enqueue_async_action( 'aifd_ai_score_order', [ $order_id ],
  'hifqa-fraud-detector', true )`. Unique, so a resubmitted checkout
  queues one review. Action Scheduler runs on WP-Cron: on a quiet site a
  review waits for the next visit unless a real server cron job runs
  WP-Cron.
- **Job:** `aifd_run_ai_scoring()` skips orders the merchant has judged
  or that are blocked, re-checks that the AI layer is still on, takes
  one unit of the hourly budget, then runs Tier 1 and Tier 2.
- **Hourly budget:** `aifd_take_ai_budget()` — one unit per order
  reviewed (default 50 per hour, setting `aifd_ai_hourly_budget`), kept
  in a transient keyed by the hour. The counter is not atomic, so
  reviews running at the same moment can go a few over. Skipped orders
  are counted per hour in `aifd_ai_budget_skips` for a dismissible admin
  notice on the Dashboard, the WooCommerce order screens and HIFQA pages.
- **Status:** `_aifd_ai_status` on the order — `queued`, `done`,
  `failed`, `skipped` or `skipped_budget` — shown in the dashboard's
  AI Check column.
- **After checkout:** a raised BLOCK moves a pending, processing or
  on-hold order to on-hold; other statuses only get a note and the
  email. The AI never releases an order.
- **Deactivation** cancels this plugin's queued reviews (our hook in our
  group only).

### Trust boundary for the AI tier

The AI reply is untrusted input and goes through
`aifd_normalize_ai_result()` before it can touch an order:

- the decision must be exactly `ALLOW`, `REVIEW` or `BLOCK` — anything
  else is a failed call;
- the score must be numeric and is clamped to 0–100;
- the decision that is used is re-derived from the score with the
  store's thresholds (the model may ask for a stricter decision, never a
  more lenient one);
- a failed or unusable reply trips the breaker and leaves the local
  verdict in place — it is never recorded as a pass.

`aifd_apply_local_floor()` then enforces the hard rule: **the AI can only
raise.** It keeps the stricter of the local and AI decisions and the
higher of the two scores, so a local REVIEW never becomes ALLOW and a
local BLOCK is never lowered. Only the merchant's Approve / Not Fraud
action releases a blocked order.

In the tool-calling agent (OpenAI, Anthropic), `calculate_final_risk`
builds the score from what the tools actually observed on the server:
the local score, plus 10 if `check_velocity` found 3 or more orders from
the same IP or email in the window it checked. Pattern similarity adds
nothing while pattern scoring is paused. The model supplies the
explanation only; any figures it reports are ignored. The final call is
required: a model that answers in text is asked once more with
`tool_choice` forced to `calculate_final_risk` (also forced on the last
allowed round), and a second text answer is a failed call.

Order data enters the prompt through `aifd_prompt_order_facts()`, which
reduces every field to a fixed shape (number, ISO country code, gateway
id, email domain) and JSON-encodes it inside a delimited block the
model is told to treat as data. The full email address, name, IP, phone
and street address are never sent.

### Block checkout

The block checkout saves the order as a `checkout-draft` before the
customer has entered their details. Drafts are skipped on
`woocommerce_new_order` and scored on
`woocommerce_store_api_checkout_order_processed` instead.

### How a tier is activated

**The API key field is the switch.** There is no separate enable or
consent checkbox — `aifd_tier1_ready()` and `aifd_tier2_ready()` gate
purely on whether the relevant key (and host, for Pinecone and Ollama)
is present, and on whether that tier's circuit breaker is currently
open:

```php
function aifd_tier1_ready() {
    if ( ! get_option( 'aifd_pinecone_key' ) )  return false;
    if ( ! get_option( 'aifd_pinecone_host' ) ) return false;
    if ( get_transient( 'aifd_pinecone_circuit_open' ) ) return false;
    return true;
}
```

Entering a key is the merchant's affirmative act of enabling that tier;
clearing the field turns it off again and the plugin falls back to
Tier 0. This is what satisfies WordPress.org's requirement that a plugin
not contact external servers without user action — a fresh install with
empty key fields makes no outbound request at all. The services
contacted, and the data sent to each, are disclosed in `readme.txt`.

---

## Multi-Provider AI Architecture

### Strategy Pattern

The plugin uses the Strategy Pattern to decouple fraud detection logic from the AI provider. Merchants choose their preferred provider from the settings page.

```php
$provider = get_option('aifd_ai_provider', 'openai');
$result   = aifd_call_provider($signals);
// Routes internally to correct provider class
```

### Supported Providers

| Provider | Scoring Model | Embedding Model | Dimensions | Keys Needed |
|----------|--------------|-----------------|------------|-------------|
| OpenAI | gpt-4o-mini | text-embedding-3-small | 1536 | OpenAI only |
| Anthropic | claude-haiku-4-5-20251001 | voyage-3 (Voyage AI) | 1024 | Anthropic + Voyage ⚠️ |
| Google Gemini | gemini-2.5-flash | gemini-embedding-001 (768d output) | 768 | Gemini only |
| Ollama | llama3 / any | nomic-embed-text | 768 | None (free, local) |

### Provider File Structure

```
includes/
├── class-embeddings.php         ← embedding abstraction layer
├── class-pinecone.php           ← Pinecone integration
├── class-seed-loader.php        ← seed pattern loader
└── providers/
    ├── class-provider-base.php
    ├── class-provider-openai.php
    ├── class-provider-anthropic.php
    ├── class-provider-gemini.php
    └── class-provider-ollama.php
```

## Provider Switching Strategy

When a merchant switches AI providers:

1. System detects the change via updated_option hook
2. Admin notice warns merchant about namespace isolation
3. Two options presented:

   Re-embed patterns:
   → All seed patterns re-embedded with new provider model
   → All confirmed merchant patterns re-embedded
   → Stored in new namespace (e.g. merchant_1_gemini)
   → The index host must have the new provider's dimension: between
     providers with different dimensions, point the host at another
     index first
   → Old namespace preserved as backup
   → Migration takes 1-2 minutes

   Start fresh:
   → New namespace created
   → Seed patterns loaded fresh
   → Confirmed patterns NOT migrated
   → Merchant starts learning again

## Ollama Production Warning

Ollama is listed as a provider option but is only suitable for:
- Local development environments
- Self-hosted VPS with 8GB+ RAM
- Dedicated servers with full environment control

Ollama is NOT compatible with shared hosting environments
(Cloudways, SiteGround, Bluehost, WP Engine, Kinsta etc).

A clear warning is shown in the settings page when Ollama is selected.

---

## Embedding Abstraction Layer

### Embedding Strategy

All seed patterns must be embedded with the same model they will be searched with. Mixing embedding models breaks vector similarity search completely.

```
WRONG ❌
Seeds embedded with OpenAI text-embedding-3-small
Search query embedded with Gemini gemini-embedding-001
→ Different vector spaces
→ Similarity scores are meaningless
→ System returns garbage results

CORRECT ✅
Seeds embedded with provider's own model
Search query embedded with same model
→ Same vector space
→ Similarity scores are accurate
→ System finds real fraud pattern matches
```

### Embedding Model Map

```php
private $models = array(
    'openai'    => array(
        'model'     => 'text-embedding-3-small',
        'dimension' => 1536,
    ),
    'anthropic' => array(
        'model'     => 'voyage-3',        // Voyage AI — separate key needed
        'dimension' => 1024,
    ),
    'gemini'    => array(
        'model'     => 'gemini-embedding-001',
        'dimension' => 768,
    ),
    'ollama'    => array(
        'model'     => 'nomic-embed-text', // free, runs locally
        'dimension' => 768,
    ),
);
```

### Anthropic Special Case

Anthropic does not provide native embedding models. Voyage AI (voyageai.com) is Anthropic's recommended embedding partner.

Anthropic merchants need two API keys:
- Anthropic API key (for Claude scoring)
- Voyage AI API key (for embeddings)

There is no fallback. Without a Voyage key, `AIFD_Embeddings::embed()` returns false with an explanation that is shown on the settings page, in the seed loader and in the confirm-fraud response — the pattern text is never sent to a different provider.

---

## Pinecone Namespaced Architecture

### Why Namespaces

Each merchant + provider combination gets its own Pinecone namespace. This means:

- Site A using OpenAI never pollutes site B using Gemini
- Switching providers gets a fresh namespace (the index dimension
  must still match the provider)
- False positive overrides only affect the merchant who reported them
- Confirmed fraud patterns are merchant-specific, not global

### Namespace Convention

```
merchant_{site_id}_{provider}

Examples:
merchant_1_openai     → site 1, using OpenAI
merchant_1_gemini     → site 1, using Gemini
merchant_2_openai     → site 2 (multisite), using OpenAI
merchant_2_anthropic  → site 2 (multisite), using Anthropic
```

`site_id` is `get_current_blog_id()`.

### Pinecone Index Structure

The plugin talks to one index: the **Index Host URL** in Settings. Its
dimension must match the selected provider's embeddings:

| Dimensions | Used By |
|------------|---------|
| 1536 | OpenAI (text-embedding-3-small) |
| 1024 | Anthropic (voyage-3 via Voyage AI) |
| 768 | Gemini (gemini-embedding-001, `outputDimensionality: 768`) + Ollama (nomic-embed-text) |

A store that switches between providers with different dimensions
keeps one index per dimension and changes the host URL. (1.0.0 had
per-provider index-name fields; nothing read them, and 1.1.0 removed
them.)

### Seed Loading Process

Seeds are never loaded automatically. From Settings:

```
Merchant clicks "Load Seed Patterns into Pinecone"
        ↓
Detect merchant's chosen provider
        ↓
First batch only: delete the seed ids retired in 1.1.0
(AIFD_Seed_Loader::RETIRED_SEED_IDS)
        ↓
Embed the 50 seeds with THAT provider's model,
in batches of 10 per admin request
        ↓
Store in the site's namespace (e.g. merchant_1_openai)
        ↓
Repeat when the provider changes, or when an update changes the seeds
```

### What We Store in Pinecone

We never store raw order data. We store behavior pattern embeddings.
A merchant-confirmed pattern carries the same fields as a seed with a
fixed description — no order number, risk score or AI-written text — and
its vector id is `confirmed_<keyed hash>_<provider>`, not the order id.

```json
{
  "id": "seed_001",
  "values": [0.123, 0.456, ...],
  "metadata": {
    "pattern":     "disposable_email address_mismatch first_order_high_value no_phone_number cod_payment",
    "fraud_type":  "card_fraud",
    "source":      "synthetic_seed",
    "description": "Card fraud: disposable email, billing and shipping countries differ, high value first order, no phone number, cash on delivery",
    "provider":    "openai"
  },
  "namespace": "merchant_1_openai"
}
```

### Pattern Text Construction

We embed a natural language description of active signals, not raw data:

```
Active signals: disposable_email, address_mismatch,
                first_order_high_value, no_phone_number
Payment method: cod
Countries: US → GB

Pattern text:
"disposable_email address_mismatch first_order_high_value
 no_phone_number cod_payment US_to_GB"
        ↓
Embedded → 1536d vector (OpenAI) or 768d (Gemini) etc
```

---

## Signal Matrix — 16 Weighted Signals

### Tier 1 — Critical signals (high weight)

| Signal | Weight | Description |
|--------|--------|-------------|
| card_testing_pattern | 0.30 | 3+ orders from the same IP, or the same email, within 1 hour (orders without an IP are not counted as one visitor) |
| disposable_email | 0.25 | Known disposable email domain, or a random-looking address (15+ letters/digits in a row) |
| high_velocity | 0.25 | 3+ orders from the same billing email in the last 24 hours, this one included |
| address_mismatch | 0.20 | Billing country ≠ shipping country (skipped when there is no shipping country) |
| freight_forwarder | 0.20 | Shipping address line 1 contains a freight-forwarder keyword (e.g. "reship", "shipito", "myus") |

### Tier 2 — Strong signals

| Signal | Weight | Description |
|--------|--------|-------------|
| chargeback_history | 0.22 | An earlier order with this email that the merchant confirmed as fraud, or that the `aifd_order_has_dispute` filter marks (label: "Previous confirmed fraud") |
| first_order_high_value | 0.15 | First order from this email, total over 500 (store currency) |
| risky_country_combo | 0.12 | Known high-fraud billing/shipping pair |
| mismatched_names | 0.10 | Billing name ≠ shipping name |
| no_phone_number | 0.10 | No billing phone |

### Tier 3 — Contextual signals

| Signal | Weight | Description |
|--------|--------|-------------|
| payment_method_risk | 0.08 | Gateway is `cod`, `cheque` or `prepaid` |
| multiple_addresses | 0.08 | Same email, 3+ different shipping addresses (last 20 orders) |
| order_total_anomaly | 0.07 | Order > 3× the store's average order value |
| odd_hours | 0.05 | Placed before 6am store time (hours 00–05) |
| guest_checkout | 0.05 | No account |
| new_account | 0.05 | Account created < 24 hours ago |

Email matching is case-insensitive, and an empty email or IP never
matches another empty one. 1.0.0 also had an `ip_proxy_vpn` signal that
never fired; 1.1.0 removed it, and a saved weight for it is ignored.

### Scoring Formula

```
raw_score   = sum(signal_weight × signal_present)
max_possible = sum(all_weights)
normalized  = (raw_score / max_possible) × 100
final_score = min(100, normalized)
```

With the default weights `max_possible` is 2.27, so the strongest single
signal is worth 13 points. Review (40) needs at least four signals at
once and Block (60) at least six: with the local rules alone, most risky
orders get REVIEW. This dilution is the known weakness of the formula;
noisy-OR scoring and two-way weight learning are planned for 1.2.0, in
shadow mode first.

---

## Decision States

Default thresholds (used only while the store has saved none; saved
thresholds are never overwritten):

| Decision | Score Range | Action |
|----------|-------------|--------|
| ALLOW | 0 – 39 | Process order normally |
| REVIEW | 40 – 59 | Order continues; note, admin email, shown on the dashboard for the merchant's verdict |
| BLOCK | 60 – 100 | Order put on hold until the merchant releases it; note and admin email |

REVIEW is the most valuable state. False positives cost merchants more than fraud. A system that flags for review builds more trust than one that aggressively blocks.

---

## AI Function Calling

OpenAI and Anthropic run the AI review as a tool-calling agent. Gemini and Ollama do not: they get one prompt built by `aifd_build_prompt()` and reply with JSON.

The model only chooses which checks to run and writes the explanation.
Each tool runs on the server against this order; the email and IP are
never sent to the model.

| Tool | Parameters | Returns |
|------|------------|---------|
| `check_signal_weights` | `include_reasons` (bool) | Local score, risk level, fired signals and their count (and reasons), with this store's weights |
| `search_fraud_patterns` | `top_k` (1–10) | Similar patterns: similarity, fraud type, description, source (`synthetic_seed` or `merchant_confirmed`) |
| `check_velocity` | `window_minutes` (1–1440) | Order counts from the same IP and email in that window |
| `calculate_final_risk` | `reasoning` (text) | The final score and decision, computed by the store |

### How Each Provider Is Used

| Provider | Mode | Implementation |
|----------|------|----------------|
| OpenAI | Tool-calling agent | Chat Completions `tools`, forced `tool_choice` for the final call |
| Anthropic | Tool-calling agent | Messages API `tools`, forced `tool_choice` for the final call |
| Gemini | Single prompt | `aifd_build_prompt()` → JSON verdict, validated by `aifd_normalize_ai_result()` |
| Ollama | Single prompt | Same prompt and validation as Gemini |

---

## Feedback Loop

```
Order blocked or flagged for review
      ↓
Merchant sees in React dashboard
      ↓
Two actions:
├── Confirm Fraud
│     → embed behavior pattern
│     → store in merchant namespace in Pinecone
│     → source: merchant_confirmed (context for later AI reviews;
│       it does not change any score)
│     → count the order once in the confirmed-fraud count and value
│
└── Override (not fraud — false positive)
      → record as false positive
      → do NOT store in Pinecone
      → trigger per-merchant weight adjustment
      → log fired signals for analysis
```

---

## Per-Merchant Weight Personalization

### The Problem

Global weights suit average stores. A luxury jewelry store has very different fraud patterns from a $10 digital download shop.

### The Solution

Each merchant gets a weight profile that adjusts automatically based on their override history.

```
Override happens
      ↓
Record which signals fired on this false positive
      ↓
Reduce those signal weights by delta (0.02)
for this merchant only — never globally
      ↓
Save as merchant_weight_profile in wp_options
      ↓
Next order uses personalized weights
```

Weights only move down for now, so a store that overrides often slowly
dilutes every signal. Two-way learning (Beta prior, with confirmed fraud
pushing weights up) is planned for 1.2.0.

### Weight Adjustment Formula

```php
$delta = 0.02; // small reduction per override
$floor = 0.01; // never go below this

foreach ($fired_signals as $signal) {
    $weights[$signal] = max(
        $floor,
        $weights[$signal] - $delta
    );
}

update_option('aifd_merchant_weights', $weights);
```

### Why Per-Merchant Not Global

```
Digital goods store
→ odd_hours weight reduced over time
   (customers legitimately buy at 3am)

Physical goods store
→ odd_hours weight stays high
   (3am physical orders are suspicious)
```

---

## Seed Pattern Dataset

`data/seed-patterns.json` holds 50 **synthetic** patterns, loaded by
hand from Settings into the site's Pinecone namespace. They are made-up
examples, not records of real fraud. Every seed carries
`"source": "synthetic_seed"`, uses only tokens the plugin itself
produces (signal names, `{gateway}_payment`), and has no counts or
confidence values. The AI is told that a seed match is not evidence on
its own.

| fraud_type | Count |
|------------|-------|
| card_fraud | 16 |
| reshipping_fraud | 9 |
| card_testing | 8 |
| chargeback_fraud | 7 |
| account_takeover | 4 |
| identity_fraud | 3 |
| legitimate | 3 |

1.0.0 shipped 65 seeds with invented `times_seen` and `confidence`
values. In 1.1.0, 11 legitimate seeds were dropped because none of their
tokens is ever produced at runtime, and 4 duplicates were merged.
Pattern scoring is paused until a replay evaluation shows whether
pattern context helps at all.

---

## Platform Architecture

```
WooCommerce (WordPress Hook) ─────────┐
EDD (WordPress Hook) ─────────────────├──► AI Fraud Engine (PHP)
SureCart (WordPress Hook) ────────────┤    ├── 16 signal extraction
Shopify (Webhook → Python API) ───────┘    ├── Embedding abstraction
                                           ├── Pinecone namespace search
                                           ├── Provider routing
                                           └── Weight personalization
```

Only WooCommerce exists today. EDD, SureCart and Shopify are planned:
WordPress hooks for EDD and SureCart, and a webhook to a separate
Python/FastAPI service on Azure for Shopify.

---

## Settings Page Structure

```
AI Provider
├── Choose: OpenAI / Anthropic / Gemini / Ollama
└── Hourly AI Limit: 50 (default, 1–1000)

OpenAI Settings
├── API Key (used for scoring + embeddings)
└── Model: gpt-4o-mini | gpt-4o

Anthropic Settings
├── API Key (Claude — for scoring only)
├── Voyage AI Key ⚠️ (for embeddings — separate key)
└── Model: claude-haiku-4-5-20251001 | claude-sonnet-4-6

Google Gemini Settings
├── API Key (used for scoring + embeddings)
└── Model: gemini-2.5-flash | gemini-2.5-pro

Ollama Settings
├── Host URL (default: http://localhost:11434)
└── Model (any installed chat model; embeddings always use nomic-embed-text)

Pinecone Settings
├── API Key
└── Index Host URL (its dimension must match the provider)

Seed Patterns
└── [Load Seed Patterns into Pinecone] button

Risk Thresholds
├── Block threshold: 60 (default when none is saved)
└── Review threshold: 40 (default)
```

---

## Technology Stack

| Layer | Technology |
|-------|------------|
| WordPress plugin | PHP 8.0+ |
| Frontend dashboard | React + @wordpress/scripts |
| REST API | WordPress REST API |
| AI scoring (OpenAI) | GPT-4o-mini (function calling) |
| AI scoring (Anthropic) | claude-haiku-4-5-20251001 (tool use) |
| AI scoring (Gemini) | gemini-2.5-flash (function declarations) |
| AI scoring (local) | Ollama (any model, free, private) |
| Embeddings (OpenAI) | text-embedding-3-small (1536d) |
| Embeddings (Anthropic) | voyage-3 via Voyage AI (1024d) |
| Embeddings (Gemini) | gemini-embedding-001 (768d output) |
| Embeddings (Ollama) | nomic-embed-text (768d) |
| Vector store | Pinecone serverless (namespaced) |
| Future: Shopify API | Python + FastAPI + Azure |

---

## Full File Structure

```
hifqa-fraud-detector/
├── hifqa-fraud-detector.php                # Main plugin file — constants + requires
├── readme.txt                           # WordPress.org readme (ships in the zip)
├── README.md                            # GitHub readme (dev-only, export-ignored)
├── ARCHITECTURE.md                      # This document (dev-only, export-ignored)
├── ROADMAP.md                           # Development roadmap (dev-only)
├── LICENSE                              # GPL-2.0-or-later
├── SECURITY.md                          # Vulnerability reporting policy
├── package.json                         # Node dependencies (dev-only)
├── includes/
│   ├── fraud-detection.php              # Signal extraction, scoring, order actions
│   ├── rest-api.php                     # REST API + AJAX handlers
│   ├── admin-menu.php                   # Admin menu + React loader
│   ├── settings-page.php                # Settings page + registration
│   ├── class-embeddings.php             # Embedding abstraction layer
│   ├── class-pinecone.php               # Pinecone integration
│   ├── class-seed-loader.php            # Seed pattern loader
│   ├── class-function-calling.php       # Function-calling agent + tool defs
│   └── providers/
│       ├── class-provider-base.php      # Shared interface
│       ├── class-provider-openai.php    # OpenAI
│       ├── class-provider-anthropic.php # Anthropic
│       ├── class-provider-gemini.php    # Google Gemini
│       └── class-provider-ollama.php    # Local Ollama
├── admin/
│   └── dashboard.php                    # React mount point
├── src/
│   └── index.js                         # React dashboard
├── build/                               # Compiled React (ships in the zip)
└── data/
    └── seed-patterns.json               # 50 synthetic seed patterns
```

The release zip is produced by `git archive`, so anything marked
`export-ignore` in `.gitattributes` (this file, `README.md`,
`ROADMAP.md`, `package.json`, build config) is stripped from what
merchants actually install.

---

## Security Considerations

### How API keys are stored

Keys are registered through the WordPress Settings API and saved as
plain options in `wp_options`:

```php
register_setting( 'aifd_settings', 'aifd_openai_key', array(
    'type'              => 'string',
    'sanitize_callback' => function ( $value ) {
        return aifd_sanitize_api_key( $value, 'aifd_openai_key' );
    },
    'default'           => '',
) );
```

Saving goes through core's `options.php` handler, which enforces the
`manage_options` capability and verifies the settings-group nonce
before any value is written. Every setting has its own sanitizer: keys
are text-sanitized and kept when the field is left blank, the provider
and model are checked against a whitelist, thresholds are clamped to
0–100, the Pinecone host must be an `https://` URL and the Ollama host
`http://` or `https://`.

**The keys are not encrypted at rest.** They are stored as plaintext
option rows, which is the standard WordPress pattern — an encryption
key would itself have to live on the same filesystem, so it would add
obfuscation rather than a real security boundary. The practical
consequences:

- Anyone with database read access can recover the keys, and so can
  an administrator who is able to run code on the site.
- The settings page never prints a saved key. The field is rendered
  empty; a blank submission keeps the stored key, a new value replaces
  it, and "Remove the saved key" deletes it.
- The Gemini key is sent in the `x-goog-api-key` header, never in the
  URL. Error text that is logged or stored passes through
  `aifd_redact_secrets()`.
- Keys are therefore only as protected as the WordPress admin account
  and the database behind it.

Merchants who need stronger guarantees should scope their provider keys
to the minimum permissions their provider offers, rotate them
periodically, or run the Ollama tier, which requires no third-party key
at all.

### Other controls

- All REST endpoints declare a `permission_callback` that checks
  `current_user_can( 'manage_options' )`.
- AJAX handlers verify the `aifd_settings_nonce` nonce **and** re-check
  `manage_options` before acting.
- All settings input is sanitized on save (see above).
- Pinecone stores behavior-pattern embeddings and signal metadata — not
  raw order records, names, addresses, or email addresses.

### Data protection

Tier 0 sends nothing off-site, and checkout itself makes no external
request. Tiers 1 and 2 transmit the derived pattern text and signal
summary to the merchant's chosen provider only once that provider's API
key has been entered, and only in the queued AI review of a borderline
order (or when the merchant loads seeds or confirms fraud) — an empty
key field means no external request is made. The pattern text is a list of signal
names (e.g. `disposable_email address_mismatch no_phone_number`) plus the
gateway id and country codes. The AI tier additionally receives the
order total, the billing email's **domain**, the previous-order count and
the store average. `readme.txt` lists every field per service and is
kept in step with `aifd_prompt_order_facts()`.

This is a design property, not a legal opinion: whether a given
deployment is GDPR-compliant depends on the merchant's own processing
and disclosures. Merchants remain the data controller. The Ollama tier
exists for deployments with strict data-sovereignty requirements.

---

## Design Choices Worth Calling Out

The decisions below are the ones that most shape how the plugin behaves,
and the reasoning behind each:

- **Local-first tiers.** Tier 0 is pure PHP and always available, so a
  provider outage degrades accuracy rather than losing the order.
- **AI after checkout, borderline orders only.** Checkout never waits
  for a provider, clear passes and local blocks cost nothing, and an
  hourly limit stops a bot burst from turning into a burst of paid
  calls. The price is that a review can lag on a quiet site.
- **The AI can only raise.** The local verdict is the floor, so a wrong
  or manipulated AI reply can cost a manual review, never a missed
  block.
- **Provider-native embeddings.** Mixing embedding models within one
  vector space makes similarity scores meaningless, so each provider
  embeds and searches with its own model.
- **Namespaced vector store.** One namespace per merchant + provider
  keeps confirmed patterns and false-positive corrections scoped to the
  merchant who reported them.
- **Per-merchant weight learning.** A luxury jeweller and a $10 digital
  download shop have genuinely different fraud profiles; a single set of
  global weights cannot serve both.
- **REVIEW as the default middle outcome.** False positives cost
  merchants more than the fraud they prevent, so the mid-range decision
  flags an order for a human and lets it continue, rather than holding
  or blocking it.
- **Ollama support.** Gives merchants with data-sovereignty constraints
  a path that sends data only to a host they run themselves.

---

