=== HIFQA Fraud Detector for WooCommerce ===
Contributors: HIFQA
Tags: fraud, woocommerce, security, ai, vector search
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fraud scoring for WooCommerce. Local signal scoring works out of the box; an AI review of borderline orders and vector search are optional.

== Description ==

HIFQA Fraud Detector scores every WooCommerce order against 16 weighted
fraud signals (disposable emails, address mismatches, card testing
patterns, and more) the moment it is placed. The local scoring engine
is pure PHP, requires no setup, and sends no data anywhere — it works
immediately after activation.

Each order gets a score from 0 to 100 and one of three decisions:

* **Allow** — the order continues normally.
* **Review** — the order continues, a note is added and the store admin is emailed.
* **Block** — the order is put on hold and the store admin is emailed. It stays on hold until you release it.

By default, Review starts at a score of 40 and Block at 60 (stores
that saved their own thresholds keep them). With the local rules
alone, most risky orders get Review rather than Block: Review needs at
least four signals at once, and Block at least six. The scoring
formula will be reworked in a later version.

For stores that want a second opinion, two optional layers can be
enabled — each stays off until you enter that service's API key in
Settings:

* **AI review** — after checkout, orders with a borderline score are
  sent to OpenAI, Anthropic, Google Gemini, or an Ollama server you run
  yourself. With OpenAI and Anthropic the model works as a tool-calling
  agent: it asks the plugin for the local score, similar patterns and
  recent order counts, and the plugin computes the final score from
  those results. Gemini and Ollama get a single prompt with the order
  facts and reply with a score, a decision and an explanation.
* **Vector pattern memory** — finds the stored patterns closest to the
  order's pattern of signals in your own Pinecone index and gives them
  to the AI review as context. It runs only as part of the AI review.
  The bundled seed patterns are synthetic examples, not records of real
  fraud, and a match does not change the score: pattern scoring is
  paused until it can be evaluated.

= When the AI review runs =

Checkout itself makes no external requests: every order is scored by
the local rules while it is placed. Orders whose local score is
borderline — from 10 points below the Review threshold up to just
under the Block threshold, so 30 to 59 by default — are queued for an
AI review that runs shortly after checkout. Orders scoring below that
band are never sent to the AI, and orders the local rules already
block are not sent either.

* The queue is WooCommerce's own Action Scheduler, which runs on
  WP-Cron. On a site with little traffic the review can be delayed
  until the next visit. For prompt reviews, set up a real server cron
  job for WP-Cron.
* The AI review can raise a decision after checkout, for example from
  Review to Block. A Block moves a paid (processing) or pending order
  to On hold. Orders that are already completed, cancelled, refunded
  or failed are not moved: they get a note and the admin email
  instead.
* There is an hourly limit on AI reviews (50 by default, set in
  Settings). Orders over the limit keep their local result. If that
  happened in the last 24 hours, admins see a notice with the count on
  the Dashboard, the WooCommerce order screens and the HIFQA pages. It
  can mean a bot attack, or a limit set too low for your store.
* The HIFQA dashboard shows each flagged order's AI review status:
  queued, done, failed, skipped, or skipped because of the hourly
  limit.

= How the AI review is kept in check =

The local score is the baseline, and an AI reply is treated as
untrusted input:

* The AI review can only raise a decision and a score, never lower
  them. A local Review stays at least a Review, and a local Block
  stays blocked. Only you can release a blocked order.
* A reply with a missing or unexpected decision, or a score that is
  not a number, counts as a failed call. So does an agent that never
  calls its final scoring step. Scores are limited to 0–100 and the
  decision is re-derived from the score using your thresholds.
* With OpenAI and Anthropic, any figures the model reports are
  ignored: the score is the local score plus 10 points if the plugin's
  own velocity check found 3 or more orders from the same IP address
  or email in the time window it checked.
* A failed AI call never marks an order as passed. The order keeps its
  local result and gets a note saying the AI layer was unavailable.
  After three failures in a row the AI review pauses for ten minutes.
* The review never runs on an order you have already approved,
  blocked, confirmed as fraud or marked as legitimate.

= Compatibility =

* WooCommerce High-Performance Order Storage (HPOS).
* Both the classic checkout and the block-based checkout.

= Source code =

The admin dashboard is built from the unminified source in the `src/`
folder of this plugin. The full development repository, including the
build tooling, is at
https://github.com/dilrubaummer/hifqa-fraud-detector

== External services ==

HIFQA Fraud Detector can connect to the services below. Every one of
them is **off by default**. Nothing is sent until you save that
service's API key (or, for Ollama, its host URL) in
HIFQA → Settings, and removing the key turns the layer off again. If
no optional layer is configured, no order data leaves your site.

Customer names, full email addresses, IP addresses, phone numbers,
street addresses and order items are never sent to any of these
services.

**What "order data" means below.** When the AI review is on, the
following is sent to the AI provider you selected, once for each order
that gets an AI review (see "When the AI review runs"):

* the order total
* the domain of the billing email address (for example `example.com` —
  never the address itself)
