# HIFQA Fraud Detector

> Fraud scoring for WooCommerce: 16 weighted signals checked locally
> on every order, with an optional AI review (OpenAI, Anthropic, Gemini
> or Ollama) of borderline orders after checkout.

> **Status:** covered by an automated test suite (PHPUnit, on both WooCommerce order storage modes).
> Not yet evaluated on real merchant data: the seed patterns are synthetic and the signal weights are set by hand.

---

## What It Does

HIFQA Fraud Detector scores every WooCommerce order
at checkout against 16 weighted fraud signals, in
plain PHP on your own server. Optionally, orders with
a borderline score get an AI review shortly after
checkout, which can raise the decision and adds a
plain-English explanation.

Orders are automatically:
- ✅ **Allowed** (score 0–39) — processed normally
- ⚠️ **Flagged for review** (score 40–59) — the order continues, merchant notified
- 🚫 **Blocked** (score 60–100) — put on hold + email alert sent

Both thresholds are configurable; these are the defaults for a store
that never saved its own (saved thresholds are kept on update).

With the local rules alone, most risky orders get Review rather than
Block: the default weights add up to 2.27, so even the strongest
signal is only 13 points, Review needs at least four signals at once
and Block at least six. The scoring formula is due to be reworked.

---

## Local-First by Design

The plugin runs in three capability tiers, and the bottom one needs
nothing at all:

| Tier | What it does | Default | Requires |
| --- | --- | --- | --- |
| **0 — Local** | 16 weighted signals, pure PHP | Always on | Nothing |
| **1 — Vector Memory** | Pinecone similarity search, as context for tier 2 | Off | Pinecone key + host, and tier 2 |
| **2 — AI Review** | OpenAI / Anthropic: tool-calling agent. Gemini / Ollama: single prompt | Off | Provider API key (or Ollama host) |

**Checkout runs tier 0 only and makes no external requests.** Orders
whose local score is borderline (from 10 below the review threshold to
just under the block threshold: 30–59 by default) are queued with
WooCommerce's Action Scheduler and get the tier 1 + 2 review shortly
after checkout. Lower scores are never sent to the AI, and a local
block is not sent either. Action Scheduler runs on WP-Cron, so on a
quiet site a review can wait for the next visit; a real server cron
job fixes that.

- An hourly limit (default 50) caps paid AI reviews. Orders over the
  limit keep their local result, and admins get a notice with the count.
- A circuit breaker pauses a tier for ten minutes after three
  consecutive failures. An error inside scoring is caught and logged
  rather than allowed to break the checkout request.
- The AI review can move a pending or paid order to on-hold after
  checkout; completed, cancelled, refunded and failed orders only get a
  note and the email.

The AI reply is treated as untrusted input. The decision is
whitelisted, the score is clamped to 0–100 and the decision re-derived
from it, and **the AI can only raise a decision, never lower it** — a
local Review stays at least a Review, and only the merchant can release
a block. An unusable reply counts as a failure, never as a pass.

Tiers 1 and 2 are activated by entering an API key, and deactivated by
clearing it. **On a fresh install with empty key fields, no order data
leaves your site.**

---

## Key Features

**🤖 AI Review: Agent or Single Prompt**
With OpenAI and Anthropic, the AI review runs as a tool-calling agent:
`check_signal_weights` → `search_fraud_patterns` → `check_velocity` →
`calculate_final_risk`. The final call is required, and the score is
computed by the plugin from what the tools observed (the local score,
plus 10 if the velocity check is suspicious); the model only supplies
the explanation. Gemini and Ollama get a single prompt with the order
facts and pattern context and reply with JSON, which is validated and
can only raise the local decision.

**🧠 Vector Memory (Pinecone)**
50 synthetic seed patterns (47 fraud, 3 legitimate) that you load from
Settings, plus the patterns you confirm as fraud. The closest matches
are given to the AI review as context. Pattern scoring is paused: a
match does not change the score until it can be evaluated.