* the billing country and shipping country codes
* the payment method ID (for example `stripe` or `cod`)
* how many earlier orders used the same billing email (counted up to 20)
* your store's average order value
* which of the fraud signals fired for the order (yes/no values such
  as "disposable email", "billing and shipping country differ", "no
  phone number provided", "guest checkout")

If vector memory is also on, the closest matches found in your
Pinecone index are included (pattern type, similarity, source label
and the stored description of the pattern).

With OpenAI and Anthropic, which run as a tool-calling agent, the
plugin also sends the results of its own checks when the model asks
for them: the local risk score with the
reasons behind it, and how many recent orders came from the same IP
address and from the same email (the numbers only — not the IP address
or the email).

**What "pattern text" means below.** When vector memory is on, an
order that gets an AI review is reduced to a short line of text made of the names of
the signals that fired, the payment method ID and the country pair —
for example `disposable_email address_mismatch cod_payment NG_to_US`.

= OpenAI =

Used for the AI review when OpenAI is the selected provider, and to
turn pattern text into an embedding when vector memory is on with
OpenAI selected.

* Data sent: order data (AI review); pattern text (vector memory).
* When: shortly after checkout, for each order that gets an AI review
  (borderline local score, within your hourly limit); and when you load
  seed patterns or confirm an order as fraud.
* Endpoint: api.openai.com
* [Terms of Service](https://openai.com/policies/services-agreement/)
* [Privacy Policy](https://openai.com/policies/privacy-policy)

= Anthropic =

Used for the AI review when Anthropic (Claude) is the selected
provider.

* Data sent: order data.
* When: shortly after checkout, for each order that gets an AI review
  (borderline local score, within your hourly limit).
* Endpoint: api.anthropic.com
* [Terms of Service](https://www.anthropic.com/legal/commercial-terms)
* [Privacy Policy](https://www.anthropic.com/legal/privacy)

= Google Gemini =

Used for the AI review when Google Gemini is the selected provider,
and to turn pattern text into an embedding when vector memory is on
with Gemini selected.

* Data sent: order data (AI review); pattern text (vector memory).
* When: shortly after checkout, for each order that gets an AI review
  (borderline local score, within your hourly limit); and when you load
  seed patterns or confirm an order as fraud.
* Endpoint: generativelanguage.googleapis.com
* [Terms of Service](https://ai.google.dev/gemini-api/terms)
* [Privacy Policy](https://policies.google.com/privacy)

= Voyage AI =

Anthropic does not offer an embedding model, so vector memory with
Anthropic selected uses Voyage AI and needs its own Voyage AI key.
Without that key, vector memory stays off for Anthropic — the pattern
text is not sent to any other provider in its place.

* Data sent: pattern text.
* When: shortly after checkout, for each order that gets an AI review
  while vector memory is on; and when you load seed patterns or confirm
  an order as fraud.
* Endpoint: api.voyageai.com
* [Terms of Service](https://www.voyageai.com/tos)
* [Privacy Policy](https://www.voyageai.com/privacy)

= Pinecone =

Stores and searches fraud patterns for the vector memory layer, in an
index in your own Pinecone account.

* Data sent: the embedding (a list of numbers) of the pattern text,
  and a namespace made of your site's numeric ID and the provider
  name. Stored patterns also carry the pattern text itself, a pattern
  type, a source label and a short fixed description. When you
  confirm an order as fraud, its pattern is stored the same way: no
  order number, risk score, customer detail or
  AI-written text is included, and the record's ID is a keyed hash,
  not the order number.
* When: a search shortly after checkout, for each order that gets an
  AI review while vector memory is on; a write when you load seed
  patterns or confirm an order as fraud. Loading seed patterns also
  deletes the seed patterns that 1.1.0 retired from your index.
* Endpoint: the index host URL you enter (HTTPS only, normally
  `*.pinecone.io`)
* [Terms of Service](https://www.pinecone.io/terms/)
* [Privacy Policy](https://www.pinecone.io/privacy/)

= Ollama =

Ollama is software you run on your own server. When Ollama is the
selected provider, the plugin sends requests to the host URL you enter
in Settings. Nothing is sent to ollama.com by this plugin. The data
stays on your own infrastructure only if that host is a server you
control.

* Data sent to your Ollama host: order data (AI review); pattern text
  (vector memory).
* When: shortly after checkout, for each order that gets an AI review
  (borderline local score, within your hourly limit); and when you load
  seed patterns or confirm an order as fraud.
* [Terms of Service](https://ollama.com/terms)
* [Privacy Policy](https://ollama.com/privacy)

== Installation ==

1. Upload and activate the plugin. WooCommerce must be active.
2. Local fraud scoring is active immediately — no setup required.
3. (Optional) Go to HIFQA → Settings to enable the AI review, and
   vector memory alongside it. Each activates only once you save its
   API key; leave the key field blank to keep the layer off.
4. (Optional, with the AI review on) Set up a real server cron job for
   WP-Cron, so queued AI reviews run promptly on a quiet site.

== Frequently Asked Questions ==

= Does this work without any API keys? =
Yes. Local signal scoring runs on every order automatically with no
external service required.

= What happens if my AI provider is slow or stops working? =
Checkout never waits for it: the AI review runs after checkout. If the
provider fails or times out, the order keeps its local result and the
order note says so. After three failures in a row the AI review is
paused for ten minutes. A provider outage never causes an order to be
marked as passed.

= Can the AI review lower a decision, or approve a blocked order? =
No. It can only raise a decision. A local Review stays at least a
Review, and a local Block stays a block until you release it from the
HIFQA dashboard.

= Why do so few orders get blocked without the AI review? =
Each signal adds a share of the score, and even the strongest signal
adds only 13 points. By default, Review needs at least four signals at
once and Block at least six, so most risky orders get Review. You can lower the Block threshold in Settings. The scoring
formula will be reworked in a later version.

= My AI reviews show "Queued" for a long time. Why? =
Queued reviews run through WooCommerce's Action Scheduler on WP-Cron,
which only runs when someone visits the site. On a quiet site, set up
a real server cron job for WP-Cron.

= What are the seed patterns? =
50 synthetic example patterns (47 fraud, 3 legitimate) you can load
into Pinecone from Settings. They are made-up examples, not records of
real fraud, and the AI is told so. If you loaded them with 1.0.0, load
them again: 1.1.0 changed them and removes the retired ones.

= Is customer data shared with anyone by default? =
No. Out of the box, all scoring runs locally on your own server and no
order data leaves your site. Data is sent to a third party only after
you save that service's API key in Settings. See the External services
section for exactly what is sent to each one.

= Where are my API keys stored, and who can see them? =
Keys are stored in your site's database as plugin options. Once saved,
a key is never shown again in the Settings page — the field stays
empty. Leave it empty to keep the saved key, type a new key to replace
it, or tick "Remove the saved key" to delete it.

= I use Anthropic. Why is vector memory not running? =
Anthropic has no embedding model. Add a Voyage AI key in Settings to
use vector memory with Anthropic. The plugin will not send your data
to a different provider instead.

= What is removed when I delete the plugin? =
Deleting the plugin removes its settings, saved API keys, learned
signal weights, counters and queued AI reviews (deactivating it also
removes the queued AI reviews). Your orders are not touched, and the
fraud result saved on each order (score, decision, reason) stays with
the order as part of its history. Patterns stored in your Pinecone
index stay in your Pinecone account until you delete them there.

== Screenshots ==

1. Fraud dashboard — every flagged order with its risk score, decision, country route, and one-click Confirm Fraud / Not Fraud feedback buttons.
2. Signal Weight Personalization — the 16 signal weights adapt automatically when you override false positives, so the system learns your store's patterns.
3. Settings — choose your AI provider (OpenAI, Anthropic, Google Gemini, or self-hosted Ollama) and configure each optional layer.

== Changelog ==

= 1.1.0 =
* Changed: checkout runs the local rules only and makes no external requests. The AI review runs shortly after checkout, only for borderline orders, through WooCommerce's Action Scheduler.
* New: hourly limit on AI reviews (default 50), with an admin notice when orders were skipped because of it.
* New: "AI Check" column on the HIFQA dashboard.
* Changed: the AI review can only raise a decision. It can no longer lower a local Review to Allow.
* Changed: a plain-text reply, or an agent that never calls its final scoring step, counts as a failed AI call.
* Changed: the agent score is no longer doubled; it is the local score plus what the plugin's own checks found.
* Changed: pattern scoring is paused: a similar pattern no longer adds to the score, and stays context for the AI only.
* Changed: the seed patterns are now 50 synthetic examples that use the plugin's own signal names, with no made-up counts. Load them again if you use vector memory.
* Changed: the default Block threshold is 60 for stores that never saved one. Saved thresholds are not changed.
* Changed: the dashboard shows the value of orders you confirmed as fraud, instead of the value of all blocked orders.
* Changed: the odd-hours signal is labelled "before 6am (store time)", which is what it always checked.
* Fixed: "high velocity" counts orders from the last 24 hours, not the customer's whole history.
* Fixed: "previous confirmed fraud" (formerly chargeback history) counts only orders you confirmed as fraud, not words in order notes. Developers can add disputes with the aifd_order_has_dispute filter.
* Fixed: orders with nothing to ship no longer count as a billing/shipping country mismatch.
* Fixed: email matching ignores upper and lower case.
* Fixed: orders without an IP address (created in wp-admin) no longer count as one visitor for card testing.
* Fixed: confirming an order as fraud twice counts it once.
* Fixed: confirming fraud no longer rewrites another pattern stored in Pinecone.
* Fixed: deactivating the plugin removes its queued AI reviews.
* Fixed: a Plugin Check error in the settings nonce check (no change in behaviour).
* Removed: the IP proxy / VPN signal, which never detected anything. There are now 16 signals.
* Removed: the per-provider Pinecone index name fields, which were never used.

= 1.0.0 =
* Initial release — local-first scoring with optional AI and vector memory layers.

== Upgrade Notice ==

= 1.1.0 =
The AI review now runs after checkout, only for borderline orders, with an hourly limit, and can only raise a decision. Saved thresholds are kept. If you use vector memory, load the seed patterns again.

= 1.0.0 =
Initial release.