**⚖️ Per-Merchant Learning**
Each false positive override lowers the weight of the signals that
fired, for that store only (by 0.02, never below 0.01). Weights only
move down for now; two-way learning is planned.

**🔌 Multi-Provider AI**
Choose your AI provider:
- OpenAI (gpt-4o-mini) — default
- Anthropic (claude-haiku-4-5-20251001)
- Google Gemini (gemini-2.5-flash)
- Ollama (self-hosted, free) ⚠️ VPS only

**📊 Provider-Native Embeddings**
Each provider uses its own embedding model with 
separate Pinecone namespaces — no cross-provider 
contamination, and no cross-provider fallback: if the
selected provider cannot embed (for example Anthropic
without a Voyage AI key), vector memory stays off
rather than sending the text somewhere else:
- OpenAI → text-embedding-3-small (1536d)
- Anthropic → voyage-3 via Voyage AI (1024d)
- Gemini → gemini-embedding-001 (reduced to 768d)
- Ollama → nomic-embed-text (768d)

**🔄 Feedback Loop**
- Confirm Fraud → saves the signal pattern to Pinecone — no order number, customer detail or AI-written text
- Override (Not Fraud) → adjusts merchant signal weights

**⚛️ React Dashboard**
Modern React dashboard built with @wordpress/scripts:
- Stats (blocked, review, allowed, confirmed fraud value)
- Flagged orders with the reason for each decision and the AI review status
- Signal weight visualization per tier
- Feedback buttons per order

---

## Architecture

```
New order (checkout)
  ↓
16 weighted signals → local score → ALLOW / REVIEW / BLOCK
  ↓
Borderline score (30–59 by default)? → queue an AI review
  ↓  (shortly after checkout, Action Scheduler, hourly limit)
Embed pattern text (provider-native model) → search Pinecone namespace
  ↓
OpenAI / Anthropic agent:            Gemini / Ollama:
├── check_signal_weights()           single prompt → JSON verdict
├── search_fraud_patterns()
├── check_velocity()
└── calculate_final_risk()
  ↓
Can only raise the local decision (REVIEW → BLOCK puts the order on hold)
  ↓
Merchant feedback → confirmed patterns + per-store weights
```

---

## Tech Stack

| Layer | Technology |
|-------|------------|
| Plugin | PHP 8.0+ |
| Frontend | React + @wordpress/scripts |
| REST API | WordPress REST API |
| AI Scoring | OpenAI / Anthropic / Gemini / Ollama |
| Embeddings | Provider-native models |
| Vector Store | Pinecone (serverless, namespaced) |
| Design Pattern | Strategy Pattern (provider abstraction) |

---

## Signal Matrix

16 signals; the weights add up to 2.27.

### Tier 1 — Critical
| Signal | Weight |
|--------|--------|
| Card Testing Pattern | 0.30 |
| Disposable Email | 0.25 |
| High Velocity | 0.25 |
| Address Mismatch | 0.20 |
| Freight Forwarder | 0.20 |

### Tier 2 — Strong
| Signal | Weight |
|--------|--------|
| Previous Confirmed Fraud | 0.22 |
| First Order High Value | 0.15 |
| Risky Country Combo | 0.12 |
| Mismatched Names | 0.10 |
| No Phone Number | 0.10 |

### Tier 3 — Contextual
| Signal | Weight |
|--------|--------|
| Payment Method Risk | 0.08 |
| Multiple Addresses | 0.08 |
| Order Total Anomaly | 0.07 |
| Odd Hours | 0.05 |
| Guest Checkout | 0.05 |
| New Account | 0.05 |

---

## Installation

1. Upload the plugin to `/wp-content/plugins/`
2. Activate it in WordPress admin
3. Done — every new order is now scored by the local signal engine.

No API key, no account, and no configuration are required to get
working fraud detection. The AI and vector layers are opt-in:

**To enable Vector Memory (optional)**
Go to **HIFQA → Settings**, enter a Pinecone API key + host
URL, then click **Load Seed Patterns into Pinecone**. The index
dimension must match the provider: 1536 (OpenAI), 1024 (Anthropic via
Voyage AI) or 768 (Gemini, Ollama). Vector memory only runs as part of
the AI review.

**To enable the AI review (optional)**
On the same page, choose a provider and enter its API key — or point
the plugin at a local Ollama host and pay nothing. Set the hourly
limit, and on a quiet site set up a real server cron job for WP-Cron
so queued reviews run promptly.

Saved keys are never printed back into the page. Leave a key field blank
to keep the saved key; tick **Remove the saved key** to turn that layer
back off.

Deleting the plugin removes its settings, keys, counters, learned
weights and queued AI reviews (`uninstall.php`; deactivating also
removes the queued reviews). Orders and the fraud result saved on each
order are left alone.

---

## Requirements

- WordPress 6.6+ (the dashboard depends on the `react-jsx-runtime` script that ships from 6.6)
- WooCommerce (HPOS and the block checkout are both supported). The AI
  review queue uses WooCommerce's bundled Action Scheduler.
- PHP 8.0+

That's the whole list. API keys are only needed for the optional layers:

| To use | You need |
| --- | --- |
| Local signal scoring (Tier 0) | Nothing — works on activation |
| Vector memory (Tier 1) | A Pinecone account (free starter plan works), plus Tier 2 |
| AI review (Tier 2) | An OpenAI, Anthropic, or Gemini key — or a local Ollama host (free) |

Anthropic users also need a Voyage AI key for embeddings, since
Anthropic does not offer an embedding model of its own.

---

## Running Tests

The suite uses the WordPress core test library with WooCommerce loaded. It
needs an **empty** MySQL database — every table in it is dropped on each run.

```bash
composer install
mysql -uroot -proot -e "CREATE DATABASE hifqa_tests"
composer test
```

Defaults in `tests/wp-tests-config.php` target a MAMP setup (WordPress three
folders up, MAMP's MySQL socket). Override with `WP_CORE_DIR`,
`WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASS`, `WP_TESTS_DB_HOST`
or `WC_DIR`. All outgoing HTTP is faked, so no API keys are used.

---

## Roadmap

- [x] WooCommerce integration
- [x] 16 fraud signal extraction
- [x] Multi-provider AI (OpenAI / Anthropic / Gemini / Ollama)
- [x] Provider-native embeddings
- [x] Pinecone vector store (namespaced)
- [x] Function calling agent
- [x] React dashboard
- [x] Feedback loop
- [x] Per-merchant weight personalization
- [x] Local-first capability tiers + circuit breakers
- [x] Unit and integration tests
- [x] HPOS + block checkout support
- [x] WordPress.org release (1.0.0)
- [x] AI review after checkout, borderline orders only, hourly limit (1.1.0)
- [ ] Decision log, labels and two-way weight learning (1.2.0)
- [ ] Replay evaluation on synthetic data (1.3.0)
- [ ] Easy Digital Downloads (EDD)
- [ ] SureCart
- [ ] Shopify (Python + FastAPI + Azure)

See [ARCHITECTURE.md](ARCHITECTURE.md) for the full design document.

---

## Releasing

The WordPress.org package is **not** a zip of this working directory. It
is built from committed files only, with development files stripped by
the `export-ignore` rules in `.gitattributes`:

```bash
npm ci && npm run build      # rebuild build/ from src/ and commit the result
git archive --format zip --prefix=hifqa-fraud-detector/ \
    --output hifqa-fraud-detector.zip HEAD
```

Pushing a tag runs the same `git archive` in `.github/workflows/release.yml`,
after checking that the committed `build/` matches `src/`. Run Plugin Check
against the extracted archive, not against this directory.

---

## Security

API keys are stored as standard WordPress options, in plaintext — the
same as most WordPress plugins. See the Security Considerations section
of [ARCHITECTURE.md](ARCHITECTURE.md#security-considerations) for what
that does and does not protect against.

Found a vulnerability? Please see [SECURITY.md](SECURITY.md) — do not
open a public issue.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

---